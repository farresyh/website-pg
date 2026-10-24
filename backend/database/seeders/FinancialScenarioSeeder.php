<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\Voucher;
use App\Services\Order\DeliveryStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\FinancialScenario\FinancialScenario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * ADR-127 decision 12 — LOCAL DEV ONLY. Fills the dev DB with about four
 * months of money data (1 July to yesterday, ~1,500 orders, every channel the
 * scenario drives) for eyeballing the money pages. Everything goes through
 * the real system via FinancialScenario, which refuses production.
 * Additive: it never deletes, and the scenario's own catalog, brands,
 * supplier and penjoki are its own rows. Deterministic (fixed seed).
 *
 *   php artisan db:seed --class=Database\\Seeders\\FinancialScenarioSeeder
 *
 * Stop `composer run dev` / `./scripts/dev.sh` first. Horizon and the
 * scheduler on the same sqlite file lock it ("database is locked"), and the
 * real scheduler would poll the scenario's Pending orders, whose supplier
 * exists only in this process.
 *
 * Not driven yet: membership, CHIP wallet top-ups, affiliate withdrawals
 * and resends (the scenario has no action for them).
 */
class FinancialScenarioSeeder extends Seeder
{
    private const FROM = '2026-07-01';

    private const ORDERS_PER_DAY = 13;

    public function run(): void
    {
        mt_srand(127);
        // Read before boot(), which moves the clock back to the start.
        $until = CarbonImmutable::now('Asia/Kuala_Lumpur')->subHours(2);
        $s = FinancialScenario::boot(self::FROM.' 08:00');
        $penjoki = $s->enableJoki(penjokiCount: 3);
        $s->supplierTransfer($s->apiSupplier, 1_500_000);

        /** @var list<Order> $pendingTomorrow */
        $pendingTomorrow = [];
        /** @var list<Order> $failedYesterday */
        $failedYesterday = [];
        /** @var list<array{Order, int}> $jokiOpen  order, day to finish */
        $jokiOpen = [];
        /** @var list<array{int, string, bool}> $vouchers  customer, code, on the affiliate's brand */
        $vouchers = [];

        for ($day = CarbonImmutable::parse(self::FROM, 'Asia/Kuala_Lumpur'); $day->lt($until->startOfDay()); $day = $day->addDay()) {
            // The clock only moves forward: back office from 07:00 a minute
            // at a time, orders in time order from 08:00, the day's NeedsReview
            // confirms and the weekly office after the last order.
            $minute = 7 * 60;
            $tick = function () use ($s, $day, &$minute) {
                $s->at($day->addMinutes($minute++)->format('Y-m-d H:i'));
            };

            // Morning back office: yesterday's leftovers, and the joki finished overnight.
            foreach ($pendingTomorrow as $order) {
                $tick();
                $s->supplierDelivers($order);
            }
            foreach ($failedYesterday as $order) {
                $tick();
                $compensated = $s->compensate($order);
                $code = Voucher::query()->where('order_id', $compensated->id)->value('code');
                if ($code !== null && ! $compensated->isJoki() && mt_rand(1, 100) <= 60) {
                    $vouchers[] = [$this->customerOf($compensated), $code, $compensated->affiliate_id === $s->affiliate->id];
                }
            }
            [$pendingTomorrow, $failedYesterday] = [[], []];

            foreach ($jokiOpen as $i => [$order, $finishDay]) {
                if ($finishDay > $day->dayOfYear) {
                    continue;
                }
                $tick();
                mt_rand(1, 100) <= 85 ? $s->penjokiDelivers($order) : $failedYesterday[] = $s->penjokiFails($order);
                unset($jokiOpen[$i]);
            }

            // Close last month on the 5th, as the founder does (closes start from September 2026).
            if ($day->day === 5 && $day->subMonthNoOverflow()->format('Y-m') >= '2026-09') {
                $tick();
                $last = $day->subMonthNoOverflow();
                $s->closeMonth($last->year, $last->month);
            }

            $count = self::ORDERS_PER_DAY + mt_rand(-4, 4) + ($day->isWeekend() ? 3 : 0);
            $times = array_map(fn () => mt_rand(8 * 60, 23 * 60 + 30), range(1, $count));
            sort($times);
            $confirms = [];
            foreach ($times as $orderMinute) {
                $s->at($day->addMinutes($orderMinute)->format('Y-m-d H:i'));
                $roll = mt_rand(1, 100);
                $package = $s->packages[mt_rand(0, count($s->packages) - 1)];

                if ($roll <= 15) {
                    $s->resellerApiOrder($package);

                    continue;
                }

                if ($roll <= 20) {
                    $order = $s->pay($s->jokiOrder(['Epic', mt_rand(3, 5), 0], ['Legend', mt_rand(3, 5), 0], express: mt_rand(1, 100) <= 25));
                    $s->assignJoki($order, $penjoki[mt_rand(0, count($penjoki) - 1)]);
                    if (mt_rand(1, 100) <= 15) {
                        $s->assignJoki($order, $this->otherPenjoki($penjoki, $order));
                    }
                    $jokiOpen[] = [$order, $day->dayOfYear + mt_rand(1, 4)];

                    continue;
                }

                $outcome = mt_rand(1, 100);
                match (true) {
                    $outcome <= 4 => $s->supplierAdapter->failNext(),
                    $outcome <= 8 => $s->supplierAdapter->pendingNext(),
                    $outcome <= 10 => $s->supplierAdapter->ambiguousNext(),
                    $outcome <= 16 => $s->supplierAdapter->costNext($package->cost_price + mt_rand(-30, 40)),
                    default => null,
                };

                $voucher = $roll <= 23 && $vouchers !== [] ? array_shift($vouchers) : null;
                $order = $s->pay($s->storefrontOrder(
                    $package,
                    // A voucher is spent on the brand that issued it.
                    onAffiliateBrand: $voucher[2] ?? $roll <= 40,
                    customer: $voucher[0] ?? mt_rand(1, 400),
                    voucherCode: $voucher[1] ?? null,
                ));

                match ($order->delivery_status) {
                    DeliveryStatus::Failed => $failedYesterday[] = $order,
                    DeliveryStatus::Pending => $pendingTomorrow[] = $order,
                    DeliveryStatus::NeedsReview => $confirms[] = [$order, $package->cost_price + mt_rand(0, 50)],
                    default => null,
                };
            }

            $minute = 23 * 60 + 40;
            foreach ($confirms as [$order, $chargedSen]) {
                $tick();
                $s->adminConfirmsDelivered($order, $chargedSen);
            }

            // Weekly back office: fund the API supplier, pay each penjoki what is owed, top up the reseller.
            if ($day->isSunday()) {
                $tick();
                $s->supplierTransfer($s->apiSupplier, 400_000);
                foreach ($penjoki as $p) {
                    $owed = (int) round(-(float) $p->fresh()->supplierLedgerBalance() * 100);
                    if ($owed > 0) {
                        $s->supplierTransfer($p, $owed);
                    }
                }
                $s->topUpWallet(150_000);
            }
        }

        // The scenario supplier exists only in this process, so the real
        // scheduler must find no API order of it still Pending: finish them.
        $s->at($until->format('Y-m-d H:i'));
        foreach ($pendingTomorrow as $order) {
            $s->supplierDelivers($order);
        }

        Carbon::setTestNow();
    }

    private function customerOf(Order $order): int
    {
        return (int) substr($order->player_id, -6);
    }

    /** @param  list<\App\Models\Supplier>  $penjoki */
    private function otherPenjoki(array $penjoki, Order $order): \App\Models\Supplier
    {
        $others = array_values(array_filter($penjoki, fn ($p) => $p->id !== $order->fresh()->supplier_id));

        return $others[mt_rand(0, count($others) - 1)];
    }
}
