<?php

namespace Tests\Feature\Services\Dashboard;

use App\Models\AdminUser;
use App\Models\BackupRun;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Review;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\Withdrawal;
use App\Services\Accounting\SupplierLedgerEntryType;
use App\Services\CircuitBreaker\CircuitBreaker;
use App\Services\Currency\CurrencyRateService;
use App\Services\Dashboard\DashboardService;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\OpenWa\OpenWaSessionStatus;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Scheduling\SchedulerLiveness;
use App\Services\Withdrawal\WithdrawalStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Locks in ADR-045's 27 grilled/pinned decisions — a regression here
 * would silently show the founder a wrong number on the one screen
 * meant to be a quick, trustworthy health/business snapshot.
 */
class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private DashboardService $dashboard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dashboard = new DashboardService(new OpenWaSessionStatus, new CurrencyRateService, new SchedulerLiveness);
    }

    /**
     * 2026-09-15 addendum: health()'s new balance_myr_equivalent calls
     * CurrencyRateService for a non-MYR supplier — any test creating
     * one needs this (or its own specific `Http::fake([...])`) to stay
     * free of real network I/O. Deliberately called per-test, not from
     * setUp(): `Http::fake()`'s stub callbacks stack in registration
     * order and the *first* matching one wins (Laravel's own
     * `PendingRequest::buildStubHandler()`), so a blanket setUp() fake
     * would always shadow a more specific one a test tries to add
     * later — found live writing this addendum's own tests.
     */
    private function fakeNoFxRateAvailable(): void
    {
        Http::fake();
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge(['placed_via' => 'storefront',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 20,
            'payment_status' => PaymentStatus::Paid->value,
            'paid_at' => now(),
            'delivery_status' => DeliveryStatus::Delivered->value,
            'is_test' => false,
        ], $overrides));
    }

    // --- summary() / DASH-1 ---------------------------------------------

    // --- health() / DASH-2 -----------------------------------------------

    /**
     * 2026-09-15 addendum: found live — this endpoint never carried
     * `currency` at all, so both this screen and /middleware's own
     * dashboard (same endpoint) either hardcoded "RM" or showed a bare
     * number with no currency, regardless of the real supplier
     * currency (Digiflazz is IDR, not MYR).
     */
    public function test_health_includes_each_suppliers_own_currency(): void
    {
        $this->fakeNoFxRateAvailable();
        Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion-'.uniqid(), 'currency' => 'MYR', 'balance' => 250.50, 'api_config' => []]);
        Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz-'.uniqid(), 'currency' => 'IDR', 'balance' => 832672, 'api_config' => []]);

        $rows = collect($this->dashboard->health()['suppliers'])->keyBy('name');

        $this->assertSame('MYR', $rows['Gamevion']['currency']);
        $this->assertSame('IDR', $rows['Digiflazz']['currency']);
    }

    /**
     * 2026-09-28 addendum: the reseller-wallet-float vs supplier-balance
     * treasury check — sums every reseller's wallet ledger balance
     * (owner_type=reseller_wallet) regardless of which reseller, and
     * separately sums every supplier's own balance_myr_equivalent
     * computed just above it.
     */
    public function test_health_sums_reseller_wallet_liability_across_all_resellers(): void
    {
        $this->fakeNoFxRateAvailable();
        Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion-'.uniqid(), 'currency' => 'MYR', 'balance' => 50000, 'api_config' => []]);
        LedgerEntry::query()->create(['owner_type' => LedgerOwnerType::ResellerWallet->value, 'owner_id' => 1, 'type' => 'wallet_topup', 'amount' => 30000]);
        LedgerEntry::query()->create(['owner_type' => LedgerOwnerType::ResellerWallet->value, 'owner_id' => 1, 'type' => 'order_spend', 'amount' => -5000]);
        LedgerEntry::query()->create(['owner_type' => LedgerOwnerType::ResellerWallet->value, 'owner_id' => 2, 'type' => 'wallet_topup', 'amount' => 12000]);
        // A different owner type must never leak into the wallet total.
        LedgerEntry::query()->create(['owner_type' => LedgerOwnerType::Affiliate->value, 'owner_id' => 99, 'type' => 'order_profit', 'amount' => 999999]);

        $health = $this->dashboard->health();

        $this->assertSame(37000, $health['reseller_wallet_liability_sen']);
        $this->assertSame(50000.0, $health['total_supplier_balance_myr_equivalent']);
    }

    /** An already-MYR supplier needs no FX lookup — the equivalent is just its own balance. */
    public function test_health_myr_equivalent_is_the_balance_itself_for_an_already_myr_supplier(): void
    {
        Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion-'.uniqid(), 'currency' => 'MYR', 'balance' => 250.50, 'api_config' => []]);

        $row = collect($this->dashboard->health()['suppliers'])->firstWhere('name', 'Gamevion');

        $this->assertSame(250.5, $row['balance_myr_equivalent']);
    }

    /** A foreign-currency supplier's equivalent goes through CurrencyRateService — real conversion, not a hardcoded RM label. */
    public function test_health_myr_equivalent_converts_a_foreign_currency_balance(): void
    {
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['MYR' => 0.000231]], 200)]);
        Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz-'.uniqid(), 'currency' => 'IDR', 'balance' => 832672, 'api_config' => []]);

        $row = collect($this->dashboard->health()['suppliers'])->firstWhere('name', 'Digiflazz');

        $this->assertSame(round(832672 * 0.000231, 2), $row['balance_myr_equivalent']);
    }

    /** No live rate and nothing ever cached — never breaks the whole 60s-polled endpoint, just this one field. */
    public function test_health_myr_equivalent_is_null_when_no_rate_is_available(): void
    {
        Http::fake(['open.er-api.com/*' => Http::response([], 500)]);
        Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz-'.uniqid(), 'currency' => 'IDR', 'balance' => 832672, 'api_config' => []]);

        $health = $this->dashboard->health();
        $row = collect($health['suppliers'])->firstWhere('name', 'Digiflazz');

        $this->assertNull($row['balance_myr_equivalent']);
        $this->assertSame(832672.0, $row['balance']);
    }

    public function test_health_reads_circuit_state_derived_not_live(): void
    {
        $slug = 'test-supplier-'.uniqid();
        Supplier::query()->create(['name' => 'Test Supplier', 'slug' => $slug, 'api_config' => [], 'currency' => 'MYR', 'balance' => 15000]);

        $breaker = new CircuitBreaker($slug, failureThreshold: 3, cooldownSeconds: 60);
        $breaker->recordFailure();
        $breaker->recordFailure();
        $breaker->recordFailure();

        $health = $this->dashboard->health();

        $supplierRow = collect($health['suppliers'])->firstWhere('slug', $slug);
        $this->assertSame('open', $supplierRow['circuit_state']);
        $this->assertSame(15000.0, $supplierRow['balance']);
        $this->assertFalse($supplierRow['low_balance']);
    }

    public function test_health_flags_a_supplier_whose_balance_is_below_its_threshold(): void
    {
        $this->fakeNoFxRateAvailable();
        Supplier::query()->create([
            'name' => 'Low One', 'slug' => 'low-'.uniqid(), 'currency' => 'IDR', 'balance' => 500,
            'api_config' => ['low_balance_threshold' => '1000'],
        ]);
        Supplier::query()->create([
            'name' => 'Fine One', 'slug' => 'fine-'.uniqid(), 'currency' => 'IDR', 'balance' => 5000,
            'api_config' => ['low_balance_threshold' => '1000'],
        ]);
        Supplier::query()->create([
            'name' => 'No Threshold', 'slug' => 'nothr-'.uniqid(), 'currency' => 'IDR', 'balance' => 1,
            'api_config' => [],
        ]);

        $rows = collect($this->dashboard->health()['suppliers'])->keyBy('name');

        $this->assertTrue($rows['Low One']['low_balance']);
        $this->assertFalse($rows['Fine One']['low_balance']);
        $this->assertFalse($rows['No Threshold']['low_balance'], 'no threshold means never flagged');

        // ADR-069 stress-test Q8 — health() reads the threshold out of
        // the encrypted api_config but must never surface it: the
        // supplier row is a fixed whitelist of keys. ADR-083 decision 6
        // adds 'drift' (null here — none of these three set drift_threshold).
        // 2026-09-15 addendum adds 'currency'/'balance_myr_equivalent'.
        $this->assertSame(
            ['id', 'name', 'slug', 'balance', 'is_api_backed', 'is_pay_after', 'currency', 'balance_myr_equivalent', 'low_balance', 'drift', 'circuit_state'],
            array_keys($rows['Low One']),
        );
        $this->assertNull($rows['Low One']['drift']);
    }

    /** ADR-083 decision 6: the drift chip's own data, computed via Supplier::fundingDrift(). */
    public function test_health_flags_a_supplier_whose_funding_ledger_has_drifted(): void
    {
        $this->fakeNoFxRateAvailable();
        $drifted = Supplier::query()->create([
            'name' => 'Drifted One', 'slug' => 'drifted-'.uniqid(), 'currency' => 'IDR', 'balance' => 100000,
            'api_config' => ['drift_threshold' => '1000'],
        ]);
        SupplierLedgerEntry::query()->create([
            'supplier_id' => $drifted->id,
            'type' => SupplierLedgerEntryType::Topup->value,
            'amount' => 50000, // 50,000 vs polled 100,000 — variance 50,000 > threshold 1,000
            'currency' => 'IDR',
        ]);

        $inSync = Supplier::query()->create([
            'name' => 'In Sync One', 'slug' => 'insync-'.uniqid(), 'currency' => 'IDR', 'balance' => 100000,
            'api_config' => ['drift_threshold' => '1000'],
        ]);
        SupplierLedgerEntry::query()->create([
            'supplier_id' => $inSync->id,
            'type' => SupplierLedgerEntryType::Topup->value,
            'amount' => 100000,
            'currency' => 'IDR',
        ]);

        $rows = collect($this->dashboard->health()['suppliers'])->keyBy('name');

        $this->assertTrue($rows['Drifted One']['drift']['is_drifted']);
        $this->assertSame(50000.0, $rows['Drifted One']['drift']['variance']);
        $this->assertFalse($rows['In Sync One']['drift']['is_drifted']);
    }

    public function test_stuck_orders_combines_needs_review_and_stale_processing_and_stale_pending(): void
    {
        config(['services.delivery_reconciliation.stale_after_minutes' => 15]);
        config(['services.delivery_reconciliation.pending_stale_minutes' => 10]);

        $this->order(['delivery_status' => DeliveryStatus::NeedsReview->value]);

        $staleProcessing = $this->order(['delivery_status' => DeliveryStatus::Processing->value]);
        DB::table('orders')->where('id', $staleProcessing->id)->update(['updated_at' => now()->subMinutes(20)]);

        $stalePending = $this->order(['delivery_status' => DeliveryStatus::Pending->value]);
        DB::table('orders')->where('id', $stalePending->id)->update(['updated_at' => now()->subMinutes(12)]);

        // Fresh processing/pending orders — must NOT count.
        $this->order(['delivery_status' => DeliveryStatus::Processing->value]);
        $this->order(['delivery_status' => DeliveryStatus::Pending->value]);

        $health = $this->dashboard->health();

        $this->assertSame(3, $health['stuck_orders']['value']);
    }

    public function test_stuck_orders_excludes_test_orders(): void
    {
        $this->order(['delivery_status' => DeliveryStatus::NeedsReview->value, 'is_test' => true]);

        $health = $this->dashboard->health();

        $this->assertSame(0, $health['stuck_orders']['value']);
    }

    public function test_pending_payments_counts_orders_with_pending_payment_status(): void
    {
        $this->order(['payment_status' => PaymentStatus::Pending->value, 'paid_at' => null]);
        $this->order(); // Paid — must not count

        $health = $this->dashboard->health();

        $this->assertSame(1, $health['pending_payments']['value']);
    }

    /**
     * Wave 4 audit Low (2026-09-29): `pending` read the `jobs` table, which
     * nothing writes since ADR-048 moved the queue to Redis — it was
     * always 0. Now the live queue size across every order lane.
     */
    public function test_queue_depth_counts_every_order_lane_from_the_live_queue(): void
    {
        Queue::fake();
        Queue::pushOn('orders', 'retail-job');
        Queue::pushOn('orders-reseller', 'reseller-job');
        Queue::pushOn('orders-combo', 'combo-job');
        Queue::pushOn('price-sync', 'not-an-order-job');

        $health = $this->dashboard->health();

        $this->assertSame(3, $health['queue']['pending']);
    }

    // --- funnel() / DASH-3 ------------------------------------------------

    // --- topGames() / DASH-4 -----------------------------------------------

    // --- hourlyActivity() / DASH-5 ------------------------------------------

    // --- attention (ADR-104 2026-10-15 grill Q9 / S4) -------------------

    /** @return array<string, array{key: string, level: string, count: int, subject: string|null}> */
    private function attentionItems(): array
    {
        return collect($this->dashboard->health()['attention']['items'])
            ->keyBy(fn (array $item) => $item['key'].($item['subject'] ? ':'.$item['subject'] : ''))
            ->all();
    }

    public function test_attention_is_empty_when_all_is_well(): void
    {
        $attention = $this->dashboard->health()['attention'];

        $this->assertSame([], $attention['items']);
        $this->assertSame(0, $attention['needs_action_count']);
    }

    public function test_a_needs_review_order_is_not_counted_again_as_stuck(): void
    {
        $this->order(['delivery_status' => DeliveryStatus::NeedsReview->value]);
        $stale = $this->order(['delivery_status' => DeliveryStatus::Processing->value]);
        DB::table('orders')->where('id', $stale->id)->update(['updated_at' => now()->subDay()]);

        $items = $this->attentionItems();

        $this->assertSame(['key' => 'needs_review', 'level' => 'red', 'count' => 1, 'subject' => null], $items['needs_review']);
        $this->assertSame(1, $items['stuck_orders']['count']);
        $this->assertSame(2, $this->dashboard->health()['attention']['needs_action_count']);
    }

    public function test_failed_uncompensated_orders_need_action(): void
    {
        $this->order(['delivery_status' => DeliveryStatus::Failed->value]);

        $this->assertSame(['key' => 'needs_action_orders', 'level' => 'red', 'count' => 1, 'subject' => null], $this->attentionItems()['needs_action_orders']);
    }

    public function test_supplier_low_balance_is_amber_and_names_the_supplier(): void
    {
        $this->fakeNoFxRateAvailable();
        Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz-'.uniqid(), 'currency' => 'IDR', 'balance' => 5000, 'api_config' => ['low_balance_threshold' => 10000]]);

        $this->assertSame(['key' => 'supplier_low_balance', 'level' => 'amber', 'count' => 1, 'subject' => 'Digiflazz'], $this->attentionItems()['supplier_low_balance:Digiflazz']);
    }

    public function test_withdrawals_to_process_count_pending_and_approved(): void
    {
        $admin = AdminUser::factory()->create();
        foreach ([WithdrawalStatus::Pending, WithdrawalStatus::Approved, WithdrawalStatus::Completed] as $status) {
            Withdrawal::query()->create(['owner_type' => 'platform', 'amount' => 1000, 'bank_name' => 'Maybank', 'bank_account_no' => '1', 'bank_account_holder' => 'KRS', 'status' => $status, 'requested_by' => $admin->id]);
        }

        $this->assertSame(2, $this->attentionItems()['withdrawals_to_process']['count']);
    }

    public function test_payments_off_and_pending_reviews_are_neutral_and_not_counted(): void
    {
        PaymentMethod::query()->create(['channel_code' => 'fpx', 'label' => 'FPX', 'category' => 'fpx', 'gateway' => 'chip', 'is_active' => false, 'percentage_rate' => 0.0, 'flat_fee_sen' => 100]);
        Review::query()->create(['order_id' => $this->order()->id, 'rating' => 5]);

        $items = $this->attentionItems();

        $this->assertSame('neutral', $items['payments_off']['level']);
        $this->assertSame(['key' => 'reviews_pending', 'level' => 'neutral', 'count' => 1, 'subject' => null], $items['reviews_pending']);
        $this->assertSame(0, $this->dashboard->health()['attention']['needs_action_count']);
    }

    public function test_payments_are_not_off_while_one_method_is_active(): void
    {
        PaymentMethod::query()->create(['channel_code' => 'fpx', 'label' => 'FPX', 'category' => 'fpx', 'gateway' => 'chip', 'is_active' => true, 'percentage_rate' => 0.0, 'flat_fee_sen' => 100]);
        PaymentMethod::query()->create(['channel_code' => 'qr', 'label' => 'QR', 'category' => 'fpx', 'gateway' => 'chip', 'is_active' => false, 'percentage_rate' => 0.0, 'flat_fee_sen' => 100]);

        $this->assertArrayNotHasKey('payments_off', $this->attentionItems());
    }

    public function test_a_disconnected_reseller_bot_is_red_but_no_recent_event_is_not(): void
    {
        $this->assertArrayNotHasKey('reseller_bot_disconnected', $this->attentionItems());

        (new OpenWaSessionStatus)->record('disconnected');

        $this->assertSame('red', $this->attentionItems()['reseller_bot_disconnected']['level']);
    }

    public function test_the_last_backup_failing_is_amber(): void
    {
        BackupRun::query()->create(['status' => 'success', 'triggered_by' => 'system']);
        BackupRun::query()->create(['status' => 'failed', 'triggered_by' => 'system']);

        $this->assertSame('amber', $this->attentionItems()['backup_failed']['level']);
    }

    /** ADR-039 2026-10-22 addendum: judged per source. */
    public function test_a_failed_openwa_backup_stays_amber_after_a_later_database_success(): void
    {
        BackupRun::query()->create(['source' => 'openwa', 'status' => 'failed', 'triggered_by' => 'system']);
        BackupRun::query()->create(['source' => 'database', 'status' => 'success', 'triggered_by' => 'system']);

        $this->assertSame('amber', $this->attentionItems()['backup_failed']['level']);
    }

    public function test_attention_carries_the_last_paid_order_time(): void
    {
        $this->assertNull($this->dashboard->health()['attention']['last_paid_at']);

        $this->order(['paid_at' => CarbonImmutable::parse('2026-10-10 10:00', 'UTC')]);

        $this->assertSame('2026-10-10T18:00:00+08:00', $this->dashboard->health()['attention']['last_paid_at']);
    }
}
