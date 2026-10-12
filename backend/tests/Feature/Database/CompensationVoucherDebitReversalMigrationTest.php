<?php

namespace Tests\Feature\Database;

use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Voucher;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §16 item 79 (ADR-024 2026-10-12 addendum): every compensation voucher
 * issued before the fix carries a `voucher_issued` debit it should never
 * have had. The migration appends one reasoned reversal per such row and
 * touches nothing else.
 */
class CompensationVoucherDebitReversalMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migrate(): void
    {
        (require database_path('migrations/2026_10_12_000000_reverse_compensation_voucher_platform_debits.php'))->up();
    }

    /** A voucher with the pre-fix debit, written the way the old issue() did. */
    private function voucherWithOldDebit(int $amount, ?int $orderId): Voucher
    {
        $voucher = Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id, 'order_id' => $orderId, 'code' => 'VC-'.uniqid(),
            'customer_email' => 'a@example.com', 'amount' => $amount, 'remaining' => $amount, 'status' => 'active', 'reason' => 'test',
        ]);
        app(LedgerService::class)->credit(LedgerOwnerType::Platform, null, -$amount, 'voucher_issued', 'voucher', $voucher->id);

        return $voucher;
    }

    private function net(Voucher $voucher): int
    {
        return (int) LedgerEntry::query()->where('type', 'voucher_issued')->where('reference_id', $voucher->id)->sum('amount');
    }

    public function test_reverses_compensation_debits_once_and_leaves_admin_vouchers_alone(): void
    {
        $compensation = $this->voucherWithOldDebit(372, Order::factory()->create()->id);
        $zero = $this->voucherWithOldDebit(0, Order::factory()->create()->id);
        $admin = $this->voucherWithOldDebit(1000, null);

        $this->migrate();
        $this->migrate();

        $this->assertSame(0, $this->net($compensation));
        $reversal = LedgerEntry::query()->where('reference_id', $compensation->id)->where('amount', '>', 0)->sole();
        $this->assertSame('platform', $reversal->owner_type);
        $this->assertSame('voucher_issued', $reversal->type);
        $this->assertNotNull($reversal->reason);

        $this->assertSame(1, LedgerEntry::query()->where('reference_id', $zero->id)->count());
        $this->assertSame(-1000, $this->net($admin));
        $this->assertSame(-1000, app(LedgerService::class)->balance(LedgerOwnerType::Platform, null));
    }
}
