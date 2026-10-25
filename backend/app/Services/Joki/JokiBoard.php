<?php

namespace App\Services\Joki;

use App\Models\Order;
use App\Models\Supplier;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * ADR-126 decisions 23-24 and F4: the one place the Joki page's filters and
 * the Dashboard card are defined, so the two can't disagree. Paid, real
 * joki orders only.
 *
 *  - waiting:       Pending on the pool (no penjoki yet)
 *  - in_progress:   Pending with a penjoki
 *  - stale:         Pending past its supplier's SLA (Order::isStalePending),
 *                   or NeedsReview: either way someone should look
 *  - needs_voucher: Failed and not compensated (Order::scopeNeedsAction),
 *                   which the general Need Action leaves to this board
 *  - done / failed: Delivered / every Failed
 */
final class JokiBoard
{
    public const FILTERS = ['waiting', 'in_progress', 'stale', 'needs_voucher', 'done', 'failed'];

    public function orders(?string $filter): Builder
    {
        $query = $this->base();

        return match ($filter) {
            'waiting' => $this->pendingOn($query, Supplier::JOKI_POOL),
            'in_progress' => $this->pendingOn($query, Supplier::JOKI_PENJOKI),
            'stale' => $query->whereIn('id', $this->staleIds()),
            'needs_voucher' => $query->needsAction(),
            'done' => $query->where('delivery_status', DeliveryStatus::Delivered->value),
            'failed' => $query->where('delivery_status', DeliveryStatus::Failed->value),
            default => $query,
        };
    }

    /** @return array{waiting: int, in_progress: int, stale: int, needs_voucher: int} */
    public function counts(): array
    {
        return [
            'waiting' => $this->orders('waiting')->count(),
            'in_progress' => $this->orders('in_progress')->count(),
            'stale' => count($this->staleIds()),
            'needs_voucher' => $this->orders('needs_voucher')->count(),
        ];
    }

    private function base(): Builder
    {
        return Order::query()->joki()->where('is_test', false)->where('payment_status', PaymentStatus::Paid->value);
    }

    private function pendingOn(Builder $query, string $role): Builder
    {
        return $query->where('delivery_status', DeliveryStatus::Pending->value)
            ->whereHas('supplier', fn (Builder $q) => $q->where('joki_role', $role));
    }

    /**
     * Judged in PHP, like the Dashboard's stale count: open joki orders are few.
     *
     * @return list<int>
     */
    private function staleIds(): array
    {
        return $this->base()
            ->whereIn('delivery_status', [DeliveryStatus::Pending->value, DeliveryStatus::NeedsReview->value])
            ->with(['supplier', 'package'])
            ->get()
            ->filter(fn (Order $order) => $order->delivery_status === DeliveryStatus::NeedsReview || $order->isStalePending())
            ->pluck('id')
            ->all();
    }
}
