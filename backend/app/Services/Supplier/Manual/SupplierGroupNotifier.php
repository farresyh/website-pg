<?php

namespace App\Services\Supplier\Manual;

use App\Models\CustomerNotification;
use App\Models\Order;
use App\Models\OrderCredential;
use App\Models\Supplier;
use App\Services\Notification\WhatsAppOutbox;
use App\Services\Order\DeliveryStatus;
use App\Services\WhatsApp\WhatsAppGroupService;

/**
 * ADR-125 decisions 13/15/48-54/59: what a manual supplier's WhatsApp group
 * is told, and when it is no longer worth telling.
 *
 * One post per attempt, keyed on (order, reference): a retry or resend from
 * Failed mints a new reference and posts again, labelled RETRY; a resend
 * from NeedsReview keeps the reference but was never posted (decision 48).
 * One SLA reminder per attempt. Rows go through the persisted queue on the
 * bot session; `prepare()` finishes them at send time.
 */
final class SupplierGroupNotifier
{
    public const EVENT_ORDER_POST = 'supplier_order_post';

    public const EVENT_SLA_REMINDER = 'supplier_sla_reminder';

    public function __construct(
        private readonly WhatsAppOutbox $outbox,
        private readonly WhatsAppGroupService $groups,
        private readonly SupplierLoginLinkService $links,
    ) {}

    /** Called after the transaction that put the order Pending has committed. */
    public function orderPosted(int $orderId): void
    {
        $order = Order::query()->with(['game:id,name', 'package:id,name', 'supplier'])->find($orderId);

        if ($order === null || ! $this->isWaitingOnManualSupplier($order)) {
            return;
        }

        // An earlier attempt's post, not this attempt's own skipped row.
        $isRetry = CustomerNotification::query()
            ->where('order_id', $order->id)
            ->where('event', self::EVENT_ORDER_POST)
            ->where('dedupe_key', '!=', self::dedupeKey(self::EVENT_ORDER_POST, $order))
            ->exists();

        $this->queue($order, self::EVENT_ORDER_POST, $this->postMessage($order, $isRetry));
    }

    /**
     * A group was just linked or reactivated: a post skipped for having no
     * group gets its one real chance now (WhatsAppOutbox revives a skipped
     * row); a post already sent or queued is left alone by its dedupe key.
     */
    public function repostWaiting(Supplier $supplier): void
    {
        Order::query()
            ->where('supplier_id', $supplier->id)
            ->where('delivery_status', DeliveryStatus::Pending->value)
            ->where('is_test', false)
            ->pluck('id')
            ->each(fn (int $orderId) => $this->orderPosted($orderId));
    }

    /** Decision 59: from the reconciler, once the order is past its SLA. */
    public function slaReminder(Order $order): void
    {
        $order->loadMissing(['game:id,name', 'package:id,name', 'supplier']);

        if (! $this->isWaitingOnManualSupplier($order)) {
            return;
        }

        $minutes = (int) $order->updated_at->diffInMinutes(now());

        $this->queue($order, self::EVENT_SLA_REMINDER, implode("\n", [
            "⏰ *PERINGATAN* {$order->order_number}",
            "{$order->game?->name} · {$order->package?->name}",
            "Belum dibalas selepas {$minutes} minit.",
            '',
            "Balas .done {$order->order_number} atau .gagal {$order->order_number} sebab",
        ]));
    }

    /**
     * The text to send now, or null when this row's attempt is no longer
     * waiting (delivered, failed, retried elsewhere). Mints the login link.
     */
    public function prepare(CustomerNotification $notification): ?string
    {
        $order = $notification->order()->with('supplier')->first();

        if ($order === null || ! $this->isWaitingOnManualSupplier($order)
            || $notification->dedupe_key !== self::dedupeKey($notification->event, $order)) {
            return null;
        }

        if (! str_contains($notification->message, SupplierLoginLinkService::PLACEHOLDER)) {
            return $notification->message;
        }

        return str_replace(
            SupplierLoginLinkService::PLACEHOLDER,
            $this->links->mint($order) ?? '(login sudah dipadam, sila hubungi admin)',
            $notification->message,
        );
    }

    private function queue(Order $order, string $event, string $message): void
    {
        $group = $this->groups->activeGroupFor($order->supplier);

        $this->outbox->enqueue(
            session: CustomerNotification::SESSION_BOT,
            event: $event,
            dedupeKey: self::dedupeKey($event, $order),
            message: $message,
            chatId: $group?->whatsapp_group_id,
            orderId: $order->id,
            skipReason: match (true) {
                config('services.openwa.session_id') === null || config('services.openwa.api_key') === null => 'Bot WhatsApp session is not configured',
                $group === null => 'This supplier has no active WhatsApp group',
                default => null,
            },
        );
    }

    private function postMessage(Order $order, bool $isRetry): string
    {
        $credential = OrderCredential::query()->where('order_id', $order->id)->first();

        $lines = [
            ($isRetry ? '🔁 *ORDER RETRY* ' : '🆕 *ORDER BARU* ').$order->order_number,
            "Game: {$order->game?->name}",
            "Pakej: {$order->package?->name}",
        ];

        if ($credential !== null) {
            foreach ($credential->fields ?? [] as $field) {
                $lines[] = "{$field['label']}: {$field['value']}";
            }
            $lines[] = '';
            $lines[] = 'Login penuh: '.SupplierLoginLinkService::PLACEHOLDER;
        } else {
            $lines[] = 'Player ID: '.$order->player_id.($order->server_id ? " ({$order->server_id})" : '');
        }

        $lines[] = '';
        $lines[] = 'Bila siap, balas:';
        $lines[] = ".done {$order->order_number}";
        $lines[] = "Kalau tak dapat: .gagal {$order->order_number} sebab";

        return implode("\n", $lines);
    }

    private function isWaitingOnManualSupplier(Order $order): bool
    {
        return $order->delivery_status === DeliveryStatus::Pending
            && ! $order->is_test
            && $order->supplier !== null
            && ! $order->supplier->isApiBacked();
    }

    private static function dedupeKey(string $event, Order $order): string
    {
        return "{$event}:order:{$order->id}:ref:{$order->reference_number}";
    }
}
