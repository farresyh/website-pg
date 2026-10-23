<?php

namespace App\Services\Joki;

use App\Models\JokiOrder;
use App\Models\JokiProgressUpdate;
use App\Models\Order;
use App\Services\Notification\CustomerNotificationService;
use App\Services\Order\DeliveryStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-126 decisions 10 and 21: the one place a joki order's progress is
 * recorded, from the penjoki's `.progress` or the admin's Order Detail.
 * Under the order lock: only a Pending joki order a penjoki holds (never
 * the pool), and for the bot only the penjoki it is assigned to. The rank
 * is checked against the panel's model. It writes the history row, the
 * order's current rank and the stale clock (`manual_activity_at`, so the
 * order broadcasts to Track Order), then, after commit, the customer's
 * paced WhatsApp update.
 */
final class JokiProgressService
{
    public function __construct(private readonly CustomerNotificationService $notifications) {}

    /**
     * @param  array<string, mixed>  $rank  `{tier_id, division, stars}`
     */
    public function record(Order $order, array $rank, string $actor, string $channel, ?int $expectedSupplierId = null): JokiOrder
    {
        $joki = DB::transaction(function () use ($order, $rank, $actor, $channel, $expectedSupplierId) {
            $locked = Order::query()->with('supplier')->lockForUpdate()->findOrFail($order->id);

            if (! $locked->isJoki() || $locked->is_test || $locked->delivery_status !== DeliveryStatus::Pending
                || $locked->supplier === null || ! $locked->supplier->isPenjoki()
                || ($expectedSupplierId !== null && $locked->supplier_id !== $expectedSupplierId)) {
                throw ValidationException::withMessages(['order' => ['Progress is recorded only for a joki order a penjoki is playing.']]);
            }

            $position = JokiLadder::current()->position($rank, 'rank');
            if ($position->kind === JokiPosition::GRADING) {
                throw ValidationException::withMessages(['rank' => ['Give the rank with its stars.']]);
            }

            JokiProgressUpdate::query()->create([
                'order_id' => $locked->id,
                'rank' => $position->toArray(),
                'actor' => $actor,
                'channel' => $channel,
            ]);

            $joki = JokiOrder::query()->where('order_id', $locked->id)->firstOrFail();
            $joki->update(['current_rank' => $position->toArray(), 'current_rank_at' => now()]);
            $locked->update(['manual_activity_at' => now()]);

            return $joki;
        });

        $this->notifications->jokiProgress($order->id);

        return $joki;
    }
}
