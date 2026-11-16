<?php

namespace App\Console\Commands\Order;

use App\Models\Order;
use App\Services\Backup\BackupFailureAlerter;
use App\Services\Order\DeliveryStatus;
use App\Support\MalaysiaTime;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * ADR-111 2026-11-15 addendum: once a day, emails every admin the orders
 * delivered on the previous Malaysia day at a platform loss
 * (`platform_profit < 0`), with this month's total. A sweep over the stored
 * result, like ADR-139's wallet auto-refund, so every path that writes
 * `platform_profit` is covered without hooking any of them. Settlement
 * leaves an order `partially_delivered`, so it is not reported.
 *
 * Known ceiling: a combo leg with no supplier price is costed at catalog
 * cost (PRD §16 item 122), so a loss on it can't be seen here.
 *
 * The two thresholds are PRD §16 item 169's trigger (ADR-137 2026-11-15
 * addendum): when one is crossed, the email says it is time to build
 * `max_price` for reseller-wallet orders.
 */
final class AlertOrderLossesCommand extends Command
{
    protected $signature = 'app:alert-order-losses';

    protected $description = 'Email admins the orders delivered yesterday (Malaysia) at a platform loss';

    public const MONTH_TRIGGER_SEN = 2000;

    public const ORDER_TRIGGER_SEN = 500;

    public function handle(BackupFailureAlerter $alerter): int
    {
        $dayStart = Carbon::now(MalaysiaTime::TIMEZONE)->subDay()->startOfDay();
        $dayEnd = $dayStart->copy()->addDay();
        $monthStart = $dayStart->copy()->startOfMonth();

        $losses = $this->losses($dayStart, $dayEnd)
            ->withExists(['resendAttempts as was_resent' => fn (Builder $q) => $q->where('attempt_type', 'resend')])
            ->orderBy('delivered_at')
            ->get(['id', 'order_number', 'pricing_basis', 'cost_price', 'real_cost_price_sen', 'platform_profit']);

        if ($losses->isEmpty()) {
            return self::SUCCESS;
        }

        $monthLossSen = -(int) $this->losses($monthStart, $dayEnd)->sum('platform_profit');
        $worstSen = -(int) $losses->min('platform_profit');

        $lines = $losses->map(fn (Order $o) => sprintf(
            '- %s (%s%s): expected cost %s, real cost %s, loss %s',
            $o->order_number,
            $o->pricing_basis?->value ?? 'unknown basis',
            $o->was_resent ? ', resend' : '',
            self::rm($o->cost_price),
            $o->real_cost_price_sen === null ? 'not reported' : self::rm($o->real_cost_price_sen),
            self::rm(-$o->platform_profit),
        ))->implode("\n");

        $summary = sprintf('Loss this month (%s): %s. ', $monthStart->format('F Y'), self::rm($monthLossSen));
        $summary .= $monthLossSen > self::MONTH_TRIGGER_SEN || $worstSen > self::ORDER_TRIGGER_SEN
            ? 'PRD §16 item 169 trigger crossed (over '.self::rm(self::MONTH_TRIGGER_SEN).' a month or '.self::rm(self::ORDER_TRIGGER_SEN).' on one order): time to build max_price for reseller-wallet orders.'
            : 'Item 169 trigger: over '.self::rm(self::MONTH_TRIGGER_SEN).' a month or '.self::rm(self::ORDER_TRIGGER_SEN).' on one order.';

        $alerter->alert(
            sprintf('%d order(s) delivered at a loss on %s', $losses->count(), $dayStart->format('j M Y')),
            "{$lines}\n\n{$summary}",
            'Order loss',
        );

        return self::SUCCESS;
    }

    /** Orders delivered in [$from, $to) Malaysia time at a platform loss. */
    private function losses(Carbon $from, Carbon $to): Builder
    {
        return Order::query()
            ->where('is_test', false)
            ->where('delivery_status', DeliveryStatus::Delivered->value)
            ->where('platform_profit', '<', 0)
            ->where('delivered_at', '>=', $from->copy()->utc())
            ->where('delivered_at', '<', $to->copy()->utc());
    }

    private static function rm(int $sen): string
    {
        return 'RM'.number_format($sen / 100, 2);
    }
}
