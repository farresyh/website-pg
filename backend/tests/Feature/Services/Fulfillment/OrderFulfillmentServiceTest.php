<?php

namespace Tests\Feature\Services\Fulfillment;

use App\Models\LedgerEntry;
use App\Models\Order;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\InvalidOrderTransitionException;
use App\Services\Order\OrderStatusService;
use App\Services\Order\PaymentStatus;
use App\Services\Order\ReferenceNumberService;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\ValidationNotSupportedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class OrderFulfillmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(SupplierAdapter $adapter): OrderFulfillmentService
    {
        return new OrderFulfillmentService(
            new OrderStatusService(),
            new ReferenceNumberService(),
            $adapter,
            new LedgerService(),
        );
    }

    private function paidOrder(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => 'KRS-TEST-1',
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'server_id' => '1234',
            'supplier_product_ref' => 'FFP5',
            'cost_price' => 900,
            'reseller_cost_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'reseller_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
        ], $overrides));
    }

    private function fakeSupplierAdapter(
        bool $success,
        ?array $data = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): SupplierAdapter {
        return new class($success, $data, $errorCode, $errorMessage) implements SupplierAdapter
        {
            public function __construct(
                private readonly bool $success,
                private readonly ?array $data,
                private readonly ?string $errorCode,
                private readonly ?string $errorMessage,
            ) {
            }

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
                    : SupplierResponse::failure($this->errorCode, $this->errorMessage);
            }

            public function checkStatus(string $supplierRef): SupplierResponse
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
     * Fails fast with a clear internal error instead of silently
     * sending an empty product_code to the supplier.
     */
    public function test_fulfill_rejects_when_supplier_product_ref_is_missing(): void
    {
        $order = $this->paidOrder(['supplier_product_ref' => null]);

        $this->expectException(\App\Services\Fulfillment\OrderFulfillmentException::class);

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
    }

    /**
     * PRD §8 LedgerEntry: every delivered order writes exactly two
     * order_profit credit entries, even in MVP where both currently
     * resolve to the same internal owner.
     */
    public function test_fulfill_credits_platform_and_reseller_profit_on_delivery(): void
    {
        $order = $this->paidOrder(['platform_profit' => 150, 'reseller_profit' => 50]);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))->fulfill($order);

        $this->assertSame(150, (int) LedgerEntry::query()->where('owner_type', 'platform')->sum('amount'));
        $this->assertSame(50, (int) LedgerEntry::query()->where('owner_type', 'reseller')->sum('amount'));
    }

    /**
     * ADR-018 decision #6: the single, explicit guard that keeps a
     * sandbox order from ever reaching the real ledger, even though
     * every other line of fulfill() runs completely unchanged against
     * it (status transitions, reference_number, supplier_response).
     */
    public function test_fulfill_skips_ledger_credit_for_a_test_order(): void
    {
        $order = $this->paidOrder(['is_test' => true, 'platform_profit' => 150, 'reseller_profit' => 50]);

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
}
