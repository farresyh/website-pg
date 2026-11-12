<?php

namespace Tests\Feature\Database;

use App\Models\Order;
use App\Models\Voucher;
use App\Models\VoucherMerge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-004 2026-11-12 addendum, decision 2: the backfill classes every
 * existing voucher once, with a merge target following its sources.
 */
class VoucherSourceBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function voucher(?int $orderId, string $status = 'active'): Voucher
    {
        return Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id, 'order_id' => $orderId, 'code' => 'VC-'.uniqid(),
            'customer_email' => 'a@example.com', 'amount' => 100, 'remaining' => 100, 'status' => $status, 'reason' => 'test',
        ]);
    }

    private function mergeInto(Voucher $target, Voucher ...$sources): void
    {
        foreach ($sources as $source) {
            VoucherMerge::query()->create(['source_voucher_id' => $source->id, 'target_voucher_id' => $target->id, 'reason' => 'x', 'merged_by' => null]);
        }
    }

    public function test_every_existing_voucher_is_classed_once(): void
    {
        $paid = $this->voucher(Order::factory()->create()->id, 'merged');
        $paid2 = $this->voucher(Order::factory()->create()->id, 'merged');
        $sandbox = $this->voucher(Order::factory()->create(['is_test' => true])->id);
        $gift = $this->voucher(null, 'merged');
        $cashTarget = $this->voucher(null);
        $this->mergeInto($cashTarget, $paid, $paid2);
        $mixedTarget = $this->voucher(null);
        $this->mergeInto($mixedTarget, $gift);
        $chained = $this->voucher(null);
        $cashTarget->update(['status' => 'merged']);
        $this->mergeInto($chained, $cashTarget);

        $migration = require database_path('migrations/2026_11_17_100000_add_source_and_cash_refunds_to_vouchers.php');
        $migration->down();
        $migration->up();

        $source = fn (Voucher $v) => Voucher::query()->whereKey($v->id)->value('source');
        $this->assertSame('customer_cash', $source($paid));
        $this->assertSame('gift', $source($sandbox));
        $this->assertSame('gift', $source($gift));
        $this->assertSame('customer_cash', $source($cashTarget));
        $this->assertSame('gift', $source($mixedTarget));
        $this->assertSame('customer_cash', $source($chained));
        $this->assertSame(4, VoucherMerge::query()->count(), 'the migration never touches the merge history');
    }
}
