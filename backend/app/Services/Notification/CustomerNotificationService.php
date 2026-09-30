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
use App\Models\WhatsappContact;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;

/**
 * ADR-116: the one place a customer WhatsApp notification is decided,
 * worded, recorded and queued. Callers (VoucherController,
 * OrderFulfillmentService, CustomerWhatsAppInboundService) name the event;
 * this class applies every rule:
 * - scope: storefront orders only, never reseller-wallet or `is_test`;
 * - the master switch and CS-session config (a `skipped` row, so the admin
 *   sees why nothing went out);
 * - phone normalisation;
 * - de-duplication (`dedupe_key`, unique);
 * - per-brand wording;
 * - pacing: each send gets a slot 10–30s after the previous one.
 *
 * Call it after the DB transaction that created the voucher or delivered
 * the order has committed.
 */
final class CustomerNotificationService
{
    public const EVENT_VOUCHER_ISSUED = 'voucher_issued';

    public const EVENT_VOUCHER_RESTORED = 'voucher_restored';

    public const EVENT_DELIVERED_RECEIPT = 'delivered_receipt';

    public const EVENT_STATUS_CARD = 'status_card';

    public const EVENT_STOP_REPLY = 'stop_reply';

    public const EVENT_START_REPLY = 'start_reply';

    public const EVENT_ORDER_NOT_FOUND = 'order_not_found';

    private const NOT_FOUND_REPEAT_SECONDS = 600;

    private const SLOT_KEY = 'whatsapp:next-send-slot';

    private const STATUS_CARD_REPEAT_SECONDS = 1800;

    /**
     * ADR-116 decision 4: the order just became Delivered. A receipt goes out
     * only if the order's phone has opted in and not opted out. Otherwise
     * there is no row at all, since not sending is the normal case.
     */
    public function orderDelivered(int $orderId): void
    {
        $order = Order::query()->with(['game:id,name', 'package:id,name'])->find($orderId);
        $phone = PhoneNumber::normalize($order?->customer_phone);

        if ($order === null || ! $this->inScope($order) || $phone === null || ! WhatsappContact::receivesReceipts($phone)) {
            return;
        }

        $this->queueReceipt($order, $phone);
    }

    /**
     * ADR-116 2026-09-30 addendum: any message carrying a valid order number
     * gets that order's status card back, whichever button sent it or even if
     * it was typed by hand. The card holds only what the public track-order
     * page shows, so it goes to whoever sent the number. The same card isn't
     * repeated to the same number within 30 minutes unless the order's status
     * changed, so a support chat that keeps quoting the order number isn't
     * flooded.
     */
    public function orderStatusCard(Order $order, string $phone, string $inboundMessageId): void
    {
        if (! $this->inScope($order)) {
            return;
        }

        $throttleKey = "whatsapp:status-card:{$phone}:{$order->id}:{$order->payment_status->value}:{$order->delivery_status->value}";
        if (! Cache::add($throttleKey, true, self::STATUS_CARD_REPEAT_SECONDS)) {
            return;
        }

        $this->queue(
            event: self::EVENT_STATUS_CARD,
            dedupeKey: self::EVENT_STATUS_CARD.':message:'.$inboundMessageId,
            rawPhone: $phone,
            message: OrderStatusCard::render($order->loadMissing(['game:id,name', 'package:id,name']), $this->brand($order->affiliate_id)),
            orderId: $order->id,
            voucherId: null,
        );
    }

    /**
     * ADR-116 addendum: the message quoted an order number we don't have,
     * usually a typo. No brand (there's no order to take it from), and at most
     * one reply per number every 10 minutes so a run of wrong guesses doesn't
     * become a run of replies.
     */
    public function orderNotFound(string $orderNumber, string $phone, string $inboundMessageId): void
    {
        if (! Cache::add("whatsapp:order-not-found:{$phone}", true, self::NOT_FOUND_REPEAT_SECONDS)) {
            return;
        }

        $this->queue(
            event: self::EVENT_ORDER_NOT_FOUND,
            dedupeKey: self::EVENT_ORDER_NOT_FOUND.':message:'.$inboundMessageId,
            rawPhone: $phone,
            message: "We couldn't find order {$orderNumber}. Please check the order number for typos.\n\n"
                ."You can find it on your order page right after payment, or in the payment receipt sent to your email.\n\n"
                .'Still stuck? Just reply here and our team will help.',
            orderId: null,
            voucherId: null,
        );
    }

    /** ADR-116 addendum: confirms a START, which undoes an earlier STOP. */
    public function startReply(string $phone, string $inboundMessageId): void
    {
        $this->queue(
            event: self::EVENT_START_REPLY,
            dedupeKey: self::EVENT_START_REPLY.':message:'.$inboundMessageId,
            rawPhone: $phone,
            message: "You're back on. We'll send your order receipts here again. Reply STOP anytime to stop them.",
            orderId: null,
            voucherId: null,
        );
    }

    /** ADR-116 decision 5: confirms a STOP. Voucher messages still come, since that is the customer's money. */
    public function stopReply(string $phone, string $inboundMessageId): void
    {
        $this->queue(
            event: self::EVENT_STOP_REPLY,
            dedupeKey: self::EVENT_STOP_REPLY.':message:'.$inboundMessageId,
            rawPhone: $phone,
            message: "Done. You won't receive order receipts here anymore. If an order ever needs a refund voucher, we'll still send you its code. Reply START to turn receipts back on.",
            orderId: null,
            voucherId: null,
        );
    }

    private function queueReceipt(Order $order, string $phone): void
    {
        $this->queue(
            event: self::EVENT_DELIVERED_RECEIPT,
            dedupeKey: self::EVENT_DELIVERED_RECEIPT.':order:'.$order->id,
            rawPhone: $phone,
            message: OrderStatusCard::render($order, $this->brand($order->affiliate_id))."\n\nReply STOP to stop receipts.",
            orderId: $order->id,
            voucherId: null,
        );
    }

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
            // Already recorded for this event. Sent, queued or failed: never
            // again. Only a row skipped for a reason that no longer applies (the
            // switch was off, the phone was unusable) gets its one real chance,
            // via a conditional update so two callers can't both revive it.
            if ($skipReason !== null) {
                return;
            }

            $revived = CustomerNotification::query()
                ->where('dedupe_key', $dedupeKey)
                ->where('status', CustomerNotification::STATUS_SKIPPED)
                ->update(['status' => CustomerNotification::STATUS_QUEUED, 'phone' => $phone, 'message' => $message, 'error' => null]);

            if ($revived === 0) {
                return;
            }

            $notification = CustomerNotification::query()->where('dedupe_key', $dedupeKey)->firstOrFail();
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
