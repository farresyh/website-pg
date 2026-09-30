<?php

namespace App\Services\Notification;

use App\Jobs\SendCustomerWhatsAppJob;
use App\Models\AffiliateBranding;
use App\Models\AffiliateDomain;
use App\Models\CustomerNotification;
use App\Models\Order;
use App\Models\PlatformSettings;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;

/**
 * ADR-116: the one place a customer WhatsApp notification is decided,
 * worded, recorded and queued. Callers (VoucherController) name the event;
 * this class applies every rule:
 * - scope: storefront orders only, never reseller-wallet or `is_test`;
 * - the master switch and CS-session config (a `skipped` row, so the admin
 *   sees why nothing went out);
 * - phone normalisation;
 * - de-duplication (`dedupe_key`, unique);
 * - per-brand wording;
 * - pacing: each send gets a slot 10–30s after the previous one.
 *
 * Call it after the DB transaction that created the voucher has committed.
 */
final class CustomerNotificationService
{
    public const EVENT_VOUCHER_ISSUED = 'voucher_issued';

    public const EVENT_VOUCHER_RESTORED = 'voucher_restored';

    private const SLOT_KEY = 'whatsapp:next-send-slot';

    /** A new voucher: from a failed order (Path B) or standalone with a phone (Path A). */
    public function voucherIssued(Voucher $voucher): void
    {
        $order = $voucher->order_id !== null ? Order::query()->find($voucher->order_id) : null;

        if ($order !== null && ! $this->inScope($order)) {
            return;
        }

        $this->queue(
            event: self::EVENT_VOUCHER_ISSUED,
            dedupeKey: self::EVENT_VOUCHER_ISSUED.':voucher:'.$voucher->id,
            rawPhone: $voucher->customer_phone ?? $order?->customer_phone,
            message: $this->voucherIssuedMessage($voucher, $order),
            orderId: $order?->id,
            voucherId: $voucher->id,
        );
    }

    /** A full-voucher-cover order failed: the voucher it paid with got its balance back (ADR-024 restore-only). */
    public function voucherRestored(Order $order): void
    {
        $redemption = $this->restoredRedemption($order);

        if ($redemption === null || ! $this->inScope($order)) {
            return;
        }

        $voucher = $redemption->voucher;
        $brand = $this->brand($order->affiliate_id);

        $this->queue(
            event: self::EVENT_VOUCHER_RESTORED,
            dedupeKey: self::EVENT_VOUCHER_RESTORED.':order:'.$order->id,
            rawPhone: $order->customer_phone,
            message: "*{$brand['name']}*: Hi {$this->firstName($order->customer_name)}, your order {$order->order_number} couldn't be completed, "
                ."so RM{$this->rm($redemption->amount)} has been returned to your voucher *{$voucher->code}*.\n\n"
                ."You can use it again at checkout on {$brand['host']}.",
            orderId: $order->id,
            voucherId: $voucher->id,
        );
    }

    private function voucherIssuedMessage(Voucher $voucher, ?Order $order): string
    {
        $brand = $this->brand($voucher->affiliate_id);

        $opening = $order !== null
            ? "Hi {$this->firstName($order->customer_name)}, sorry, we couldn't complete your order {$order->order_number}. We've issued you store credit instead."
            : 'Hi there, you have received store credit.';

        $lines = [
            "*{$brand['name']}*: {$opening}",
            '',
            "Voucher: *{$voucher->code}*",
            "Value: RM{$this->rm($voucher->amount)}",
        ];

        if ($voucher->expires_at !== null) {
            $lines[] = 'Valid until: '.$voucher->expires_at->format('j M Y');
        }

        $lines[] = '';
        $lines[] = "Use it at checkout on {$brand['host']} with the same email or phone number"
            .($order !== null ? ' you ordered with.' : ' this voucher was issued to.');

        // ADR-024 decision 7: a partly-voucher-paid order that fails gets a new
        // voucher for the cash part AND its original voucher restored.
        $restored = $order !== null ? $this->restoredRedemption($order) : null;
        if ($restored !== null) {
            $lines[] = '';
            $lines[] = "The RM{$this->rm($restored->amount)} you paid with voucher {$restored->voucher->code} has also been returned to that voucher.";
        }

        return implode("\n", $lines);
    }

    private function queue(string $event, string $dedupeKey, ?string $rawPhone, string $message, ?int $orderId, ?int $voucherId): void
    {
        $phone = PhoneNumber::normalize($rawPhone);
        $skipReason = match (true) {
            ! PlatformSettings::current()->whatsapp_notifications_enabled => 'WhatsApp notifications are switched off',
            config('services.openwa.cs_session_id') === null => 'Customer-support WhatsApp session is not configured',
            $phone === null => 'No usable phone number',
            default => null,
        };

        try {
            $notification = CustomerNotification::query()->create([
                'event' => $event,
                'dedupe_key' => $dedupeKey,
                'order_id' => $orderId,
                'voucher_id' => $voucherId,
                'phone' => $phone,
                'message' => $message,
                'status' => $skipReason === null ? CustomerNotification::STATUS_QUEUED : CustomerNotification::STATUS_SKIPPED,
                'error' => $skipReason,
            ]);
        } catch (UniqueConstraintViolationException) {
            return; // already recorded for this event, never send twice
        }

        if ($skipReason === null) {
            SendCustomerWhatsAppJob::dispatch($notification->id)->delay($this->nextSendSlot());
        }
    }

    /**
     * ADR-116 decision 7: hands out send times 10–30s apart (config knobs),
     * so a burst of events never becomes a burst of messages. Reserved under
     * a lock, so two concurrent callers can't take the same slot.
     */
    private function nextSendSlot(): CarbonImmutable
    {
        return Cache::lock(self::SLOT_KEY.':lock', 5)->block(5, function () {
            $now = CarbonImmutable::now();
            $reserved = Cache::get(self::SLOT_KEY);
            $slot = $reserved !== null && $reserved > $now->getTimestamp()
                ? CarbonImmutable::createFromTimestamp($reserved)
                : $now;

            $gap = random_int(
                (int) config('services.openwa.notification_gap_min_seconds'),
                (int) config('services.openwa.notification_gap_max_seconds'),
            );
            Cache::put(self::SLOT_KEY, $slot->getTimestamp() + $gap, 86400);

            return $slot;
        });
    }

    private function inScope(Order $order): bool
    {
        return $order->wallet_reseller_id === null && ! $order->is_test;
    }

    private function restoredRedemption(Order $order): ?VoucherRedemption
    {
        return VoucherRedemption::query()
            ->with('voucher')
            ->where('order_id', $order->id)
            ->where('status', 'restored')
            ->first();
    }

    /** @return array{name: string, host: string} */
    private function brand(int $affiliateId): array
    {
        $name = AffiliateBranding::withoutAffiliateScope()->where('affiliate_id', $affiliateId)->value('store_name');

        $host = AffiliateDomain::withoutAffiliateScope()
            ->where('affiliate_id', $affiliateId)
            ->where('status', AffiliateDomainStatus::Active->value)
            ->orderByDesc('is_primary')
            ->value('hostname')
            ?? parse_url((string) config('services.storefront.url'), PHP_URL_HOST);

        return ['name' => $name ?: 'PekanGame', 'host' => (string) $host];
    }

    private function firstName(?string $name): string
    {
        $first = trim(explode(' ', trim((string) $name))[0]);

        return $first !== '' ? $first : 'there';
    }

    private function rm(int $sen): string
    {
        return number_format($sen / 100, 2);
    }
}
