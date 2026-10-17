<?php

namespace Tests\Feature\Services\Accounting;

use App\Models\ChipSettledTransaction;
use App\Models\MembershipCheckoutAttempt;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\PaymentSettlement;
use App\Models\Reseller;
use App\Models\WalletTopupAttempt;
use App\Services\Accounting\ChipTransactionMatchType;
use App\Services\Accounting\SettlementFileParser;
use App\Services\Accounting\SettlementIngestResult;
use App\Services\Accounting\SettlementReconciliationService;
use App\Services\Membership\MembershipCheckoutAttemptStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Reseller\WalletTopupAttemptStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\SettlementFixture;
use Tests\TestCase;

/**
 * ADR-110 PR-B (fills ADR-083 decision 7) + its same-day addendum (the
 * `chip_settled_transactions` dedup guard against a re-uploaded or
 * date-overlapping settlement file).
 */
class SettlementReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.accounting_disk'));
    }

    private function service(): SettlementReconciliationService
    {
        return new SettlementReconciliationService(new SettlementFileParser);
    }

    private function ingest(string $path, string $name = 'settlement.xlsx'): SettlementIngestResult
    {
        return $this->service()->ingest(new UploadedFile($path, $name, null, null, true), null);
    }

    private function refusal(callable $upload): string
    {
        try {
            $upload();
        } catch (ValidationException $e) {
            return $e->errors()['file'][0];
        }

        $this->fail('expected the upload to be refused');
    }

    private function paidMembershipAttempt(array $overrides = []): MembershipCheckoutAttempt
    {
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();

        return MembershipCheckoutAttempt::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'sub@example.com',
            'membership_plan_id' => $plan->id,
            'fee_sen' => $plan->fee_sen,
            'total_charged_sen' => $plan->fee_sen + 100,
            'channel_code' => 'fpx',
            'subscription_number' => 'MS-'.uniqid(),
            'idempotency_key' => 'idem-'.uniqid(),
            'status' => MembershipCheckoutAttemptStatus::Paid->value,
        ], $overrides));
    }

    private function paidWalletTopupAttempt(array $overrides = []): WalletTopupAttempt
    {
        $reseller = Reseller::query()->create(['business_name' => 'Wallet Reseller', 'is_active' => true]);

        return WalletTopupAttempt::query()->create(array_merge([
            'reseller_id' => $reseller->id,
            'reference' => 'WT-'.uniqid(),
            'amount_sen' => 9000,
            'total_charged_sen' => 10000,
            'channel_code' => 'fpx',
            'status' => WalletTopupAttemptStatus::Paid->value,
            'expires_at' => now()->addMinutes(30),
        ], $overrides));
    }

    public function test_matches_a_transaction_to_a_paid_order_by_payment_ref(): void
    {
        $order = Order::factory()->create([
            'order_number' => 'PG-ABC123',
            'payment_gateway' => 'chip',
            'payment_ref' => 'tx-order-1',
            'payment_status' => PaymentStatus::Paid,
            'paid_at' => '2026-09-07 12:00:00',
            'final_amount' => 1100,
            'transaction_fee' => 100,
        ]);

        $path = SettlementFixture::build('2026-09-07 to 2026-09-07', '11.00', '1.00', '10.00', [
            ['transaction_id' => 'tx-order-1', 'reference' => 'PG-ABC123', 'amount' => '11.00', 'fee' => '1.00', 'net' => '10.00', 'settled_on' => '2026-09-07 12:32'],
        ]);

        $result = $this->ingest($path, 'settlement.xlsx');

        $this->assertSame(1, $result->newlyMatchedCount);
        $this->assertSame(0, $result->newlyUnmatchedCount);
        $this->assertSame(0, $result->alreadyReconciledSkippedCount);

        $recorded = ChipSettledTransaction::query()->where('transaction_id', 'tx-order-1')->first();
        $this->assertSame(ChipTransactionMatchType::Order, $recorded->matched_type);
        $this->assertSame($order->id, $recorded->matched_id);
        $this->assertSame($order->id, $recorded->matchedRecord()->id);

        unlink($path);
    }

    public function test_matches_a_transaction_to_a_paid_membership_checkout_attempt(): void
    {
        $attempt = $this->paidMembershipAttempt(['payment_ref' => 'tx-membership-1']);

        $path = SettlementFixture::build('2026-09-07 to 2026-09-07', '10.00', '1.00', '9.00', [
            ['transaction_id' => 'tx-membership-1', 'reference' => $attempt->subscription_number, 'amount' => '10.00', 'fee' => '1.00', 'net' => '9.00', 'settled_on' => '2026-09-07 12:32'],
        ]);

        $this->ingest($path, 'settlement.xlsx');

        $recorded = ChipSettledTransaction::query()->where('transaction_id', 'tx-membership-1')->first();
        $this->assertSame(ChipTransactionMatchType::MembershipCheckoutAttempt, $recorded->matched_type);
        $this->assertSame($attempt->id, $recorded->matched_id);

        unlink($path);
    }

    public function test_matches_a_transaction_to_a_paid_wallet_topup_attempt(): void
    {
        $topup = $this->paidWalletTopupAttempt(['chip_payment_ref' => 'tx-wallet-1']);

        $path = SettlementFixture::build('2026-09-07 to 2026-09-07', '10.00', '1.00', '9.00', [
            ['transaction_id' => 'tx-wallet-1', 'reference' => $topup->reference, 'amount' => '10.00', 'fee' => '1.00', 'net' => '9.00', 'settled_on' => '2026-09-07 12:32'],
        ]);

        $this->ingest($path, 'settlement.xlsx');

        $recorded = ChipSettledTransaction::query()->where('transaction_id', 'tx-wallet-1')->first();
        $this->assertSame(ChipTransactionMatchType::WalletTopupAttempt, $recorded->matched_type);
        $this->assertSame($topup->id, $recorded->matched_id);

        unlink($path);
    }

    /**
     * Found live testing this session: a settlement with zero matched
     * transactions (nothing to gross-compare) used to show a clean
     * `matched` status — misleading, since an unmatched transaction is
     * real money CHIP reports settled that this platform can't identify
     * at all, just as serious a reconciliation failure as a gross
     * mismatch. Fixed as an ADR-110 addendum revision, same day.
     */
    public function test_records_an_unmatched_transaction_with_no_local_record(): void
    {
        $path = SettlementFixture::build('2026-09-07 to 2026-09-07', '5.00', '0.50', '4.50', [
            ['transaction_id' => 'tx-orphan', 'reference' => 'UNKNOWN', 'amount' => '5.00', 'fee' => '0.50', 'net' => '4.50', 'settled_on' => '2026-09-07 12:32'],
        ]);

        $result = $this->ingest($path, 'settlement.xlsx');

        $this->assertSame(0, $result->newlyMatchedCount);
        $this->assertSame(1, $result->newlyUnmatchedCount);
        $this->assertSame(['tx-orphan'], $result->unmatchedTransactionIds);
        $this->assertSame('variance', $result->settlement->status);

        $recorded = ChipSettledTransaction::query()->where('transaction_id', 'tx-orphan')->first();
        $this->assertNull($recorded->matched_type);
        $this->assertNull($recorded->matched_id);

        unlink($path);
    }

    /**
     * The core fix this ADR's same-day addendum exists for: re-uploading
     * the exact same file must never double-count. Item 120: it is now
     * refused outright, so no empty settlement row or second file is left.
     */
    public function test_re_uploading_the_same_file_is_refused_and_records_nothing(): void
    {
        Order::factory()->create(['payment_gateway' => 'chip', 'payment_ref' => 'tx-dup', 'payment_status' => PaymentStatus::Paid, 'paid_at' => '2026-09-07 12:00:00']);

        $path = SettlementFixture::build('2026-09-07 to 2026-09-07', '11.00', '1.00', '10.00', [
            ['transaction_id' => 'tx-dup', 'reference' => 'PG-X', 'amount' => '11.00', 'fee' => '1.00', 'net' => '10.00', 'settled_on' => '2026-09-07 12:32'],
        ]);

        $first = $this->ingest($path);
        $this->assertSame(1, $first->newlyMatchedCount);

        $message = $this->refusal(fn () => $this->ingest($path));

        $this->assertStringContainsString("settlement #{$first->settlement->id}", $message);
        $this->assertSame(1, ChipSettledTransaction::query()->count());
        $this->assertSame(1, PaymentSettlement::query()->count());
        $this->assertCount(1, Storage::disk(config('filesystems.accounting_disk'))->allFiles());

        unlink($path);
    }

    /** A re-export of a period already uploaded has different bytes but nothing new: also refused. */
    public function test_a_re_export_with_no_new_transaction_is_refused(): void
    {
        $tx = ['transaction_id' => 'tx-reexport', 'reference' => 'PG-X', 'amount' => '11.00', 'fee' => '1.00', 'net' => '10.00', 'settled_on' => '2026-09-07 12:32'];
        $original = SettlementFixture::build('2026-09-07 to 2026-09-07', '11.00', '1.00', '10.00', [$tx]);
        $reExport = SettlementFixture::build('2026-09-07 to 2026-09-07', '11.00', '1.00', '10.00', [$tx], sheetName: 'Card');
        $this->assertNotSame(hash_file('sha256', $original), hash_file('sha256', $reExport));

        $this->ingest($original, 'settlements-20260907120000.xlsx');
        $this->refusal(fn () => $this->ingest($reExport, 'settlements-20260908090000.xlsx'));

        $this->assertSame(1, PaymentSettlement::query()->count());

        unlink($original);
        unlink($reExport);
    }

    /** Item 120: the original is kept under its payout month (date_to), with the hash of what was reconciled. */
    public function test_the_original_file_is_kept_under_its_date_to_month_with_its_hash(): void
    {
        $path = SettlementFixture::build('2026-09-28 to 2026-10-02', '11.00', '1.00', '10.00', [
            ['transaction_id' => 'tx-keep', 'reference' => 'PG-X', 'amount' => '11.00', 'fee' => '1.00', 'net' => '10.00', 'settled_on' => '2026-10-02 12:32'],
        ]);

        $settlement = $this->ingest($path, 'settlements-20261002.xlsx')->settlement;

        $this->assertStringStartsWith('accounting/chip-settlements/2026/10/', $settlement->file_path);
        $this->assertSame(hash_file('sha256', $path), $settlement->file_sha256);
        $this->assertSame('settlements-20261002.xlsx', $settlement->original_filename);
        Storage::disk(config('filesystems.accounting_disk'))->assertExists($settlement->file_path);

        unlink($path);
    }

    public function test_a_file_that_is_not_a_chip_export_is_refused_not_a_500(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bad').'.xlsx';
        file_put_contents($path, 'not a spreadsheet');

        $this->refusal(fn () => $this->ingest($path));
        $this->assertSame(0, PaymentSettlement::query()->count());
        $this->assertSame([], Storage::disk(config('filesystems.accounting_disk'))->allFiles());

        unlink($path);
    }

    /**
     * A second file whose date range overlaps the first must also skip
     * the overlapping transaction — the dedup key is the transaction's
     * own identity, never the upload's date range.
     */
    public function test_an_overlapping_date_range_file_also_skips_the_shared_transaction(): void
    {
        Order::factory()->create(['payment_gateway' => 'chip', 'payment_ref' => 'tx-week1-day7', 'payment_status' => PaymentStatus::Paid, 'paid_at' => '2026-09-07 12:00:00']);
        Order::factory()->create(['payment_gateway' => 'chip', 'payment_ref' => 'tx-week2-day8', 'payment_status' => PaymentStatus::Paid, 'paid_at' => '2026-09-08 12:00:00']);

        $week1 = SettlementFixture::build('2026-09-01 to 2026-09-07', '11.00', '1.00', '10.00', [
            ['transaction_id' => 'tx-week1-day7', 'reference' => 'PG-A', 'amount' => '11.00', 'fee' => '1.00', 'net' => '10.00', 'settled_on' => '2026-09-07 12:32'],
        ]);
        $week2 = SettlementFixture::build('2026-09-07 to 2026-09-08', '22.00', '2.00', '20.00', [
            ['transaction_id' => 'tx-week1-day7', 'reference' => 'PG-A', 'amount' => '11.00', 'fee' => '1.00', 'net' => '10.00', 'settled_on' => '2026-09-07 12:32'],
            ['transaction_id' => 'tx-week2-day8', 'reference' => 'PG-B', 'amount' => '11.00', 'fee' => '1.00', 'net' => '10.00', 'settled_on' => '2026-09-08 09:00'],
        ]);

        $this->ingest($week1, 'week1.xlsx');
        $overlap = $this->ingest($week2, 'week2.xlsx');

        $this->assertSame(1, $overlap->newlyMatchedCount); // only tx-week2-day8 is genuinely new
        $this->assertSame(1, $overlap->alreadyReconciledSkippedCount); // tx-week1-day7 already reconciled by week1's upload

        unlink($week1);
        unlink($week2);
    }

    /**
     * ADR-110 PR-B addendum (automatic reconciliation) — `matched_*`
     * sums ONLY the transactions actually matched in THIS file, never a
     * calendar-window query (the bug this addendum fixes — see
     * `docs/adr.md`'s ADR-110 entry). A second order paid in the same
     * window but absent from the file must not inflate `matched_gross_sen`.
     */
    public function test_matched_totals_sum_only_transactions_actually_in_this_file(): void
    {
        Order::factory()->create(['payment_gateway' => 'chip', 'payment_ref' => 'tx-in-file', 'payment_status' => PaymentStatus::Paid, 'paid_at' => '2026-09-07 12:00:00', 'final_amount' => 1100, 'transaction_fee' => 100]);
        // Paid the same day, same window — but never appears in the uploaded file (e.g. still awaiting T+1/T+2 settlement).
        Order::factory()->create(['payment_gateway' => 'chip', 'payment_ref' => 'tx-not-in-file', 'payment_status' => PaymentStatus::Paid, 'paid_at' => '2026-09-07 13:00:00', 'final_amount' => 99999, 'transaction_fee' => 9999]);

        $path = SettlementFixture::build('2026-09-07 to 2026-09-07', '11.00', '1.00', '10.00', [
            ['transaction_id' => 'tx-in-file', 'reference' => 'PG-X', 'amount' => '11.00', 'fee' => '1.00', 'net' => '10.00', 'settled_on' => '2026-09-07 12:32'],
        ]);
        $result = $this->ingest($path, 'settlement.xlsx');

        $this->assertSame(1100, $result->settlement->matched_gross_sen);
        $this->assertSame(100, $result->settlement->matched_fee_sen);
        $this->assertSame(1000, $result->settlement->matched_net_sen);
        $this->assertSame('matched', $result->settlement->status);

        unlink($path);
    }

    /**
     * A sandbox order's `payment_ref` must never match a real CHIP
     * settlement transaction — sandbox never calls the real gateway, so
     * a real file's transaction_id colliding with one is never a
     * genuine match.
     */
    public function test_a_sandbox_order_is_never_matched(): void
    {
        Order::factory()->create(['payment_gateway' => 'chip', 'payment_ref' => 'tx-sandbox', 'payment_status' => PaymentStatus::Paid, 'paid_at' => '2026-09-07 12:00:00', 'final_amount' => 1100, 'is_test' => true]);

        $path = SettlementFixture::build('2026-09-07 to 2026-09-07', '11.00', '1.00', '10.00', [
            ['transaction_id' => 'tx-sandbox', 'reference' => 'PG-X', 'amount' => '11.00', 'fee' => '1.00', 'net' => '10.00', 'settled_on' => '2026-09-07 12:32'],
        ]);
        $result = $this->ingest($path, 'settlement.xlsx');

        $this->assertSame(0, $result->newlyMatchedCount);
        $this->assertSame(1, $result->newlyUnmatchedCount);

        unlink($path);
    }

    /**
     * `status` is fully computed, never admin-typed — the core fix this
     * addendum exists for. A gross mismatch between CHIP's own file and
     * our own record for the SAME matched transaction is a genuine
     * anomaly (data corruption, wrong match); a fee-level difference is
     * normal (CHIP's real MDR fee vs our own assumption) and must never
     * trigger `variance` on its own — see `MonthlyAccountingSummaryServiceTest`
     * for where that fee gap is correctly booked instead.
     */
    public function test_status_is_variance_when_a_matched_transactions_gross_disagrees(): void
    {
        Order::factory()->create(['payment_gateway' => 'chip', 'payment_ref' => 'tx-wrong-gross', 'payment_status' => PaymentStatus::Paid, 'paid_at' => '2026-09-07 12:00:00', 'final_amount' => 1000, 'transaction_fee' => 100]);

        $path = SettlementFixture::build('2026-09-07 to 2026-09-07', '11.00', '1.00', '10.00', [
            // CHIP's file says amount 1100 sen; our own record says 1000 sen gross — a genuine mismatch.
            ['transaction_id' => 'tx-wrong-gross', 'reference' => 'PG-X', 'amount' => '11.00', 'fee' => '1.00', 'net' => '10.00', 'settled_on' => '2026-09-07 12:32'],
        ]);
        $result = $this->ingest($path, 'settlement.xlsx');

        $this->assertSame('variance', $result->settlement->status);

        unlink($path);
    }

    /**
     * A fee-only difference (our assumed transaction_fee vs CHIP's real
     * MDR) is normal and must never flip status to `variance` on its
     * own — gross agreeing is the only thing that matters for `status`.
     */
    public function test_status_stays_matched_when_only_the_fee_assumption_differs(): void
    {
        // Our own fee assumption (100 sen) differs from CHIP's real fee (150 sen) — gross still agrees at 1100.
        Order::factory()->create(['payment_gateway' => 'chip', 'payment_ref' => 'tx-fee-diff', 'payment_status' => PaymentStatus::Paid, 'paid_at' => '2026-09-07 12:00:00', 'final_amount' => 1100, 'transaction_fee' => 100]);

        $path = SettlementFixture::build('2026-09-07 to 2026-09-07', '11.00', '1.50', '9.50', [
            ['transaction_id' => 'tx-fee-diff', 'reference' => 'PG-X', 'amount' => '11.00', 'fee' => '1.50', 'net' => '9.50', 'settled_on' => '2026-09-07 12:32'],
        ]);
        $result = $this->ingest($path, 'settlement.xlsx');

        $this->assertSame('matched', $result->settlement->status);

        unlink($path);
    }

    public function test_paid_but_not_settled_lists_a_chip_paid_order_missing_from_every_file(): void
    {
        Order::factory()->create(['order_number' => 'PG-MISSING', 'payment_gateway' => 'chip', 'payment_ref' => 'tx-never-settled', 'payment_status' => PaymentStatus::Paid, 'paid_at' => '2026-09-07 12:00:00', 'final_amount' => 500]);

        $rows = $this->service()->paidButNotSettled(Carbon::parse('2026-09-07'), Carbon::parse('2026-09-07'));

        $this->assertCount(1, $rows);
        $this->assertSame('PG-MISSING', $rows[0]['reference']);
        $this->assertSame(500, $rows[0]['amount_sen']);
    }

    public function test_a_file_with_no_transactions_is_refused(): void
    {
        $path = SettlementFixture::build('2026-09-07 to 2026-09-07', '0.00', '0.00', '0.00', []);

        $this->assertSame('This settlement file has no transactions.', $this->refusal(fn () => $this->ingest($path)));
        $this->assertSame(0, PaymentSettlement::query()->count());

        unlink($path);
    }
}
