<?php

namespace App\Services\Notification;

use App\Models\CustomerNotification;
use App\Models\JokiSetting;
use App\Models\Order;
use App\Models\PlatformSettings;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Models\WhatsappContact;
use App\Services\Order\DeliveryStatus;
use App\Support\BrandIdentity;
use App\Support\MalaysiaTime;
use App\Support\PhoneNumber;
use DateTimeInterface;

/**
 * ADR-116: the one place a customer WhatsApp notification is decided,
 * worded, recorded and queued. Callers (VoucherController,
 * OrderFulfillmentService, CustomerWhatsAppInboundService) name the event;
 * this class applies every rule:
 * - scope: storefront orders only, never reseller-wallet or `is_test`;
 * - the master switch and CS-session config (a `skipped` row, so the admin
 *   sees why nothing went out);
 * - phone normalisation;
 * - per-brand wording.
 * De-duplication and pacing are WhatsAppOutbox's.
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

    public const EVENT_JOKI_PROGRESS = 'joki_progress';

    public function __construct(private readonly WhatsAppOutbox $outbox) {}

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
     * page shows, so it goes to whoever sent the number.
     *
     * Every message is answered, even when the card is unchanged (ADR-116
     * 2026-10-12 addendum). A retried webhook is already safe: the dedupe key
     * is the inbound message id.
     *
     * ponytail: no per-number cap. Ceiling: a scripted flood would queue
     * behind the one paced lane and delay voucher messages (Horizon's LongWait
     * does not see delayed jobs). Add a DB count or a next-send-slot guard if
     * that is ever observed.
     */
    public function orderStatusCard(Order $order, string $phone, string $inboundMessageId): void
    {
        if (! $this->inScope($order)) {
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
     * usually a typo. No brand (there's no order to take it from). Answered
     * every time, so a customer who corrects the typo always gets a reply.
     */
    public function orderNotFound(string $orderNumber, string $phone, string $inboundMessageId): void
    {
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

    /**
     * ADR-126 decision 10 and S23: a joki order's rank moved. Only for a
     * customer who opted in (as receipts). The first update goes at once;
     * after that at most one message per `progress_message_interval_minutes`
     * (panel, default 120). While one is waiting to go, a newer update adds
     * nothing: prepare() words it at send time with the latest rank.
     */
    public function jokiProgress(int $orderId): void
    {
        $order = Order::query()->find($orderId);
        $phone = PhoneNumber::normalize($order?->customer_phone);

        if ($order === null || ! $order->isJoki() || ! $this->inScope($order) || $phone === null || ! WhatsappContact::receivesReceipts($phone)) {
            return;
        }

        $earlier = CustomerNotification::query()
            ->where('order_id', $order->id)
            ->where('event', self::EVENT_JOKI_PROGRESS)
            ->orderByDesc('id')
            ->get(['id', 'status', 'sent_at', 'created_at']);

        if ($earlier->contains('status', CustomerNotification::STATUS_QUEUED)) {
            return;
        }

        $last = $earlier->first();
        $interval = JokiSetting::current()->progress_message_interval_minutes;

        $this->queue(
            event: self::EVENT_JOKI_PROGRESS,
            dedupeKey: self::EVENT_JOKI_PROGRESS.':order:'.$order->id.':n'.($earlier->count() + 1),
            rawPhone: $phone,
            message: 'Joki progress update (worded when sent).',
            orderId: $order->id,
            voucherId: null,
            notBefore: $last === null ? null : ($last->sent_at ?? $last->created_at)->copy()->addMinutes($interval),
        );
    }

    /**
     * The text a queued customer message goes out with. A joki progress
     * update is worded now, with the latest rank, or dropped (null) when
     * the order is no longer being played or the customer stopped updates.
     */
    public function prepare(CustomerNotification $notification): ?string
    {
        if ($notification->event !== self::EVENT_JOKI_PROGRESS) {
            return $notification->message;
        }

        $order = Order::query()->with('jokiOrder')->find($notification->order_id);
        $current = $order?->jokiOrder?->current_rank;

        if ($order === null || $order->delivery_status !== DeliveryStatus::Pending || $current === null
            || $notification->phone === null || ! WhatsappContact::receivesReceipts($notification->phone)) {
            return null;
        }

        $brand = $this->brand($order->affiliate_id);

        return implode("\n", [
            "*{$brand['name']}* · Order {$order->order_number}",
            '',
            "🎮 Joki progress: {$current['label']}",
            "🎯 Target: {$order->jokiOrder->to_rank['label']}",
            '',
            "Track it: https://{$brand['host']}/order/status/{$order->order_number}",
            'Reply STOP to stop these updates.',
        ]);
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
            message: "*{$brand['name']}*: Hi {$this->firstName($order->customer_name)}, "
                .($order->delivery_status === DeliveryStatus::PartiallyDelivered ? 'part of your order' : 'your order')
                ." {$order->order_number} couldn't be completed, "
                ."so RM{$this->rm($redemption->restored_amount)} has been returned to your voucher *{$voucher->code}*.\n\n"
                ."You can use it again at checkout on {$brand['host']}.",
            orderId: $order->id,
            voucherId: $voucher->id,
        );
    }

    private function voucherIssuedMessage(Voucher $voucher, ?Order $order): string
    {
        $brand = $this->brand($voucher->affiliate_id);

        $opening = match (true) {
            $order === null => 'Hi there, you have received store credit.',
            // ADR-094 decision 39 — never says why (combo stays opaque).
            $order->delivery_status === DeliveryStatus::PartiallyDelivered => "Hi {$this->firstName($order->customer_name)}, sorry, part of your order {$order->order_number} couldn't be completed. We've issued you store credit for that part.",
            default => "Hi {$this->firstName($order->customer_name)}, sorry, we couldn't complete your order {$order->order_number}. We've issued you store credit instead.",
        };

        $lines = [
            "*{$brand['name']}*: {$opening}",
            '',
            "Voucher: *{$voucher->code}*",
            "Value: RM{$this->rm($voucher->amount)}",
        ];

        if ($voucher->expires_at !== null) {
            $lines[] = 'Valid until: '.$voucher->expires_at->timezone(MalaysiaTime::TIMEZONE)->format('j M Y');
        }

        $lines[] = '';
        $lines[] = "Use it at checkout on {$brand['host']} with the same email or phone number"
            .($order !== null ? ' you ordered with.' : ' this voucher was issued to.');

        // ADR-024 decision 7: a partly-voucher-paid order that fails gets a new
        // voucher for the cash part AND its original voucher restored.
        $restored = $order !== null ? $this->restoredRedemption($order) : null;
        if ($restored !== null) {
            $lines[] = '';
            $lines[] = "The RM{$this->rm($restored->restored_amount)} you paid with voucher {$restored->voucher->code} has also been returned to that voucher.";
        }

        return implode("\n", $lines);
    }

    private function queue(string $event, string $dedupeKey, ?string $rawPhone, string $message, ?int $orderId, ?int $voucherId, ?DateTimeInterface $notBefore = null): void
    {
        $phone = PhoneNumber::normalize($rawPhone);

        $this->outbox->enqueue(
            session: CustomerNotification::SESSION_CUSTOMER_SUPPORT,
            event: $event,
            dedupeKey: $dedupeKey,
            message: $message,
            phone: $phone,
            orderId: $orderId,
            voucherId: $voucherId,
            notBefore: $notBefore,
            skipReason: match (true) {
                ! PlatformSettings::current()->whatsapp_notifications_enabled => 'WhatsApp notifications are switched off',
                config('services.openwa.cs_session_id') === null => 'Customer-support WhatsApp session is not configured',
                $phone === null => 'No usable phone number',
                default => null,
            },
        );
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
        $brand = BrandIdentity::of($affiliateId);

        return ['name' => $brand->name, 'host' => $brand->host()];
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
