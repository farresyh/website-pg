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
use App\Services\Accounting\SettlementReconciliationService;
use App\Services\Membership\MembershipCheckoutAttemptStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Reseller\WalletTopupAttemptStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function service(): SettlementReconciliationService
    {
        return new SettlementReconciliationService(new SettlementFileParser);
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

        $result = $this->service()->ingest($path, 'settlement.xlsx', null);

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

        $this->service()->ingest($path, 'settlement.xlsx', null);

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

        $this->service()->ingest($path, 'settlement.xlsx', null);

        $recorded = ChipSettledTransaction::query()->where('transaction_id', 'tx-wallet-1')->first();
        $this->assertSame(ChipTransactionMatchType::WalletTopupAttempt, $recorded->matched_type);
        $this->assertSame($topup->id, $recorded->matched_id);

        unlink($path);
    }

    public function test_records_an_unmatched_transaction_with_no_local_record(): void
    {
        $path = SettlementFixture::build('2026-09-07 to 2026-09-07', '5.00', '0.50', '4.50', [
            ['transaction_id' => 'tx-orphan', 'reference' => 'UNKNOWN', 'amount' => '5.00', 'fee' => '0.50', 'net' => '4.50', 'settled_on' => '2026-09-07 12:32'],
        ]);

        $result = $this->service()->ingest($path, 'settlement.xlsx', null);

        $this->assertSame(0, $result->newlyMatchedCount);
        $this->assertSame(1, $result->newlyUnmatchedCount);
        $this->assertSame(['tx-orphan'], $result->unmatchedTransactionIds);

        $recorded = ChipSettledTransaction::query()->where('transaction_id', 'tx-orphan')->first();
        $this->assertNull($recorded->matched_type);
        $this->assertNull($recorded->matched_id);

        unlink($path);
    }

    /**
     * The core fix this ADR's same-day addendum exists for: re-uploading
     * the exact same file must never double-count.
     */
    public function test_re_uploading_the_same_file_skips_every_already_reconciled_transaction(): void
    {
        Order::factory()->create(['payment_gateway' => 'chip', 'payment_ref' => 'tx-dup', 'payment_status' => PaymentStatus::Paid, 'paid_at' => '2026-09-07 12:00:00']);

        $path = SettlementFixture::build('2026-09-07 to 2026-09-07', '11.00', '1.00', '10.00', [
            ['transaction_id' => 'tx-dup', 'reference' => 'PG-X', 'amount' => '11.00', 'fee' => '1.00', 'net' => '10.00', 'settled_on' => '2026-09-07 12:32'],
        ]);

        $first = $this->service()->ingest($path, 'settlement.xlsx', null);
        $this->assertSame(1, $first->newlyMatchedCount);
        $this->assertSame(0, $first->alreadyReconciledSkippedCount);

        $second = $this->service()->ingest($path, 'settlement.xlsx', null);
        $this->assertSame(0, $second->newlyMatchedCount);
        $this->assertSame(0, $second->newlyUnmatchedCount);
        $this->assertSame(1, $second->alreadyReconciledSkippedCount);

        $this->assertSame(1, ChipSettledTransaction::query()->where('transaction_id', 'tx-dup')->count());
        $this->assertSame(2, PaymentSettlement::query()->count()); // one row per upload, even though the transaction itself wasn't double-counted

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

        $this->service()->ingest($week1, 'week1.xlsx', null);
        $overlap = $this->service()->ingest($week2, 'week2.xlsx', null);

        $this->assertSame(1, $overlap->newlyMatchedCount); // only tx-week2-day8 is genuinely new
        $this->assertSame(1, $overlap->alreadyReconciledSkippedCount); // tx-week1-day7 already reconciled by week1's upload

        unlink($week1);
        unlink($week2);
    }

    public function test_expected_totals_exclude_test_orders(): void
    {
        Order::factory()->create(['payment_gateway' => 'chip', 'payment_ref' => 'tx-real', 'payment_status' => PaymentStatus::Paid, 'paid_at' => '2026-09-07 12:00:00', 'final_amount' => 1100, 'transaction_fee' => 100, 'is_test' => false]);
        Order::factory()->create(['payment_gateway' => 'chip', 'payment_ref' => 'tx-sandbox', 'payment_status' => PaymentStatus::Paid, 'paid_at' => '2026-09-07 12:00:00', 'final_amount' => 99999, 'transaction_fee' => 9999, 'is_test' => true]);

        $path = SettlementFixture::build('2026-09-07 to 2026-09-07', '11.00', '1.00', '10.00', []);
        $result = $this->service()->ingest($path, 'settlement.xlsx', null);

        $this->assertSame(1100, $result->settlement->expected_gross_sen);
        $this->assertSame(100, $result->settlement->expected_fee_sen);

        unlink($path);
    }

    public function test_paid_but_not_settled_lists_a_chip_paid_order_missing_from_every_file(): void
    {
        Order::factory()->create(['order_number' => 'PG-MISSING', 'payment_gateway' => 'chip', 'payment_ref' => 'tx-never-settled', 'payment_status' => PaymentStatus::Paid, 'paid_at' => '2026-09-07 12:00:00', 'final_amount' => 500]);

        $path = SettlementFixture::build('2026-09-07 to 2026-09-07', '0.00', '0.00', '0.00', []);
        $result = $this->service()->ingest($path, 'settlement.xlsx', null);

        $this->assertCount(1, $result->paidButNotSettled);
        $this->assertSame('PG-MISSING', $result->paidButNotSettled[0]['reference']);
        $this->assertSame(500, $result->paidButNotSettled[0]['amount_sen']);

        unlink($path);
    }
}
