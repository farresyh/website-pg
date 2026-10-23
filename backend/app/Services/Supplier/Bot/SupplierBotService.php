<?php

namespace App\Services\Supplier\Bot;

use App\Models\Order;
use App\Models\Supplier;
use App\Services\Fulfillment\ManualDeliveryService;
use App\Services\OpenWa\OpenWaClient;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\InvalidOrderTransitionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * ADR-125 decisions 11/14/58: a manual supplier's WhatsApp group commands.
 * `OpenWaWebhookController` routes a supplier-owned group here, never to
 * the reseller bot, so the two command sets can't collide.
 *
 * `.done` and `.gagal` are callers of ManualDeliveryService, the same seam
 * as the admin's Manual Queue: the cost is always the agreed one (a
 * WhatsApp reply never sets money, decision 12) and the sender is recorded
 * as `confirmed_by`. Only that supplier's own Pending orders are touched.
 */
final class SupplierBotService
{
    public const CHANNEL = 'supplier_bot';

    private const PENDING_LIST_LIMIT = 30;

    public function __construct(
        private readonly ManualDeliveryService $manual,
        private readonly OpenWaClient $openWa,
    ) {}

    public function handle(Supplier $supplier, string $whatsappGroupId, string $rawText, ?string $sender): void
    {
        $text = trim($rawText);

        // Ordinary chat in the group stays silent; only `.`-commands answer.
        if (! str_starts_with($text, '.')) {
            return;
        }

        if (! RateLimiter::attempt("supplier-bot:{$supplier->id}", 60, fn () => true, 60)) {
            $this->openWa->sendText($whatsappGroupId, 'Terlalu banyak permintaan. Sila cuba sebentar lagi.');

            return;
        }

        $parts = preg_split('/\s+/', $text, 3, PREG_SPLIT_NO_EMPTY) ?: [];
        $orderNumber = isset($parts[1]) ? strtoupper($parts[1]) : null;
        $confirmedBy = 'wa:'.($sender ?? 'unknown');

        $reply = match (strtolower($parts[0])) {
            '.done' => $orderNumber === null ? self::help() : $this->done($supplier, $orderNumber, $confirmedBy),
            '.gagal' => $orderNumber === null ? self::help() : $this->failed($supplier, $orderNumber, trim($parts[2] ?? ''), $confirmedBy),
            '.pending' => $this->pendingList($supplier),
            default => self::help(),
        };

        $this->openWa->sendText($whatsappGroupId, $reply);
    }

    private function done(Supplier $supplier, string $orderNumber, string $confirmedBy): string
    {
        return $this->confirm($supplier, $orderNumber, function (Order $order) use ($supplier, $confirmedBy) {
            $this->manual->confirmDelivered($order, null, null, $confirmedBy, $supplier->id, self::CHANNEL);

            return "✅ {$order->order_number} ditanda SELESAI. Terima kasih.";
        });
    }

    private function failed(Supplier $supplier, string $orderNumber, string $reason, string $confirmedBy): string
    {
        if (mb_strlen($reason) < 3) {
            return "Sila beri sebab. Contoh: .gagal {$orderNumber} password salah";
        }

        return $this->confirm($supplier, $orderNumber, function (Order $order) use ($supplier, $reason, $confirmedBy) {
            $this->manual->confirmFailed($order, mb_substr($reason, 0, 255), $confirmedBy, $supplier->id, self::CHANNEL);

            return "❌ {$order->order_number} ditanda GAGAL. Admin akan uruskan.";
        });
    }

    /** @param  callable(Order): string  $action */
    private function confirm(Supplier $supplier, string $orderNumber, callable $action): string
    {
        $order = Order::query()
            ->where('order_number', $orderNumber)
            ->where('supplier_id', $supplier->id)
            ->where('is_test', false)
            ->first();

        if ($order === null) {
            return "Order {$orderNumber} tiada dalam senarai anda.";
        }

        // Decisions 48/58: only a posted, still-waiting attempt. A wrong
        // Failed is corrected by an admin (decision 42), never from here.
        $notWaiting = match ($order->delivery_status) {
            DeliveryStatus::Pending => null,
            DeliveryStatus::Delivered => "{$orderNumber} sudah selesai.",
            DeliveryStatus::Failed => "{$orderNumber} sudah ditanda gagal. Kalau top-up sebenarnya berjaya, sila hubungi admin.",
            default => "{$orderNumber} tidak menunggu balasan anda. Sila hubungi admin.",
        };

        if ($notWaiting !== null) {
            return $notWaiting;
        }

        try {
            return $action($order);
        } catch (InvalidOrderTransitionException) {
            return "{$orderNumber} sudah selesai.";
        } catch (ValidationException $e) {
            Log::warning('Supplier bot confirmation refused', ['order_number' => $orderNumber, 'errors' => $e->errors()]);

            return "{$orderNumber} tidak dapat dikemas kini. Sila hubungi admin.";
        }
    }

    private function pendingList(Supplier $supplier): string
    {
        $orders = Order::query()
            ->with(['game:id,name', 'package:id,name'])
            ->where('supplier_id', $supplier->id)
            ->where('delivery_status', DeliveryStatus::Pending->value)
            ->where('is_test', false)
            ->orderByRaw('COALESCE(manual_activity_at, updated_at)')
            ->limit(self::PENDING_LIST_LIMIT)
            ->get();

        if ($orders->isEmpty()) {
            return 'Tiada order menunggu. 👍';
        }

        return "*Order menunggu ({$orders->count()})*\n".$orders
            ->map(fn (Order $o) => "• {$o->order_number} · {$o->game?->name} · {$o->package?->name} · ".(int) $o->pendingClock()->diffInMinutes(now()).' min')
            ->implode("\n");
    }

    private static function help(): string
    {
        return "Arahan:\n.done PG-XXXX — top-up selesai\n.gagal PG-XXXX sebab — tak dapat buat\n.pending — senarai order menunggu";
    }
}
