<?php

namespace Tests\Feature\Services\Fulfillment;

use App\Models\Affiliate;
use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Accounting\SupplierFundingService;
use App\Services\Accounting\SupplierLedgerEntryType;
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
use App\Services\Supplier\SupplierOutcome;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use App\Services\Voucher\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class OrderFulfillmentServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ADR-031: binds $adapter under this test's own default supplier
     * slug and resolves OrderFulfillmentService through the real
     * SupplierAdapterFactory — every other test in this file exercises
     * a single supplier and doesn't care about routing itself (that's
     * test_fulfill_routes_to_the_adapter_bound_for_the_orders_own_supplier's
     * job), so this stays a one-adapter-in, one-service-out helper.
     */
    private function service(SupplierAdapter $adapter): OrderFulfillmentService
    {
        $this->app->bind('supplier-adapter.'.self::DEFAULT_SUPPLIER_SLUG, fn () => $adapter);

        return new OrderFulfillmentService(
            new OrderStatusService,
            new ReferenceNumberService,
            $this->app->make(SupplierAdapterFactory::class),
            new LedgerService,
            new VoucherService(new LedgerService),
            new SupplierFundingService,
        );
    }

    private const DEFAULT_SUPPLIER_SLUG = 'test-supplier';

    private function paidOrder(array $overrides = []): Order
    {
        $supplierId = $overrides['supplier_id']
            ?? Supplier::query()->firstOrCreate(
                ['slug' => self::DEFAULT_SUPPLIER_SLUG],
                ['name' => 'Test Supplier', 'api_config' => [], 'currency' => 'MYR'],
            )->id;

        return Order::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-TEST-1',
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'server_id' => '1234',
            'supplier_id' => $supplierId,
            'supplier_product_ref' => 'FFP5',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
        ], $overrides));
    }

    private function fakeSupplierAdapter(
        bool $success,
        ?array $data = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        // ADR-098 — the generic signal driving NeedsReview routing;
        // defaults false so every pre-existing plain-Failed test keeps
        // its exact prior behavior.
        bool $transactionAlreadyFormed = false,
    ): SupplierAdapter {
        return new class($success, $data, $errorCode, $errorMessage, $transactionAlreadyFormed) implements SupplierAdapter
        {
            public function __construct(
                private readonly bool $success,
                private readonly ?array $data,
                private readonly ?string $errorCode,
                private readonly ?string $errorMessage,
                private readonly bool $transactionAlreadyFormed,
            ) {}

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
                return $this->success
                    ? SupplierResponse::success($this->data)
                    : SupplierResponse::failure($this->errorCode, $this->errorMessage, transactionAlreadyFormed: $this->transactionAlreadyFormed);
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
    }

    /** ADR-032: an async supplier accepted the order but hasn't confirmed the final outcome yet. */
    private function fakePendingSupplierAdapter(array $data = []): SupplierAdapter
    {
        return new class($data) implements SupplierAdapter
        {
            public function __construct(private readonly array $data) {}

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
                return SupplierResponse::pending($this->data);
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
    }

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
     * ORD-8's whole point: a retry after a prior failure must reuse
     * the same idempotency key, never regenerate it.
     */
    public function test_fulfill_reuses_the_same_reference_number_on_a_retry_after_failure(): void
    {
        $order = $this->paidOrder();

        $failed = $this->service($this->fakeSupplierAdapter(false, null, 'timeout', 'Supplier timed out'))
            ->fulfill($order);
        $firstReference = $failed->reference_number;

        $failed->update(['delivery_status' => DeliveryStatus::Failed->value]); // admin retries

        $delivered = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->fulfill($failed->fresh());

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

        $result = $this->service($this->fakeSupplierAdapter(false, null, 'duplicate_reference', 'Gamevion already has an order for this reference number', transactionAlreadyFormed: true))
            ->fulfill($order);

        $this->assertSame(DeliveryStatus::NeedsReview, $result->delivery_status);
        $this->assertSame(PaymentStatus::Paid, $result->payment_status);
        $this->assertSame('duplicate_reference', $result->supplier_response['error_code']);
    }

    /**
     * ADR-098 — the routing decision reads the generic
     * transactionAlreadyFormed flag, not a Gamevion-specific
     * errorCode==='duplicate_reference' string match, so a Digiflazz
     * terminal rc (e.g. '02') routes to NeedsReview too, not just
     * Gamevion's own duplicate_reference case.
     */
    public function test_fulfill_marks_needs_review_on_any_transaction_already_formed_response(): void
    {
        $order = $this->paidOrder();

        $result = $this->service($this->fakeSupplierAdapter(false, null, '02', 'Transaksi Gagal', transactionAlreadyFormed: true))
            ->fulfill($order);

        $this->assertSame(DeliveryStatus::NeedsReview, $result->delivery_status);
        $this->assertSame('02', $result->supplier_response['error_code']);
    }

    /** A plain Digiflazz retriable failure (e.g. rc='44', transactionAlreadyFormed=false) stays a plain Failed. */
    public function test_fulfill_marks_delivery_failed_when_transaction_was_not_formed(): void
    {
        $order = $this->paidOrder();

        $result = $this->service($this->fakeSupplierAdapter(false, null, '44', 'Saldo tidak cukup'))
            ->fulfill($order);

        $this->assertSame(DeliveryStatus::Failed, $result->delivery_status);
    }

    public function test_fulfill_logs_a_distinct_warning_for_needs_review(): void
    {
        Log::spy();
        $order = $this->paidOrder();

        $this->service($this->fakeSupplierAdapter(false, null, 'duplicate_reference', 'dup', transactionAlreadyFormed: true))
            ->fulfill($order);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Delivery ambiguous — needs manual review', [
                'error_code' => 'duplicate_reference',
                'error_message' => 'dup',
            ]);
    }

    public function test_mark_delivered_manually_transitions_from_needs_review_and_sets_supplier_ref(): void
    {
        $order = $this->paidOrder(['delivery_status' => DeliveryStatus::NeedsReview->value]);

        $result = $this->service($this->fakeSupplierAdapter(true))
            ->markDeliveredManually($order, 'GV-RAPI-MANUAL1', 'confirmed via Gamevion dashboard', 'Jane Admin');

        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
        $this->assertSame('GV-RAPI-MANUAL1', $result->supplier_ref);
        $this->assertTrue($result->supplier_response['manually_confirmed']);
        $this->assertSame('Jane Admin', $result->supplier_response['confirmed_by']);
        $this->assertSame('confirmed via Gamevion dashboard', $result->supplier_response['note']);
        $this->assertNotNull($result->delivered_at);
    }

    public function test_mark_delivered_manually_credits_ledger_profit(): void
    {
        $order = $this->paidOrder([
            'delivery_status' => DeliveryStatus::NeedsReview->value,
            'platform_profit' => 150,
            'affiliate_profit' => 50,
        ]);

        $this->service($this->fakeSupplierAdapter(true))
            ->markDeliveredManually($order, 'GV-RAPI-MANUAL2', null, 'Jane Admin');

        $this->assertSame(150, (int) LedgerEntry::query()->where('owner_type', 'platform')->sum('amount'));
        $this->assertSame(50, (int) LedgerEntry::query()->where('owner_type', 'affiliate')->sum('amount'));
    }

    /**
     * ADR-026 decision 4a: deliberately only reachable from
     * needs_review — no other state lets an admin's own claim
     * substitute for a real supplier confirmation.
     */
    public function test_mark_delivered_manually_rejects_when_not_needs_review(): void
    {
        $order = $this->paidOrder(['delivery_status' => DeliveryStatus::Failed->value]);

        $this->expectException(InvalidOrderTransitionException::class);

        $this->service($this->fakeSupplierAdapter(true))
            ->markDeliveredManually($order, 'GV-RAPI-MANUAL3', null, 'Jane Admin');
    }

    /**
     * ADR-026 addendum (2026-09-16, found shipping ADR-098) — the exit
     * that lets a genuinely-unresolvable needs_review order (a
     * transactionAlreadyFormed Digiflazz rc, retry can never change it)
     * reach Failed, unlocking Issue Voucher (Failed-only gate,
     * unchanged). Preserves the original error_code/message instead of
     * overwriting it — that's the actual evidence this failed.
     */
    public function test_confirm_delivery_failed_transitions_from_needs_review_and_preserves_original_error(): void
    {
        $order = $this->paidOrder([
            'delivery_status' => DeliveryStatus::NeedsReview->value,
            'supplier_response' => ['error_code' => '02', 'error_message' => 'Transaksi Gagal'],
        ]);

        $result = $this->service($this->fakeSupplierAdapter(true))
            ->confirmDeliveryFailed($order, 'Confirmed dead via request logs — same rc replayed 3x.', 'Jane Admin');

        $this->assertSame(DeliveryStatus::Failed, $result->delivery_status);
        $this->assertSame('02', $result->supplier_response['error_code']);
        $this->assertSame('Transaksi Gagal', $result->supplier_response['error_message']);
        $this->assertSame('Jane Admin', $result->supplier_response['confirmed_failed_by']);
        $this->assertSame('Confirmed dead via request logs — same rc replayed 3x.', $result->supplier_response['note']);
    }

    public function test_confirm_delivery_failed_rejects_when_not_needs_review(): void
    {
        $order = $this->paidOrder(['delivery_status' => DeliveryStatus::Failed->value]);

        $this->expectException(InvalidOrderTransitionException::class);

        $this->service($this->fakeSupplierAdapter(true))
            ->confirmDeliveryFailed($order, 'note', 'Jane Admin');
    }

    /** Unblocks the whole point of this action: Issue Voucher works once the order is genuinely Failed. */
    public function test_confirm_delivery_failed_then_unblocks_voucher_issuance(): void
    {
        $order = $this->paidOrder(['delivery_status' => DeliveryStatus::NeedsReview->value]);

        $result = $this->service($this->fakeSupplierAdapter(true))
            ->confirmDeliveryFailed($order, 'note', 'Jane Admin');

        $this->assertSame(DeliveryStatus::Failed, $result->delivery_status);
        $this->assertSame(0, Voucher::query()->where('order_id', $order->id)->count());
    }

    /**
     * ADR-032 decision 3: the webhook/poll-driven money path out of
     * Pending — reached via createOrder()'s async acceptance, not a
     * synchronous fulfill() call.
     */
    public function test_finalize_pending_delivery_marks_delivered_and_credits_ledger_on_success(): void
    {
        $order = $this->paidOrder([
            'delivery_status' => DeliveryStatus::Pending->value,
            'platform_profit' => 150,
            'affiliate_profit' => 50,
        ]);

        $result = $this->service($this->fakeSupplierAdapter(true))
            ->finalizePendingDelivery($order, SupplierOutcome::Success, 'DGFLZ-FINAL-1', ['status' => 'Sukses']);

        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
        $this->assertSame('DGFLZ-FINAL-1', $result->supplier_ref);
        $this->assertSame(['status' => 'Sukses'], $result->supplier_response);
        $this->assertSame(150, (int) LedgerEntry::query()->where('owner_type', 'platform')->sum('amount'));
        $this->assertSame(50, (int) LedgerEntry::query()->where('owner_type', 'affiliate')->sum('amount'));
        $this->assertNotNull($result->delivered_at);
    }

    /**
     * ADR-032 decision 7: a Pending order finalized as Failed lands on
     * the same Failed state a synchronous rejection would — no ledger
     * effect, and the existing Failed-only voucher gate applies
     * unchanged (no separate double-compensation guard needed here).
     */
    public function test_finalize_pending_delivery_marks_failed_and_skips_ledger_on_failure(): void
    {
        $order = $this->paidOrder(['delivery_status' => DeliveryStatus::Pending->value]);

        $result = $this->service($this->fakeSupplierAdapter(true))
            ->finalizePendingDelivery($order, SupplierOutcome::Failure, null, ['status' => 'Gagal']);

        $this->assertSame(DeliveryStatus::Failed, $result->delivery_status);
        $this->assertSame(['status' => 'Gagal'], $result->supplier_response);
        $this->assertSame(PaymentStatus::Paid, $result->payment_status);
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_finalize_pending_delivery_commits_a_reserved_voucher_redemption_on_success(): void
    {
        $voucher = Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'code' => 'VC-TESTPENDINGCOMMIT',
            'customer_email' => 'buyer@example.com',
            'amount' => 1000,
            'remaining' => 500,
            'status' => 'active',
            'reason' => 'test',
        ]);
        $order = $this->paidOrder([
            'delivery_status' => DeliveryStatus::Pending->value,
            'voucher_id' => $voucher->id,
            'voucher_discount' => 500,
        ]);
        VoucherRedemption::query()->create([
            'voucher_id' => $voucher->id,
            'order_id' => $order->id,
            'amount' => 500,
            'status' => 'reserved',
        ]);

        $this->service($this->fakeSupplierAdapter(true))
            ->finalizePendingDelivery($order, SupplierOutcome::Success, 'DGFLZ-FINAL-2');

        $this->assertSame('committed', VoucherRedemption::query()->where('order_id', $order->id)->value('status'));
    }

    /**
     * Idempotency: a second finalize (duplicate webhook delivery, or a
     * webhook racing the poll) observes the already-advanced state and
     * is rejected — OrderStatusService's own guard, not a re-check
     * this method has to duplicate.
     */
    public function test_finalize_pending_delivery_rejects_when_not_pending(): void
    {
        $order = $this->paidOrder(['delivery_status' => DeliveryStatus::Delivered->value]);

        $this->expectException(InvalidOrderTransitionException::class);

        $this->service($this->fakeSupplierAdapter(true))
            ->finalizePendingDelivery($order, SupplierOutcome::Success, 'DGFLZ-FINAL-3');
    }

    public function test_finalize_pending_delivery_rejects_being_called_with_outcome_pending(): void
    {
        $order = $this->paidOrder(['delivery_status' => DeliveryStatus::Pending->value]);

        $this->expectException(OrderFulfillmentException::class);

        $this->service($this->fakeSupplierAdapter(true))
            ->finalizePendingDelivery($order, SupplierOutcome::Pending);
    }

    /**
     * ADR-083 decision 3: a synchronous Success (Gamevion always, or a
     * Digiflazz order that never went Pending) writes ORDER_DRAWDOWN
     * from the adapter's own `price` — AFTER fulfill()'s transaction
     * commits, never from `orders.cost_price`.
     */
    public function test_fulfill_records_a_supplier_ledger_drawdown_when_the_adapter_reports_a_price(): void
    {
        $order = $this->paidOrder();

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1', 'price' => 850.0]))
            ->fulfill($order);

        $entry = SupplierLedgerEntry::query()->sole();
        $this->assertSame(SupplierLedgerEntryType::OrderDrawdown->value, $entry->type);
        $this->assertSame('-850.0000', $entry->amount);
        $this->assertSame('MYR', $entry->currency);
        $this->assertSame('order', $entry->reference_type);
        $this->assertSame($order->id, $entry->reference_id);
    }

    /** A Pending response never carries a price — nothing to record yet. */
    public function test_fulfill_records_no_drawdown_when_the_adapter_reports_no_price(): void
    {
        $order = $this->paidOrder();

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-2']))
            ->fulfill($order);

        $this->assertSame(0, SupplierLedgerEntry::query()->count());
    }

    /** A Pending order accepted (but not yet finalized) records no drawdown either. */
    public function test_fulfill_records_no_drawdown_on_a_pending_response(): void
    {
        $order = $this->paidOrder();

        $this->service($this->fakePendingSupplierAdapter())->fulfill($order);

        $this->assertSame(0, SupplierLedgerEntry::query()->count());
    }

    /**
     * ADR-083 decision 3: the Digiflazz webhook (or the reconcile poll's
     * own checkStatus() re-submit) is where a Pending order's real
     * `price` first becomes known — this is the only place its drawdown
     * gets recorded.
     */
    public function test_finalize_pending_delivery_records_a_supplier_ledger_drawdown_on_success(): void
    {
        $order = $this->paidOrder(['delivery_status' => DeliveryStatus::Pending->value]);

        $this->service($this->fakeSupplierAdapter(true))
            ->finalizePendingDelivery($order, SupplierOutcome::Success, 'DGFLZ-DRAWDOWN-1', ['status' => 'Sukses', 'price' => 15000.0]);

        $entry = SupplierLedgerEntry::query()->sole();
        $this->assertSame(SupplierLedgerEntryType::OrderDrawdown->value, $entry->type);
        $this->assertSame('-15000.0000', $entry->amount);
        $this->assertSame('order', $entry->reference_type);
        $this->assertSame($order->id, $entry->reference_id);
    }

    /**
     * Grilled 2026-09-11 (ADR-083): a Gagal after Pending writes NO
     * REFUND — a Pending response never carried a price, so nothing was
     * ever recorded as drawn down for it in the first place.
     */
    public function test_finalize_pending_delivery_records_no_supplier_ledger_entry_on_failure(): void
    {
        $order = $this->paidOrder(['delivery_status' => DeliveryStatus::Pending->value]);

        $this->service($this->fakeSupplierAdapter(true))
            ->finalizePendingDelivery($order, SupplierOutcome::Failure, null, ['status' => 'Gagal', 'buyer_last_saldo' => 500000]);

        $this->assertSame(0, SupplierLedgerEntry::query()->count());
    }

    /**
     * Defense-in-depth (ADR-083 decision 2's own append-only guard is
     * the primary one): SupplierFundingService::recordOrderDrawdown()
     * itself won't double-write for the same order even if called twice.
     */
    public function test_record_order_drawdown_is_idempotent_per_order(): void
    {
        $order = $this->paidOrder();
        $funding = app(SupplierFundingService::class);

        $funding->recordOrderDrawdown($order, 850.0);
        $funding->recordOrderDrawdown($order, 850.0);

        $this->assertSame(1, SupplierLedgerEntry::query()->count());
    }
}
