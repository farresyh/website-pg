<?php

namespace Tests\Feature\Services\Fulfillment;

use App\Jobs\CheckSupplierDeliveryJob;
use App\Models\Affiliate;
use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderResendAttempt;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Accounting\SupplierFundingService;
use App\Services\Currency\CurrencyRateService;
use App\Services\Fulfillment\OrderFulfillmentException;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\InvalidOrderTransitionException;
use App\Services\Order\OrderStatusService;
use App\Services\Order\PaymentStatus;
use App\Services\Order\ReferenceNumberService;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use App\Services\Voucher\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\OrderFulfillmentFixtures;
use Tests\TestCase;

/** This file: fulfill() itself: routing, outcomes, references, attempt rows and the ledger credit. */
class OrderFulfillmentServiceTest extends TestCase
{
    use OrderFulfillmentFixtures;
    use RefreshDatabase;

    /**
     * ADR-031: fulfill() resolves the adapter to call by the order's
     * own supplier_id, through SupplierAdapterFactory — never a single
     * globally-injected adapter. Two suppliers bound, order points at
     * one of them; the other must never be touched.
     */
    public function test_fulfill_routes_to_the_adapter_bound_for_the_orders_own_supplier(): void
    {
        $supplierA = Supplier::query()->create(['name' => 'Supplier A', 'slug' => 'supplier-a', 'api_config' => [], 'currency' => 'MYR']);
        $supplierB = Supplier::query()->create(['name' => 'Supplier B', 'slug' => 'supplier-b', 'api_config' => [], 'currency' => 'MYR']);

        $this->app->bind("supplier-adapter.{$supplierA->slug}", fn () => $this->fakeSupplierAdapter(true, ['supplier_ref' => 'FROM-A']));
        $this->app->bind("supplier-adapter.{$supplierB->slug}", fn () => new class implements SupplierAdapter
        {
            public function checkBalance(): SupplierResponse
            {
                throw new RuntimeException('supplier B must never be called for a supplier-A order');
            }

            public function listProducts(): SupplierResponse
            {
                throw new RuntimeException('supplier B must never be called for a supplier-A order');
            }

            public function createOrder(SupplierOrderRequest $request): SupplierResponse
            {
                throw new RuntimeException('supplier B must never be called for a supplier-A order');
            }

            public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
            {
                throw new RuntimeException('supplier B must never be called for a supplier-A order');
            }

            public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
            {
                throw new ValidationNotSupportedException('not used in this test');
            }
        });

        $order = $this->paidOrder(['supplier_id' => $supplierA->id]);

        $service = new OrderFulfillmentService(
            new OrderStatusService,
            new ReferenceNumberService,
            $this->app->make(SupplierAdapterFactory::class),
            new LedgerService,
            new VoucherService(new LedgerService),
            new SupplierFundingService,
            new CurrencyRateService,
        );

        $result = $service->fulfill($order);

        $this->assertSame('FROM-A', $result->supplier_ref);
    }

    /**
     * ORD-11's central guard: the single most direct path to giving
     * away free game credits without confirmed payment.
     */
    public function test_fulfill_rejects_when_payment_is_not_paid(): void
    {
        $order = $this->paidOrder(['payment_status' => PaymentStatus::Pending->value]);

        $this->expectException(InvalidOrderTransitionException::class);

        $this->service($this->fakeSupplierAdapter(true))->fulfill($order);
    }

    /**
     * ADR-102 decision 1: the real, final defense-in-depth check —
     * inside `fulfill()`'s own row lock, not just the controller's
     * pre-check or `OrderResendService::assertResendable()`'s
     * job-time re-check. Proves a resend can never deliver on top of
     * a compensation voucher already issued, even if every guard
     * upstream of this method were somehow bypassed or raced.
     */
    public function test_fulfill_rejects_an_order_with_an_already_issued_voucher(): void
    {
        $order = $this->paidOrder(['delivery_status' => DeliveryStatus::Failed->value]);
        Voucher::query()->create([
            'order_id' => $order->id,
            'affiliate_id' => $order->affiliate_id,
            'code' => 'KRS-FULFILL-GUARD',
            'customer_email' => 'buyer@example.com',
            'amount' => 500,
            'remaining' => 500,
            'status' => 'active',
            'reason' => 'test',
        ]);

        $this->expectException(OrderFulfillmentException::class);

        $this->service($this->fakeSupplierAdapter(true))->fulfill($order);
    }

    /**
     * Fails fast with a clear internal error instead of silently
     * sending an empty product_code to the supplier.
     */
    public function test_fulfill_rejects_when_supplier_product_ref_is_missing(): void
    {
        $order = $this->paidOrder(['supplier_product_ref' => null]);

        $this->expectException(OrderFulfillmentException::class);

        $this->service($this->fakeSupplierAdapter(true))->fulfill($order);
    }

    public function test_fulfill_generates_reference_number_and_submits_to_supplier(): void
    {
        $order = $this->paidOrder();

        $adapter = $this->fakeSupplierAdapter(true, [
            'supplier_ref' => 'GV-RAPI-1A2B3C4D5E6F',
            'game' => 'Free Fire',
        ]);

        $result = $this->service($adapter)->fulfill($order);

        $this->assertStringStartsWith('REF-', $result->reference_number);
        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
        $this->assertSame('GV-RAPI-1A2B3C4D5E6F', $result->supplier_ref);
        $this->assertNotNull($result->delivered_at);
    }

    /**
     * ADR-097 decision 15 — fulfill() resolves the order's own Game's
     * `validation_rules['customer_no_separator']` override and forwards
     * it on the SupplierOrderRequest; the adapter that never reads it
     * (any non-Digiflazz supplier) just ignores it, but the value must
     * still reach it correctly.
     */
    public function test_fulfill_forwards_the_games_customer_no_separator_override_to_the_adapter(): void
    {
        $game = Game::query()->create([
            'name' => 'Test Game', 'slug' => 'test-game-'.uniqid(),
            'validation_rules' => ['customer_no_separator' => 'space'],
        ]);
        $order = $this->paidOrder(['game_id' => $game->id]);

        $captured = null;
        $adapter = new class($captured) implements SupplierAdapter
        {
            public ?SupplierOrderRequest $captured = null;

            public function __construct(&$captured) {}

            public function checkBalance(): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function listProducts(): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function createOrder(SupplierOrderRequest $request): SupplierResponse
            {
                $this->captured = $request;

                return SupplierResponse::success(['supplier_ref' => 'SEP-TEST']);
            }

            public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
            {
                throw new ValidationNotSupportedException('not used in this test');
            }
        };

        $this->service($adapter)->fulfill($order);

        $this->assertSame('space', $adapter->captured?->customerNoSeparator);
    }

    /**
     * PRD §8 LedgerEntry: every delivered order writes exactly two
     * order_profit credit entries, even in MVP where both currently
     * resolve to the same internal owner.
     */
    public function test_fulfill_credits_platform_and_affiliate_profit_on_delivery(): void
    {
        $order = $this->paidOrder(['platform_profit' => 150, 'affiliate_profit' => 50]);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))->fulfill($order);

        $this->assertSame(150, (int) LedgerEntry::query()->where('owner_type', 'platform')->sum('amount'));
        $this->assertSame(50, (int) LedgerEntry::query()->where('owner_type', 'affiliate')->sum('amount'));
    }

    /**
     * ADR-060 PR-4c: an `affiliate`-basis order (a real third-party
     * branded storefront sale) splits its frozen profit between the
     * Platform (wholesale − cost) and the affiliate's OWN ledger account
     * (their margin) — `creditProfit()` books the affiliate credit to
     * `owner_id = order.affiliate_id`, not the primary.
     */
    public function test_fulfill_credits_a_third_party_affiliates_own_ledger_account(): void
    {
        $brand = Affiliate::query()->create(['business_name' => 'Acme Resell', 'markup_pct' => 10]);
        $order = $this->paidOrder([
            'affiliate_id' => $brand->id,
            'pricing_basis' => 'affiliate',
            'platform_profit' => 200,
            'affiliate_profit' => 120,
            'wholesale_markup_pct' => 20,
        ]);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))->fulfill($order);

        $this->assertSame(200, (int) LedgerEntry::query()
            ->where('owner_type', 'platform')->where('owner_id', null)->sum('amount'));
        $this->assertSame(120, (int) LedgerEntry::query()
            ->where('owner_type', 'affiliate')->where('owner_id', $brand->id)->sum('amount'));
    }

    /**
     * ADR-032: an async supplier's Pending response is neither a
     * clean delivery nor a failure — no ledger credit, no voucher
     * commit, order parked in Pending until finalizePendingDelivery()
     * resolves it later (webhook or poll).
     */
    public function test_fulfill_marks_pending_on_a_pending_supplier_response(): void
    {
        $order = $this->paidOrder(['platform_profit' => 150, 'affiliate_profit' => 50]);

        $result = $this->service($this->fakePendingSupplierAdapter(['trx_id' => 'DGFLZ-1']))->fulfill($order);

        $this->assertSame(DeliveryStatus::Pending, $result->delivery_status);
        $this->assertSame(PaymentStatus::Paid, $result->payment_status);
        $this->assertSame(['trx_id' => 'DGFLZ-1'], $result->supplier_response);
        $this->assertNull($result->supplier_ref);
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    /**
     * ADR-106 decision 3 — a genuinely first-ever fulfillment attempt
     * (order starts at NotStarted) now writes a durable
     * `attempt_type=initial` row, closing the gap where the admin's
     * "Initial Delivery" row was previously synthesized live from
     * mutable Order columns and permanently lost the moment any resend
     * happened. price_diff_sen is null (decision 4) — no live-cost
     * comparison is ever made on a first attempt.
     */
    public function test_fulfill_writes_a_durable_initial_attempt_row_on_success(): void
    {
        $order = $this->paidOrder(['cost_price' => 900, 'standard_selling_price' => 950]);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))->fulfill($order);

        $attempt = OrderResendAttempt::query()->where('order_id', $result->id)->sole();
        $this->assertSame('initial', $attempt->attempt_type);
        $this->assertSame('success', $attempt->outcome);
        $this->assertSame($order->package_id, $attempt->package_id);
        $this->assertSame(900, $attempt->cost_price_sen);
        $this->assertSame(950, $attempt->standard_selling_price_sen);
        $this->assertNull($attempt->price_diff_sen);
        $this->assertNull($attempt->triggered_by);
    }

    public function test_fulfill_writes_a_durable_initial_attempt_row_on_failure(): void
    {
        $order = $this->paidOrder();

        $result = $this->service($this->fakeSupplierAdapter(false, null, 'insufficient_balance', 'No balance'))
            ->fulfill($order);

        $attempt = OrderResendAttempt::query()->where('order_id', $result->id)->sole();
        $this->assertSame('initial', $attempt->attempt_type);
        $this->assertSame('failed', $attempt->outcome);
    }

    public function test_fulfill_writes_a_durable_initial_attempt_row_on_pending(): void
    {
        $order = $this->paidOrder();

        $result = $this->service($this->fakePendingSupplierAdapter(['trx_id' => 'DGFLZ-1']))->fulfill($order);

        $attempt = OrderResendAttempt::query()->where('order_id', $result->id)->sole();
        $this->assertSame('initial', $attempt->attempt_type);
        $this->assertSame('pending', $attempt->outcome);
    }

    /**
     * A retry (order not starting at NotStarted) must never write a
     * SECOND 'initial' row — $wasNotStarted is computed once, before
     * this call's own update() advances the order, so a retry from
     * Failed correctly sees it as false.
     *
     * ADR-106 addendum (2026-09-21): a retry is no longer silent either
     * — it now writes its own `attempt_type=retry` row, closing the
     * exact gap `docs/prd.md` §16 item 16 tracked (a retryDelivery()
     * call previously left zero durable trace for combo OR plain
     * orders alike, since both share this same FulfillOrderJob/
     * fulfill() path).
     */
    public function test_fulfill_does_not_write_a_second_initial_attempt_row_on_a_retry(): void
    {
        $order = $this->paidOrder();

        $failed = $this->service($this->fakeSupplierAdapter(false, null, 'timeout', 'Supplier timed out'))
            ->fulfill($order);
        $this->assertSame(1, OrderResendAttempt::query()->where('order_id', $failed->id)->count());

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))->fulfill($failed->fresh());

        $this->assertSame(1, OrderResendAttempt::query()->where('order_id', $failed->id)->where('attempt_type', 'initial')->count());
        $this->assertSame(1, OrderResendAttempt::query()->where('order_id', $failed->id)->where('attempt_type', 'retry')->count());
        $this->assertSame(2, OrderResendAttempt::query()->where('order_id', $failed->id)->count());
    }

    /**
     * ADR-106 addendum (2026-09-21) — a plain (non-combo) retry writes
     * a durable `attempt_type=retry` row, with `triggered_by`/`note`
     * threaded all the way from `OrderController::retryDelivery()`
     * through `FulfillOrderJob`, exactly mirroring how `resend()`
     * already threads them for a package-swap resend.
     */
    public function test_fulfill_writes_a_retry_attempt_row_with_triggered_by_and_note(): void
    {
        $order = $this->paidOrder();

        $failed = $this->service($this->fakeSupplierAdapter(false, null, 'timeout', 'Supplier timed out'))
            ->fulfill($order);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->fulfill($failed->fresh(), 'Admin User', 'Retried after supplier outage cleared');

        $attempt = OrderResendAttempt::query()->where('order_id', $result->id)->where('attempt_type', 'retry')->sole();
        $this->assertSame('Admin User', $attempt->triggered_by);
        $this->assertSame('Retried after supplier outage cleared', $attempt->note);
        $this->assertSame('success', $attempt->outcome);
        $this->assertNull($attempt->order_delivery_leg_id);
    }

    /**
     * A system-triggered retry (scheduled reconciliation, no admin
     * involved) must still leave a row — just with a null
     * `triggered_by`, distinguishing it from an admin-clicked one.
     */
    public function test_fulfill_writes_a_retry_attempt_row_with_null_triggered_by_when_system_triggered(): void
    {
        $order = $this->paidOrder();

        $failed = $this->service($this->fakeSupplierAdapter(false, null, 'timeout', 'Supplier timed out'))
            ->fulfill($order);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))->fulfill($failed->fresh());

        $attempt = OrderResendAttempt::query()->where('order_id', $result->id)->where('attempt_type', 'retry')->sole();
        $this->assertNull($attempt->triggered_by);
        $this->assertNull($attempt->note);
    }

    /**
     * ADR-018 decision #6: the single, explicit guard that keeps a
     * sandbox order from ever reaching the real ledger, even though
     * every other line of fulfill() runs completely unchanged against
     * it (status transitions, reference_number, supplier_response).
     */
    public function test_fulfill_skips_ledger_credit_for_a_test_order(): void
    {
        $order = $this->paidOrder(['is_test' => true, 'platform_profit' => 150, 'affiliate_profit' => 50]);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'SANDBOX-1']))->fulfill($order);

        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    /**
     * Money genuinely received (payment_status stays Paid) — only
     * delivery didn't complete. No cash refund exists (ADR-004); this
     * needs admin resolution (retry or voucher), never silent auto-fix.
     */
    public function test_fulfill_marks_delivery_failed_and_keeps_payment_paid_when_supplier_rejects(): void
    {
        $order = $this->paidOrder();

        $result = $this->service($this->fakeSupplierAdapter(false, null, 'insufficient_balance', 'Supplier balance too low'))
            ->fulfill($order);

        $this->assertSame(DeliveryStatus::Failed, $result->delivery_status);
        $this->assertSame(PaymentStatus::Paid, $result->payment_status);
        $this->assertSame('insufficient_balance', $result->supplier_response['error_code']);
    }

    /**
     * ORD-8, narrowed by ADR-102 decision 9: a retry initiated from
     * Failed now gets a FRESH reference — deliberate, since Failed
     * (post decision 4) always means confirmed non-delivery, so there's
     * no double-delivery risk a fresh reference could create, and it
     * makes a package swap (ADR-017) genuinely effective again (a
     * resubmitted already-formed reference would otherwise just replay
     * the stored result). This supersedes the old "never regenerate"
     * behavior for exactly this case — see the NeedsReview sibling test
     * below for the case where reuse is still the deliberate safety
     * mechanism.
     */
    /**
     * 2026-09-28 audit finding M-1: createOrder() used to run inside the
     * SAME transaction that generated and persisted reference_number, so
     * a thrown exception (a real supplier timeout — ConnectionException —
     * included) rolled EVERYTHING back, including the reference. A retry
     * then minted a brand-new reference via resolve(null), and if the
     * supplier had actually processed the first, now-orphaned attempt,
     * the order could be delivered twice. Proves the reference survives
     * the throw and the order lands on NeedsReview (not stranded, not
     * silently rolled back to NotStarted).
     */
    public function test_fulfill_persists_the_reference_number_and_moves_to_needs_review_when_the_supplier_call_throws(): void
    {
        $order = $this->paidOrder();

        $adapter = $this->throwingSupplierAdapter(
            new ConnectionException('Connection timed out'),
        );

        $result = $this->service($adapter)->fulfill($order);

        $this->assertSame(DeliveryStatus::NeedsReview, $result->delivery_status);
        $this->assertNotNull($result->reference_number);
        $this->assertStringStartsWith('REF-', $result->reference_number);
        $this->assertSame(PaymentStatus::Paid, $result->payment_status);
    }

    /**
     * The actual money-critical guarantee M-1 closes: once the order is
     * on NeedsReview after a throw, a retry must reuse the SAME
     * reference — never mint a fresh one — so the supplier's own dedup
     * on ref_id is what protects against a real double delivery if the
     * first, exception-interrupted attempt actually reached them.
     */
    public function test_fulfill_reuses_the_persisted_reference_on_retry_after_a_supplier_exception(): void
    {
        $order = $this->paidOrder();

        $needsReview = $this->service($this->throwingSupplierAdapter(
            new ConnectionException('Connection timed out'),
        ))->fulfill($order);
        $firstReference = $needsReview->reference_number;

        $delivered = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->fulfill($needsReview->fresh());

        $this->assertSame($firstReference, $delivered->reference_number);
        $this->assertSame(DeliveryStatus::Delivered, $delivered->delivery_status);
    }

    public function test_a_digiflazz_exception_goes_to_pending_and_schedules_a_same_ref_poll(): void
    {
        Queue::fake();
        [$order, $service] = $this->digiflazzOrder($this->throwingSupplierAdapter(
            new ConnectionException('Connection timed out'),
        ));

        $result = $service->fulfill($order);

        $this->assertSame(DeliveryStatus::Pending, $result->delivery_status);
        $this->assertNotNull($result->reference_number);
        $this->assertArrayHasKey('auto_recovery', $result->supplier_response);
        Queue::assertPushed(CheckSupplierDeliveryJob::class, fn ($job) => $job->order->id === $order->id && $job->delay !== null);
    }

    public function test_a_digiflazz_circuit_open_goes_to_pending_not_needs_review(): void
    {
        Queue::fake();
        [$order, $service] = $this->digiflazzOrder($this->fakeSupplierAdapter(false, null, 'CIRCUIT_OPEN', 'breaker open', resendUnsafeWithSameReference: true));

        $this->assertSame(DeliveryStatus::Pending, $service->fulfill($order)->delivery_status);
    }

    public function test_a_digiflazz_confirmed_gagal_still_fails(): void
    {
        Queue::fake();
        [$order, $service] = $this->digiflazzOrder($this->fakeSupplierAdapter(false, null, '02', 'Gagal', resendUnsafeWithSameReference: true, outcomeConfirmedFailed: true));

        $this->assertSame(DeliveryStatus::Failed, $service->fulfill($order)->delivery_status);
        Queue::assertNotPushed(CheckSupplierDeliveryJob::class);
    }

    public function test_fulfill_generates_a_fresh_reference_number_on_a_retry_from_failed(): void
    {
        $order = $this->paidOrder();

        $failed = $this->service($this->fakeSupplierAdapter(false, null, 'timeout', 'Supplier timed out'))
            ->fulfill($order);
        $firstReference = $failed->reference_number;

        $this->assertSame(DeliveryStatus::Failed, $failed->delivery_status); // admin retries from here

        $delivered = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->fulfill($failed->fresh());

        $this->assertNotSame($firstReference, $delivered->reference_number);
        $this->assertStringStartsWith('REF-', $delivered->reference_number);
    }

    /**
     * ADR-102 decision 9 — the deliberate carve-out: a retry initiated
     * from NeedsReview keeps reusing the SAME reference, exactly as
     * ORD-8 always specified. Reuse IS the safety mechanism here — if a
     * genuinely-unknown-outcome order actually did succeed silently,
     * the supplier's own dedup on the same reference prevents a real
     * second delivery. Regenerating here would remove that protection.
     */
    public function test_fulfill_reuses_the_same_reference_number_on_a_retry_from_needs_review(): void
    {
        $order = $this->paidOrder();

        $needsReview = $this->service($this->fakeSupplierAdapter(false, null, 'duplicate_reference', 'dup', resendUnsafeWithSameReference: true))
            ->fulfill($order);
        $firstReference = $needsReview->reference_number;

        $this->assertSame(DeliveryStatus::NeedsReview, $needsReview->delivery_status); // admin retries from here

        $delivered = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->fulfill($needsReview->fresh());

        $this->assertSame($firstReference, $delivered->reference_number);
    }

    /**
     * ADR-014: a business-level delivery failure never throws (see
     * this class's own doc comment) — without an explicit log line, it
     * would be silent to a file-log admin until someone checks the
     * Admin Orders screen. Proves the line exists and carries the
     * error detail, with reference_number already in the shared log
     * context by this point.
     */
    public function test_fulfill_logs_a_warning_when_delivery_fails(): void
    {
        Log::spy();
        $order = $this->paidOrder();

        $this->service($this->fakeSupplierAdapter(false, null, 'timeout', 'Supplier timed out'))
            ->fulfill($order);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Delivery failed', [
                'error_code' => 'timeout',
                'error_message' => 'Supplier timed out',
            ]);
    }

    /**
     * ADR-024 decision #6 — the third and final outcome of the
     * voucher-redemption three-outcome model: both payment and
     * delivery succeeded, so a reserved redemption becomes committed
     * (permanent), never restored.
     */
    public function test_fulfill_commits_a_reserved_voucher_redemption_on_delivery(): void
    {
        $voucher = Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'code' => 'VC-TESTCOMMIT',
            'customer_email' => 'buyer@example.com',
            'amount' => 1000,
            'remaining' => 500,
            'status' => 'active',
            'reason' => 'test',
        ]);

        $order = $this->paidOrder(['voucher_id' => $voucher->id, 'voucher_discount' => 500]);

        VoucherRedemption::query()->create([
            'voucher_id' => $voucher->id,
            'order_id' => $order->id,
            'amount' => 500,
            'status' => 'reserved',
        ]);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))->fulfill($order);

        $this->assertSame('committed', VoucherRedemption::query()->where('order_id', $order->id)->value('status'));
        // Committing must never touch the voucher's own remaining balance.
        $this->assertSame(500, $voucher->fresh()->remaining);
    }

    /**
     * ADR-026: duplicate_reference is Gamevion's own idempotency signal
     * that a prior attempt for this reference_number already reached
     * them — structurally different from every other failure, so it
     * must route to needs_review, never a plain failed.
     */
    public function test_fulfill_marks_needs_review_on_a_duplicate_reference_response(): void
    {
        $order = $this->paidOrder();

        $result = $this->service($this->fakeSupplierAdapter(false, null, 'duplicate_reference', 'Gamevion already has an order for this reference number', resendUnsafeWithSameReference: true))
            ->fulfill($order);

        $this->assertSame(DeliveryStatus::NeedsReview, $result->delivery_status);
        $this->assertSame(PaymentStatus::Paid, $result->payment_status);
        $this->assertSame('duplicate_reference', $result->supplier_response['error_code']);
    }

    /**
     * ADR-102 decision 4 — the actual fix: a Digiflazz terminal rc
     * (e.g. '02', "Terbentuk Transaksi=Ya") is unsafe to resubmit but
     * Digiflazz's own `status` field DID confirm the outcome (Gagal) —
     * a known result, not an ambiguous one. This now routes straight
     * to Failed (Issue Voucher immediately available), superseding
     * ADR-098 decision 6's original "any already-formed response routes
     * to NeedsReview" rule, which conflated "can't safely resubmit"
     * with "outcome unknown". Only Gamevion's duplicate_reference (no
     * status field to confirm anything) still routes to NeedsReview —
     * see the sibling test above.
     */
    public function test_fulfill_marks_delivery_failed_on_a_confirmed_gagal_digiflazz_rc(): void
    {
        $order = $this->paidOrder();

        $result = $this->service($this->fakeSupplierAdapter(false, null, '02', 'Transaksi Gagal', resendUnsafeWithSameReference: true, outcomeConfirmedFailed: true))
            ->fulfill($order);

        $this->assertSame(DeliveryStatus::Failed, $result->delivery_status);
        $this->assertSame('02', $result->supplier_response['error_code']);
    }

    /** A plain Digiflazz retriable failure (e.g. rc='44') stays a plain Failed — unaffected by decision 4, unchanged from before this ADR. */
    public function test_fulfill_marks_delivery_failed_when_resend_is_not_unsafe(): void
    {
        $order = $this->paidOrder();

        $result = $this->service($this->fakeSupplierAdapter(false, null, '44', 'Saldo tidak cukup', outcomeConfirmedFailed: true))
            ->fulfill($order);

        $this->assertSame(DeliveryStatus::Failed, $result->delivery_status);
    }

    public function test_fulfill_logs_a_distinct_warning_for_needs_review(): void
    {
        Log::spy();
        $order = $this->paidOrder();

        $this->service($this->fakeSupplierAdapter(false, null, 'duplicate_reference', 'dup', resendUnsafeWithSameReference: true))
            ->fulfill($order);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Delivery ambiguous — needs manual review', [
                'error_code' => 'duplicate_reference',
                'error_message' => 'dup',
            ]);
    }
}
