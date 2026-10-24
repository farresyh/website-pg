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
 * It also drives the money around the orders: share capital, both
 * suppliers' funding (one in IDR at a daily rate), CHIP's daily payout
 * through a settlement file, membership, wallet top-ups by FPX, affiliate
 * withdrawals and resends. Months from September are closed with the
 * modelled bank, so each close's gap is only CHIP's fee estimate.
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
        $s->fundCompany(5_000_000);
        $s->supplierTransfer($s->apiSupplier, 1_500_000);
        $s->fundIdrSupplier(500_000, 3_750.0);
        $s->enableMembership();
        $idrRate = 0.000270;
        /** @var array<int, CarbonImmutable> $members  customer => membership expiry */
        $members = [];

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

            // Morning back office: CHIP's payout, today's rupiah rate,
            // yesterday's leftovers, and the joki finished overnight.
            $tick();
            $s->settleChip();
            $idrRate = max(0.000262, min(0.000288, $idrRate * (1 + mt_rand(-50, 50) / 10_000)));
            $s->setIdrRate($idrRate);

            foreach ($pendingTomorrow as $order) {
                $tick();
                $s->supplierDelivers($order);
            }
            foreach ($failedYesterday as $order) {
                $tick();
                // Some failures are resent and delivered instead (ADR-105).
                if (! $order->isJoki() && mt_rand(1, 100) <= 40) {
                    $s->resend($order);

                    continue;
                }
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

            // A new member most days; the affiliate withdraws its earnings on the 10th.
            if (mt_rand(1, 100) <= 45) {
                $tick();
                $customer = mt_rand(1, 400);
                if (! isset($members[$customer]) || $members[$customer]->lt($day)) {
                    $members[$customer] = CarbonImmutable::parse($s->subscribeMember($customer, mt_rand(1, 100) <= 70 ? 'Tier 1' : 'Tier 2')->expires_at);
                }
            }
            if ($day->day === 10) {
                $tick();
                $earnings = app(\App\Services\Affiliate\AffiliateEarningsService::class)->balance($s->affiliate);
                if ($earnings >= 1_000) {
                    $s->affiliateWithdraws($earnings);
                }
            }

            $count = self::ORDERS_PER_DAY + mt_rand(-4, 4) + ($day->isWeekend() ? 3 : 0);
            $times = array_map(fn () => mt_rand(8 * 60, 23 * 60 + 30), range(1, $count));
            sort($times);
            $confirms = [];
            foreach ($times as $orderMinute) {
                $s->at($day->addMinutes($orderMinute)->format('Y-m-d H:i'));
                $roll = mt_rand(1, 100);
                // A quarter of the top-ups are Free Fire, on the IDR supplier.
                $idr = mt_rand(1, 100) <= 25;
                $catalog = $idr ? $s->idrPackages : $s->packages;
                $adapter = $idr ? $s->idrAdapter : $s->supplierAdapter;
                $package = $catalog[mt_rand(0, count($catalog) - 1)];

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
                    $outcome <= 4 => $adapter->failNext(),
                    $outcome <= 8 => $adapter->pendingNext(),
                    $outcome <= 10 => $adapter->ambiguousNext(),
                    $outcome <= 16 => $adapter->costNext($package->cost_price + mt_rand(-30, 40)),
                    default => null,
                };

                $voucher = $roll <= 23 && $vouchers !== [] ? array_shift($vouchers) : null;
                $customer = $voucher[0] ?? mt_rand(1, 400);
                // A voucher is spent on the brand that issued it; a member buys on PekanGame, signed in.
                $onAffiliate = $voucher[2] ?? $roll <= 40;
                $order = $s->pay($s->storefrontOrder(
                    $package,
                    onAffiliateBrand: $onAffiliate,
                    customer: $customer,
                    voucherCode: $voucher[1] ?? null,
                    asMember: ! $onAffiliate && isset($members[$customer]) && $members[$customer]->gt($day),
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

            // Weekly back office: fund both suppliers, pay each penjoki what is
            // owed, and the reseller tops up (bank transfer or FPX, by turns).
            if ($day->isSunday()) {
                $tick();
                $s->supplierTransfer($s->apiSupplier, 400_000);
                $s->fundIdrSupplier(150_000, mt_rand(3_650, 3_800) * 1.0);
                foreach ($penjoki as $p) {
                    $owed = (int) round(-(float) $p->fresh()->supplierLedgerBalance() * 100);
                    if ($owed > 0) {
                        $s->supplierTransfer($p, $owed);
                    }
                }
                $day->weekOfYear % 2 === 0 ? $s->topUpWallet(150_000) : $s->walletTopupViaChip(150_000);
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
