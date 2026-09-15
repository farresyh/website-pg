<?php

namespace Tests\Feature\Services\Supplier;

use App\Jobs\LogSupplierRequestJob;
use App\Services\Supplier\Gamevion\GamevionAdapter;
use App\Services\Supplier\RequestLog\DeveloperTestContext;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GamevionAdapterTest extends TestCase
{
    /**
     * ADR-051: every real adapter call now dispatches
     * LogSupplierRequestJob (ShouldQueue) via SupplierRequestLogger's
     * on_stats hook. Queue::fake() keeps this suite's deliberate
     * DB-free scope intact — without it, the testing env's
     * QUEUE_CONNECTION=sync would run that job inline and hit a
     * `suppliers` table that was never migrated here.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function adapter(bool $sandbox = false): GamevionAdapter
    {
        return new GamevionAdapter(
            baseUrl: 'https://api.gamevion.com',
            bearerToken: 'test-bearer-token',
            apiKey: 'test-api-key',
            sandbox: $sandbox,
        );
    }

    public function test_check_balance_sends_both_required_auth_headers(): void
    {
        Http::fake([
            'api.gamevion.com/*' => Http::response([
                'error' => false,
                'code' => 200,
                'message' => 'Success',
                'data' => [
                    'user_name' => 'Melpa Digital',
                    'user_email' => 'user@example.com',
                    'user_membership' => 'gold',
                    'user_balance' => '150000.00',
                ],
            ], 200),
        ]);

        $this->adapter()->checkBalance();

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer test-bearer-token')
                && $request->hasHeader('X-API-KEY', 'test-api-key');
        });
    }

    /**
     * ADR-046 addendum: check-balance always queries the real account
     * — sandbox mode routes to a *different* account with its own fake
     * balance, found live while testing the Supplier Management
     * screen. A balance-monitoring tool showing that instead of the
     * real number whenever sandbox happens to be on is a real
     * money-visibility risk.
     */
    public function test_check_balance_never_sends_the_sandbox_header_even_when_sandbox_is_enabled(): void
    {
        Http::fake([
            'api.gamevion.com/*' => Http::response([
                'error' => false, 'code' => 200, 'message' => 'Success',
                'data' => ['user_name' => 'Melpa Digital', 'user_balance' => '150000.00'],
            ], 200),
        ]);

        $this->adapter(sandbox: true)->checkBalance();

        Http::assertSent(fn ($request) => ! $request->hasHeader('X-ENVIRONMENT'));
    }

    public function test_check_balance_normalizes_the_response(): void
    {
        Http::fake([
            'api.gamevion.com/*' => Http::response([
                'error' => false,
                'code' => 200,
                'message' => 'Success',
                'data' => [
                    'user_name' => 'Melpa Digital',
                    'user_email' => 'user@example.com',
                    'user_membership' => 'gold',
                    'user_balance' => '150000.00',
                ],
            ], 200),
        ]);

        $result = $this->adapter()->checkBalance();

        $this->assertTrue($result->success);
        $this->assertSame('Melpa Digital', $result->data['account_name']);
        $this->assertSame(150000.00, $result->data['balance']);
    }

    /**
     * ADR-051 — the real end-to-end wiring: a real call through this
     * adapter reaches SupplierRequestLogger's on_stats hook, gets
     * redacted, and dispatches LogSupplierRequestJob with the right
     * shape — not just that *a* job fired.
     */
    public function test_check_balance_logs_a_redacted_supplier_request(): void
    {
        Http::fake([
            'api.gamevion.com/*' => Http::response([
                'error' => false, 'code' => 200, 'message' => 'Success',
                'data' => ['user_balance' => '150000.00'],
            ], 200),
        ]);

        $this->adapter()->checkBalance();

        Queue::assertPushed(LogSupplierRequestJob::class, function ($job) {
            $entry = $job->entry();

            return $entry['slug'] === 'gamevion'
                && $entry['call_type'] === 'checkBalance'
                && $entry['status_code'] === 200
                && $entry['outcome'] === 'success'
                && $entry['request_payload']['headers']['Authorization'] === ['[REDACTED]']
                && $entry['request_payload']['headers']['X-API-KEY'] === ['[REDACTED]'];
        });
    }

    /**
     * ADR-054 decision 7 — the Developer API Tester's ambient context
     * flag is read at the exact point every real adapter call already
     * funnels through (SupplierRequestLogger::log()), so a call fired
     * from that screen is distinguishable in the Request Logs viewer
     * without the adapter itself knowing anything about it.
     */
    public function test_check_balance_logs_with_a_dev_test_prefix_inside_developer_test_context(): void
    {
        Http::fake([
            'api.gamevion.com/*' => Http::response([
                'error' => false, 'code' => 200, 'message' => 'Success',
                'data' => ['user_balance' => '150000.00'],
            ], 200),
        ]);

        DeveloperTestContext::runIn(fn () => $this->adapter()->checkBalance());

        Queue::assertPushed(LogSupplierRequestJob::class, fn ($job) => $job->entry()['call_type'] === 'dev_test_checkBalance');
    }

    /**
     * Live mode returns product_code/product_price shaped items.
     */
    public function test_list_products_normalizes_live_mode_shape(): void
    {
        Http::fake([
            'api.gamevion.com/*' => Http::response([
                'error' => false,
                'code' => 200,
                'message' => 'Success',
                'data' => [
                    [
                        'product_code' => 'FFP5',
                        'product_serviceName' => 'Free Fire 5 Diamonds',
                        'product_category' => 'Free Fire',
                        'product_price' => 1000,
                        'product_status' => 'active',
                    ],
                ],
            ], 200),
        ]);

        $result = $this->adapter()->listProducts();

        $this->assertTrue($result->success);
        $this->assertSame('FFP5', $result->data[0]->productRef);
        $this->assertSame('Free Fire 5 Diamonds', $result->data[0]->name);
        $this->assertSame(1000.0, $result->data[0]->price);
        $this->assertSame('active', $result->data[0]->status);
        // ADR-067 decision 4: Gamevion's category is already the group key.
        $this->assertSame('Free Fire', $result->data[0]->groupLabel);
        // ADR-069 decision 10 — Gamevion already quotes in MYR (no FX
        // conversion), so raw == price, tagged 'MYR', for a uniform
        // Product Manager sanity line across suppliers.
        $this->assertSame(1000.0, $result->data[0]->rawPrice);
        $this->assertSame('MYR', $result->data[0]->rawCurrency);
    }

    /**
     * Sandbox mode returns a DIFFERENTLY-PREFIXED shape (sandbox_code,
     * sandbox_price as a string, ...) for the same concept — confirmed
     * directly from Gamevion's OpenAPI spec (oneOf). The adapter must
     * still normalize this into the identical canonical shape as live
     * mode (ADAPT-2) — this is the concrete case that justifies the
     * whole Adapter layer, not a hypothetical one.
     */
    public function test_list_products_normalizes_sandbox_mode_shape_to_the_same_canonical_form(): void
    {
        Http::fake([
            'api.gamevion.com/*' => Http::response([
                'error' => false,
                'code' => 200,
                'message' => 'Success',
                'data' => [
                    [
                        'sandbox_code' => 'FFP5',
                        'sandbox_serviceName' => 'Free Fire 5 Diamonds',
                        'sandbox_category' => 'Free Fire',
                        'sandbox_price' => '1000.00',
                        'sandbox_status' => 'active',
                    ],
                ],
            ], 200),
        ]);

        $result = $this->adapter(sandbox: true)->listProducts();

        $this->assertTrue($result->success);
        $this->assertSame('FFP5', $result->data[0]->productRef);
        $this->assertSame('Free Fire 5 Diamonds', $result->data[0]->name);
        $this->assertSame(1000.0, $result->data[0]->price);
        $this->assertSame('active', $result->data[0]->status);
        $this->assertSame('Free Fire', $result->data[0]->groupLabel);
        $this->assertSame(1000.0, $result->data[0]->rawPrice);
        $this->assertSame('MYR', $result->data[0]->rawCurrency);
    }

    /**
     * Confirms the X-ENVIRONMENT header is sent in sandbox mode, and
     * the sandbox flag is forwarded in the request body.
     */
    public function test_list_products_in_sandbox_mode_sends_sandbox_signals(): void
    {
        Http::fake([
            'api.gamevion.com/*' => Http::response([
                'error' => false, 'code' => 200, 'message' => 'Success', 'data' => [],
            ], 200),
        ]);

        $this->adapter(sandbox: true)->listProducts();

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-ENVIRONMENT', 'sandbox')
                && $request['sandbox'] === true;
        });
    }

    /**
     * Gamevion's `data` field is player_id and server_id joined with a
     * pipe (confirmed from the spec example "123456|1234"). Our
     * canonical SupplierOrderRequest keeps them separate — the adapter
     * owns this outgoing transformation (ADAPT-4).
     */
    public function test_create_order_joins_player_id_and_server_id_with_a_pipe(): void
    {
        Http::fake([
            'api.gamevion.com/*' => Http::response([
                'error' => false,
                'code' => 200,
                'message' => 'Order Created',
                'data' => [
                    'invoice_number' => 'GV-RAPI-1A2B3C4D5E6F',
                    'ServiceName' => 'Free Fire 5 Diamonds',
                    'price' => '1000',
                    'quantity' => 1,
                    'game' => 'Free Fire',
                    'uid' => '123456',
                    'server' => '1234',
                    'created_at' => '2026-07-23T13:22:05.179Z',
                ],
            ], 200),
        ]);

        $result = $this->adapter()->createOrder(new SupplierOrderRequest(
            productRef: 'FFP5',
            referenceNumber: 'REF-01ARZ3NDEKTSV4RRFFQ69G5FAV',
            playerId: '123456',
            serverId: '1234',
        ));

        Http::assertSent(function ($request) {
            return $request['data'] === '123456|1234'
                && $request['product_code'] === 'FFP5'
                && $request['referenceNumber'] === 'REF-01ARZ3NDEKTSV4RRFFQ69G5FAV';
        });

        $this->assertTrue($result->success);
        $this->assertSame('GV-RAPI-1A2B3C4D5E6F', $result->data['supplier_ref']);
    }

    public function test_create_order_without_server_id_sends_player_id_only(): void
    {
        Http::fake([
            'api.gamevion.com/*' => Http::response([
                'error' => false, 'code' => 200, 'message' => 'Order Created',
                'data' => ['invoice_number' => 'GV-1'],
            ], 200),
        ]);

        $this->adapter()->createOrder(new SupplierOrderRequest(
            productRef: 'FFP5',
            referenceNumber: 'REF-1',
            playerId: '123456',
        ));

        Http::assertSent(fn ($request) => $request['data'] === '123456');
    }

    /**
     * Discovered live 2026-07-25 (real MLBB end-to-end checkout test):
     * Gamevion rejected "+60123456789" as `telp` with "must be between
     * 9 and 13 digits" even though it has 11 digits — their validator
     * fails entirely on the leading `+`. The adapter strips everything
     * but digits before sending.
     */
    public function test_create_order_strips_non_digit_characters_from_the_phone_number(): void
    {
        Http::fake([
            'api.gamevion.com/*' => Http::response([
                'error' => false, 'code' => 200, 'message' => 'Order Created',
                'data' => ['invoice_number' => 'GV-1'],
            ], 200),
        ]);

        $this->adapter()->createOrder(new SupplierOrderRequest(
            productRef: 'FFP5',
            referenceNumber: 'REF-1',
            playerId: '123456',
            customerPhone: '+60 12-345 6789',
        ));

        Http::assertSent(fn ($request) => $request['telp'] === '60123456789');
    }

    public function test_create_order_omits_telp_entirely_when_no_phone_given(): void
    {
        Http::fake([
            'api.gamevion.com/*' => Http::response([
                'error' => false, 'code' => 200, 'message' => 'Order Created',
                'data' => ['invoice_number' => 'GV-1'],
            ], 200),
        ]);

        $this->adapter()->createOrder(new SupplierOrderRequest(
            productRef: 'FFP5',
            referenceNumber: 'REF-1',
            playerId: '123456',
        ));

        Http::assertSent(fn ($request) => ! array_key_exists('telp', $request->data()));
    }

    /**
     * ORD-8 / ADR-006: a 409 from Gamevion means our reference_number
     * was already submitted — this is Gamevion's idempotency signal,
     * not a generic order-creation failure. The adapter surfaces it as
     * a distinguishable error code so retry logic can react correctly
     * (e.g. go check status instead of treating it as a hard failure).
     */
    public function test_create_order_normalizes_409_as_duplicate_reference(): void
    {
        Http::fake([
            'api.gamevion.com/*' => Http::response(['message' => 'Duplicate order reference'], 409),
        ]);

        $result = $this->adapter()->createOrder(new SupplierOrderRequest(
            productRef: 'FFP5',
            referenceNumber: 'REF-1',
            playerId: '123456',
        ));

        $this->assertFalse($result->success);
        $this->assertSame('duplicate_reference', $result->errorCode);
        // ADR-098 — the generic signal that drives NeedsReview routing.
        $this->assertTrue($result->transactionAlreadyFormed);
    }

    public function test_create_order_normalizes_insufficient_balance_failure(): void
    {
        Http::fake([
            'api.gamevion.com/*' => Http::response(['message' => 'Insufficient balance'], 400),
        ]);

        $result = $this->adapter()->createOrder(new SupplierOrderRequest(
            productRef: 'FFP5',
            referenceNumber: 'REF-1',
            playerId: '123456',
        ));

        $this->assertFalse($result->success);
        $this->assertSame('400', $result->errorCode);
        $this->assertSame('Insufficient balance', $result->errorMessage);
        $this->assertFalse($result->isServerError);
    }

    /**
     * ADR-019 addendum: CircuitBreakingSupplierAdapter only trips on
     * isServerError - a real 5xx must be marked as one so a sustained
     * outage (e.g. the documented sandbox 500, ADR-006) is actually
     * detected, unlike an ordinary 4xx business rejection.
     */
    public function test_create_order_marks_a_server_error_as_such(): void
    {
        Http::fake([
            'api.gamevion.com/*' => Http::response(['message' => 'Server error'], 500),
        ]);

        $result = $this->adapter()->createOrder(new SupplierOrderRequest(
            productRef: 'FFP5',
            referenceNumber: 'REF-1',
            playerId: '123456',
        ));

        $this->assertFalse($result->success);
        $this->assertTrue($result->isServerError);
    }

    /**
     * check-status takes Gamevion's OWN order id (the invoice_number
     * returned from order creation) — never our reference_number.
     * Confirmed directly from the spec's order_id example format
     * ("GV-RAPI-...", matching invoice_number, not referenceNumber).
     */
    public function test_check_status_normalizes_the_response(): void
    {
        Http::fake([
            'api.gamevion.com/*' => Http::response([
                'error' => false,
                'code' => 200,
                'message' => 'Success',
                'data' => [
                    'invoice' => 'GV-RAPI-1A2B3C4D5E6F',
                    'product_name' => 'Free Fire 5 Diamonds',
                    'transaction_status' => 'success',
                    'serial_number' => 'SN123',
                    'note' => null,
                    'created_at' => '2026-07-23T13:22:05.179Z',
                ],
            ], 200),
        ]);

        $result = $this->adapter()->checkStatus(new SupplierStatusCheckRequest('GV-RAPI-1A2B3C4D5E6F'));

        Http::assertSent(fn ($request) => $request['order_id'] === 'GV-RAPI-1A2B3C4D5E6F');

        $this->assertTrue($result->success);
        $this->assertSame('success', $result->data['status']);
    }

    public function test_check_status_normalizes_order_not_found(): void
    {
        Http::fake([
            'api.gamevion.com/*' => Http::response(['message' => 'Order not found'], 404),
        ]);

        $result = $this->adapter()->checkStatus(new SupplierStatusCheckRequest('GV-UNKNOWN'));

        $this->assertFalse($result->success);
        $this->assertSame('404', $result->errorCode);
    }

    /**
     * Gamevion has no player-validation endpoint at all (confirmed:
     * absent from the OpenAPI spec entirely) — this is the concrete
     * supplier ADR-005's fallback path exists for.
     */
    public function test_validate_player_throws_not_supported(): void
    {
        $this->expectException(ValidationNotSupportedException::class);

        $this->adapter()->validatePlayer('123456', '1234');
    }

    /**
     * ADR-014: a transient 5xx should be retried automatically rather
     * than surfacing as an immediate failure — proves the retry
     * actually fires, not just that it's configured.
     */
    public function test_retries_a_transient_server_error_then_succeeds(): void
    {
        Http::fake([
            'api.gamevion.com/*' => Http::sequence()
                ->push(['error' => true, 'code' => 500, 'message' => 'Server error'], 500)
                ->push(['error' => true, 'code' => 500, 'message' => 'Server error'], 500)
                ->push([
                    'error' => false, 'code' => 200, 'message' => 'Order Created',
                    'data' => ['invoice_number' => 'GV-RETRIED'],
                ], 200),
        ]);

        $result = $this->adapter()->createOrder(new SupplierOrderRequest(
            productRef: 'FFP5',
            referenceNumber: 'REF-RETRY-1',
            playerId: '123456',
        ));

        Http::assertSentCount(3);
        $this->assertTrue($result->success);
        $this->assertSame('GV-RETRIED', $result->data['supplier_ref']);
    }

    /**
     * ADR-014: a 4xx (validation outcome, not a transient failure —
     * this is the exact shape of the 422 "invalid product code" ADR-006's
     * sandbox retest hit) must never be retried. Retrying it would only
     * burn the retry budget and delay the real failure signal.
     */
    public function test_does_not_retry_a_validation_error(): void
    {
        Http::fake([
            'api.gamevion.com/*' => Http::response([
                'error' => true, 'code' => 422, 'message' => 'The selected product code is invalid.',
            ], 422),
        ]);

        $result = $this->adapter()->createOrder(new SupplierOrderRequest(
            productRef: '234',
            referenceNumber: 'REF-NO-RETRY-1',
            playerId: '123456',
        ));

        Http::assertSentCount(1);
        $this->assertFalse($result->success);
        $this->assertSame('422', $result->errorCode);
    }
}
