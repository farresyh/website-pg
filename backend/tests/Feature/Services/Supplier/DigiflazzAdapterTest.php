<?php

namespace Tests\Feature\Services\Supplier;

use App\Jobs\LogSupplierRequestJob;
use App\Services\Supplier\Digiflazz\DigiflazzAdapter;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierOutcome;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ADR-030: Digiflazz buyer-role adapter, prepaid games only. Every
 * request/response shape here is confirmed against the real live docs
 * (developer.digiflazz.com), not assumed — see ADR-030's Context and
 * its own re-check addendum for the three inaccuracies corrected there
 * (per-endpoint signature formula, the 4th official test case, the FX
 * tier citation — the last one is ADR-033's concern, not this file's).
 */
class DigiflazzAdapterTest extends TestCase
{
    /**
     * ADR-051: keeps this suite's deliberate DB-free scope intact —
     * see GamevionAdapterTest's own copy of this note.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function adapter(bool $testing = false): DigiflazzAdapter
    {
        return new DigiflazzAdapter(
            baseUrl: 'https://api.digiflazz.com',
            username: 'test-username',
            apiKey: 'test-api-key',
            testing: $testing,
            customerNoSeparator: '|',
        );
    }

    /**
     * ADR-030 Context (re-check addendum): the MD5 signature formula
     * is per-endpoint, not one shared formula — cek-saldo signs
     * md5(username + apiKey + "depo").
     */
    public function test_check_balance_sends_the_depo_signature(): void
    {
        Http::fake([
            'api.digiflazz.com/*' => Http::response(['data' => ['deposit' => 150000]], 200),
        ]);

        $this->adapter()->checkBalance();

        $expectedSign = md5('test-username'.'test-api-key'.'depo');
        Http::assertSent(fn ($request) => $request->url() === 'https://api.digiflazz.com/v1/cek-saldo'
            && $request['username'] === 'test-username'
            && $request['sign'] === $expectedSign);
    }

    /**
     * ADR-051 — Digiflazz's auth lives in the body (username/sign),
     * not a header, so this is the one adapter that actually exercises
     * SupplierRequestPayloadRedactor's body-key redaction path
     * end-to-end.
     */
    public function test_check_balance_logs_a_request_with_username_and_sign_redacted(): void
    {
        Http::fake([
            'api.digiflazz.com/*' => Http::response(['data' => ['deposit' => 150000]], 200),
        ]);

        $this->adapter()->checkBalance();

        Queue::assertPushed(LogSupplierRequestJob::class, function ($job) {
            $body = $job->entry()['request_payload']['body'];

            return $job->entry()['slug'] === 'digiflazz'
                && $job->entry()['call_type'] === 'checkBalance'
                && $body['cmd'] === 'deposit'
                && $body['username'] === '[REDACTED]'
                && $body['sign'] === '[REDACTED]';
        });
    }

    public function test_check_balance_normalizes_the_response(): void
    {
        Http::fake([
            'api.digiflazz.com/*' => Http::response(['data' => ['deposit' => 150000]], 200),
        ]);

        $result = $this->adapter()->checkBalance();

        $this->assertTrue($result->success);
        $this->assertSame(150000.0, $result->data['balance']);
    }

    /** Signature formula for price-list: md5(username + apiKey + "pricelist"), distinct from cek-saldo's. */
    public function test_list_products_sends_the_pricelist_signature_and_prepaid_cmd(): void
    {
        Http::fake([
            'api.digiflazz.com/*' => Http::response(['data' => []], 200),
        ]);

        $this->adapter()->listProducts();

        $expectedSign = md5('test-username'.'test-api-key'.'pricelist');
        Http::assertSent(fn ($request) => $request->url() === 'https://api.digiflazz.com/v1/price-list'
            && $request['cmd'] === 'prepaid'
            && $request['sign'] === $expectedSign);
    }

    /**
     * ADAPT-2: normalizes Digiflazz's own field names (buyer_sku_code,
     * product_name, price, buyer/seller_product_status as real booleans)
     * into the same canonical SupplierCatalogItem shape GamevionAdapter
     * produces — never mixed-in raw Digiflazz field names. ADR-067
     * decision 4 + region addendum: `groupLabel` is `brand` — `type`,
     * `type` is also carried raw.
     */
    public function test_list_products_normalizes_the_response_shape(): void
    {
        Http::fake([
            'api.digiflazz.com/*' => Http::response([
                'data' => [
                    [
                        'product_name' => 'Mobile Legends 10 Diamonds',
                        'category' => 'Games',
                        'brand' => 'MOBILE LEGENDS',
                        'type' => 'Malaysia',
                        'buyer_sku_code' => 'xld10',
                        'price' => 3200,
                        'buyer_product_status' => true,
                        'seller_product_status' => true,
                    ],
                ],
            ], 200),
        ]);

        $result = $this->adapter()->listProducts();

        $this->assertTrue($result->success);
        $this->assertSame('xld10', $result->data[0]->productRef);
        $this->assertSame('Mobile Legends 10 Diamonds', $result->data[0]->name);
        $this->assertSame(3200.0, $result->data[0]->price);
        $this->assertSame('active', $result->data[0]->status);
        $this->assertSame('Games', $result->data[0]->category);
        // ADR-067 region addendum: MLBB Malaysia is its own group,
        // never merged with MLBB Indonesia / Global / Umum.
        $this->assertSame('MOBILE LEGENDS — Malaysia', $result->data[0]->groupLabel);
        $this->assertSame('Malaysia', $result->data[0]->type);
    }

    /**
     * ADR-067 region addendum: a game Digiflazz lists with no `type`
     * falls back to the bare `brand` as its group label.
     */
    public function test_list_products_group_label_falls_back_to_brand_when_no_type(): void
    {
        Http::fake([
            'api.digiflazz.com/*' => Http::response([
                'data' => [[
                    'product_name' => 'Racing Master 100 Gold', 'category' => 'Games',
                    'brand' => 'Racing Master', 'buyer_sku_code' => 'rm100', 'price' => 9000,
                    'buyer_product_status' => true, 'seller_product_status' => true,
                ]],
            ], 200),
        ]);

        $result = $this->adapter()->listProducts();

        $this->assertSame('Racing Master', $result->data[0]->groupLabel);
        $this->assertNull($result->data[0]->type);
    }

    /**
     * ADR-025's own floor/swing guard downstream depends on an
     * inactive-at-source item never masquerading as 'active' —
     * buyer_product_status=false must map to a distinct status string.
     */
    public function test_list_products_maps_an_inactive_product_status(): void
    {
        Http::fake([
            'api.digiflazz.com/*' => Http::response([
                'data' => [[
                    'product_name' => 'Retired SKU', 'category' => 'Games', 'brand' => 'X',
                    'buyer_sku_code' => 'old10', 'price' => 1000,
                    'buyer_product_status' => false, 'seller_product_status' => true,
                ]],
            ], 200),
        ]);

        $result = $this->adapter()->listProducts();

        $this->assertSame('inactive', $result->data[0]->status);
    }

    /**
     * ADR-067 decision 3: an item the seller has disabled is dead even
     * if we left it enabled in our buyer area — 'active' requires BOTH
     * buyer_product_status AND seller_product_status true.
     */
    public function test_list_products_maps_a_seller_disabled_product_as_inactive(): void
    {
        Http::fake([
            'api.digiflazz.com/*' => Http::response([
                'data' => [[
                    'product_name' => 'Seller-pulled SKU', 'category' => 'Games', 'brand' => 'Y',
                    'buyer_sku_code' => 'y10', 'price' => 5000,
                    'buyer_product_status' => true, 'seller_product_status' => false,
                ]],
            ], 200),
        ]);

        $result = $this->adapter()->listProducts();

        $this->assertSame('inactive', $result->data[0]->status);
    }

    /**
     * ADR-030 decision 3 — testing:true sent only when the adapter is
     * constructed with testing mode on; never a stray key when off.
     */
    public function test_list_products_omits_testing_flag_when_not_in_testing_mode(): void
    {
        Http::fake(['api.digiflazz.com/*' => Http::response(['data' => []], 200)]);

        $this->adapter(testing: false)->listProducts();

        Http::assertSent(fn ($request) => ! array_key_exists('testing', $request->data()));
    }

    /**
     * Signature formula for the transaction endpoint (topup/checkStatus)
     * is md5(username + apiKey + ref_id) — the one endpoint where the
     * suffix is a real per-request value, not a literal word.
     */
    public function test_create_order_sends_the_ref_id_signature(): void
    {
        Http::fake([
            'api.digiflazz.com/*' => Http::response([
                'data' => ['ref_id' => 'REF-1', 'status' => 'Sukses', 'rc' => '00', 'sn' => 'SN123', 'message' => 'Sukses'],
            ], 200),
        ]);

        $this->adapter()->createOrder(new SupplierOrderRequest(
            productRef: 'xld10',
            referenceNumber: 'REF-1',
            playerId: '123456789',
        ));

        $expectedSign = md5('test-username'.'test-api-key'.'REF-1');
        Http::assertSent(fn ($request) => $request->url() === 'https://api.digiflazz.com/v1/transaction'
            && $request['buyer_sku_code'] === 'xld10'
            && $request['ref_id'] === 'REF-1'
            && $request['sign'] === $expectedSign);
    }

    /**
     * ADR-030 decision 5: customer_no is playerId + optional serverId,
     * joined with a configurable separator — MLBB-style games need
     * both; a game with no server concept sends playerId alone.
     */
    public function test_create_order_joins_player_id_and_server_id_into_customer_no(): void
    {
        Http::fake([
            'api.digiflazz.com/*' => Http::response(['data' => ['status' => 'Sukses', 'rc' => '00']], 200),
        ]);

        $this->adapter()->createOrder(new SupplierOrderRequest(
            productRef: 'xld10',
            referenceNumber: 'REF-1',
            playerId: '123456789',
            serverId: '1234',
        ));

        Http::assertSent(fn ($request) => $request['customer_no'] === '123456789|1234');
    }

    public function test_create_order_without_server_id_sends_player_id_only(): void
    {
        Http::fake([
            'api.digiflazz.com/*' => Http::response(['data' => ['status' => 'Sukses', 'rc' => '00']], 200),
        ]);

        $this->adapter()->createOrder(new SupplierOrderRequest(
            productRef: 'xld10',
            referenceNumber: 'REF-1',
            playerId: '123456789',
        ));

        Http::assertSent(fn ($request) => $request['customer_no'] === '123456789');
    }

    /** ADR-030 decision 3. */
    public function test_create_order_sends_testing_flag_only_when_in_testing_mode(): void
    {
        Http::fake([
            'api.digiflazz.com/*' => Http::response(['data' => ['status' => 'Sukses', 'rc' => '00']], 200),
        ]);

        $this->adapter(testing: true)->createOrder(new SupplierOrderRequest(
            productRef: 'xld10', referenceNumber: 'REF-1', playerId: '123456789',
        ));

        Http::assertSent(fn ($request) => $request['testing'] === true);
    }

    /**
     * Official test case 1: buyer_sku_code=xld10, customer_no=087800001230
     * -> Sukses, rc 00. ADR-032: maps to the normalized Success outcome.
     */
    public function test_create_order_maps_sukses_to_the_success_outcome(): void
    {
        Http::fake([
            'api.digiflazz.com/*' => Http::response([
                'data' => ['status' => 'Sukses', 'rc' => '00', 'sn' => 'SN-REAL-123', 'message' => 'Transaksi Sukses', 'price' => 3200],
            ], 200),
        ]);

        $result = $this->adapter()->createOrder(new SupplierOrderRequest(
            productRef: 'xld10', referenceNumber: 'REF-1', playerId: '087800001230',
        ));

        $this->assertSame(SupplierOutcome::Success, $result->outcome);
        $this->assertTrue($result->success);
        $this->assertSame('SN-REAL-123', $result->data['supplier_ref']);
    }

    /**
     * Official test case 2: customer_no=087800001232 -> Gagal, rc 02.
     */
    public function test_create_order_maps_gagal_to_the_failure_outcome(): void
    {
        Http::fake([
            'api.digiflazz.com/*' => Http::response([
                'data' => ['status' => 'Gagal', 'rc' => '02', 'message' => 'Transaksi Gagal'],
            ], 200),
        ]);

        $result = $this->adapter()->createOrder(new SupplierOrderRequest(
            productRef: 'xld10', referenceNumber: 'REF-1', playerId: '087800001232',
        ));

        $this->assertSame(SupplierOutcome::Failure, $result->outcome);
        $this->assertFalse($result->success);
        $this->assertSame('02', $result->errorCode);
        $this->assertSame('Transaksi Gagal', $result->errorMessage);
    }

    /**
     * Official test cases 3/4: customer_no=087800001233/087800001234
     * both start Pending, rc 03 — ADR-032's Pending outcome, the exact
     * path the whole async-delivery mechanism exists for.
     */
    public function test_create_order_maps_pending_to_the_pending_outcome(): void
    {
        Http::fake([
            'api.digiflazz.com/*' => Http::response([
                'data' => ['status' => 'Pending', 'rc' => '03', 'message' => 'Transaksi Pending'],
            ], 200),
        ]);

        $result = $this->adapter()->createOrder(new SupplierOrderRequest(
            productRef: 'xld10', referenceNumber: 'REF-1', playerId: '087800001233',
        ));

        $this->assertSame(SupplierOutcome::Pending, $result->outcome);
        $this->assertFalse($result->success);
        $this->assertNull($result->errorCode);
    }

    /** A real transport-level failure (connection/5xx) is distinct from a business rc failure. */
    public function test_create_order_marks_a_real_server_error_as_such(): void
    {
        Http::fake(['api.digiflazz.com/*' => Http::response(['message' => 'Server error'], 500)]);

        $result = $this->adapter()->createOrder(new SupplierOrderRequest(
            productRef: 'xld10', referenceNumber: 'REF-1', playerId: '123456789',
        ));

        $this->assertSame(SupplierOutcome::Failure, $result->outcome);
        $this->assertTrue($result->isServerError);
    }

    /**
     * ADR-030 decision 1 / the docs' own confirmed behavior: checkStatus
     * is a literal re-submit of the topup request with the same ref_id
     * — requires the original buyer_sku_code + customer_no too, not
     * just the ref_id (this is why SupplierStatusCheckRequest carries
     * more than a bare string, corrected from the ADR's original text
     * while building this adapter — see SupplierStatusCheckRequest's
     * own doc comment).
     */
    public function test_check_status_resubmits_with_the_same_ref_id_sku_and_customer_no(): void
    {
        Http::fake([
            'api.digiflazz.com/*' => Http::response([
                'data' => ['status' => 'Sukses', 'rc' => '00', 'sn' => 'SN-FINAL'],
            ], 200),
        ]);

        $result = $this->adapter()->checkStatus(new SupplierStatusCheckRequest(
            supplierRef: 'REF-1',
            productRef: 'xld10',
            playerId: '087800001233',
        ));

        $expectedSign = md5('test-username'.'test-api-key'.'REF-1');
        Http::assertSent(fn ($request) => $request->url() === 'https://api.digiflazz.com/v1/transaction'
            && $request['ref_id'] === 'REF-1'
            && $request['buyer_sku_code'] === 'xld10'
            && $request['customer_no'] === '087800001233'
            && $request['sign'] === $expectedSign);

        $this->assertSame(SupplierOutcome::Success, $result->outcome);
        $this->assertSame('SN-FINAL', $result->data['supplier_ref']);
    }

    /**
     * ADR-030 decision 1: no player-validation endpoint exists at all
     * for Digiflazz (confirmed absent from the public docs) — ADR-005's
     * fallback applies to every game on this supplier, same as Gamevion.
     */
    public function test_validate_player_throws_not_supported(): void
    {
        $this->expectException(ValidationNotSupportedException::class);

        $this->adapter()->validatePlayer('087800001230', null);
    }

    /** ADR-014: same shared retry policy every other adapter uses. */
    public function test_retries_a_transient_server_error_then_succeeds(): void
    {
        Http::fake([
            'api.digiflazz.com/*' => Http::sequence()
                ->push(['message' => 'Server error'], 500)
                ->push(['message' => 'Server error'], 500)
                ->push(['data' => ['status' => 'Sukses', 'rc' => '00', 'sn' => 'SN-RETRIED']], 200),
        ]);

        $result = $this->adapter()->createOrder(new SupplierOrderRequest(
            productRef: 'xld10', referenceNumber: 'REF-1', playerId: '123456789',
        ));

        Http::assertSentCount(3);
        $this->assertTrue($result->success);
        $this->assertSame('SN-RETRIED', $result->data['supplier_ref']);
    }

    /**
     * A business rc failure (rc 02, real HTTP 200) must never be
     * retried — same discipline as Gamevion's 4xx guard, just a
     * different transport shape for the same "not transient" concept.
     */
    public function test_does_not_retry_a_business_level_failure(): void
    {
        Http::fake([
            'api.digiflazz.com/*' => Http::response(['data' => ['status' => 'Gagal', 'rc' => '02', 'message' => 'insufficient balance']], 200),
        ]);

        $this->adapter()->createOrder(new SupplierOrderRequest(
            productRef: 'xld10', referenceNumber: 'REF-1', playerId: '123456789',
        ));

        Http::assertSentCount(1);
    }
}
