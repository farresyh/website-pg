<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Services\Ledger\LedgerOwnerType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-083 2026-10-18 addendum: a manual `order_profit` correction (a
 * ledger row with a `reason`, e.g. order PG-KWKBUHNDMKQT's −10 sen
 * ADR-105 fix) is its own register row dated when it was made. The
 * order row keeps the profit as first credited. Before this, the order
 * row showed only whichever entry `pluck()` saw last (−10 instead of
 * +37), and the correction had no row of its own.
 */
class TransactionRegisterOrderProfitCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function orderWithCorrection(): Order
    {
        $order = Order::factory()->delivered()->create([
            'order_number' => 'PG-CORRECTED', 'paid_at' => '2026-09-17 10:00:00', 'platform_profit' => 27,
        ]);
        $this->profitEntry($order, 37, null, '2026-09-17 10:05:00');
        $this->profitEntry($order, -10, 'ADR-105 correction: overcredited', '2026-11-03 09:00:00');

        return $order;
    }

    private function profitEntry(Order $order, int $amount, ?string $reason, string $at, LedgerOwnerType $owner = LedgerOwnerType::Platform): void
    {
        $this->travelTo(CarbonImmutable::parse($at));
        LedgerEntry::query()->create([
            'owner_type' => $owner->value, 'owner_id' => $owner === LedgerOwnerType::Platform ? null : $order->affiliate_id,
            'type' => 'order_profit', 'amount' => $amount, 'reason' => $reason, 'created_by' => $reason ? 1 : null,
            'reference_type' => 'order', 'reference_id' => $order->id,
        ]);
        $this->travelBack();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(string $query = ''): Collection
    {
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());

        return collect($this->getJson('/api/accounting/transactions?per_page=100'.$query)->assertOk()->json('data'));
    }

    public function test_the_order_row_keeps_the_original_credit_and_the_correction_is_its_own_row(): void
    {
        $this->orderWithCorrection();

        $rows = $this->rows();

        $order = $rows->firstWhere('type', 'order');
        $this->assertSame(37, $order['net_sen']);

        $correction = $rows->firstWhere('type', 'order_profit_correction');
        $this->assertSame(-10, $correction['net_sen']);
        $this->assertSame('PG-CORRECTED', $correction['reference']);
        $this->assertStringContainsString('ADR-105 correction: overcredited', $correction['description']);
        $this->assertSame(CarbonImmutable::parse('2026-11-03 09:00:00')->toIso8601String(), $correction['date']);
    }

    /** A correction made in a later month never rewrites the closed month's rows. */
    public function test_each_row_lands_in_its_own_period(): void
    {
        $this->orderWithCorrection();

        $september = $this->rows('&from=2026-09-01&to=2026-09-30');
        $this->assertSame([37], $september->pluck('net_sen')->all());

        $november = $this->rows('&from=2026-11-01&to=2026-11-30');
        $this->assertSame(['order_profit_correction'], $november->pluck('type')->all());
    }

    /** Invariant: per order, order row + correction rows = the platform's order_profit ledger sum. */
    public function test_order_and_correction_rows_reconcile_to_the_ledger(): void
    {
        $corrected = $this->orderWithCorrection();
        $plain = Order::factory()->delivered()->create(['paid_at' => '2026-09-18 10:00:00']);
        $this->profitEntry($plain, 100, null, '2026-09-18 10:01:00');
        // An affiliate-side correction is not platform profit and stays out of the register.
        $this->profitEntry($plain, -5, 'affiliate fix', '2026-09-19 10:00:00', LedgerOwnerType::Affiliate);

        $rows = $this->rows();
        $earned = Order::earnedProfitsFor(Order::query()->get());

        foreach ([$corrected, $plain] as $order) {
            $registerNet = $rows->where('reference', $order->order_number)->sum('net_sen');
            $this->assertSame($earned[$order->id]['platform'], $registerNet, $order->order_number);
        }
        $this->assertSame(1, $rows->where('type', 'order_profit_correction')->count());
    }

    public function test_a_test_orders_correction_is_excluded(): void
    {
        $order = Order::factory()->delivered()->create(['is_test' => true, 'paid_at' => now()]);
        $this->profitEntry($order, -10, 'sandbox fix', now()->toDateTimeString());

        $this->assertSame(0, $this->rows()->where('type', 'order_profit_correction')->count());
    }

    public function test_export_carries_the_correction_and_the_footer_matches_the_ledger(): void
    {
        $this->orderWithCorrection();
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());

        $csv = array_map('str_getcsv', array_filter(explode("\n", $this->get('/api/accounting/transactions/export')->streamedContent())));

        $correction = collect($csv)->first(fn ($r) => ($r[1] ?? null) === 'order_profit_correction');
        $this->assertSame('-0.10', $correction[9]);
        $footer = collect($csv)->first(fn ($r) => ($r[3] ?? null) === 'TOTAL Net (excluding voided rows)');
        $this->assertSame('0.27', $footer[9]);
    }
}
