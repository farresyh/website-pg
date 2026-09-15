<?php

namespace App\Services\Supplier\Digiflazz;

use App\Services\Http\TransientFailureRetryPolicy;
use App\Services\Supplier\RequestLog\SupplierRequestLogger;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierCatalogItem;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Adapter for Digiflazz (developer.digiflazz.com), Buyer role,
 * prepaid games only (ADR-030). Key traits specific to Digiflazz this
 * adapter absorbs so business logic never has to know about them:
 *  - Auth is a body-level `sign` (MD5), never a header — and the
 *    formula is PER-ENDPOINT, not one shared formula: cek-saldo signs
 *    md5(username+apiKey+"depo"), price-list signs
 *    md5(username+apiKey+"pricelist"), and only the transaction
 *    endpoint (topup + its checkStatus re-submit) signs
 *    md5(username+apiKey+ref_id).
 *  - Every response is wrapped in a `data` object; the transaction
 *    endpoint's `data.status` is one of Sukses/Pending/Gagal (rc
 *    00/03/02) — this adapter never exposes those raw strings to
 *    business logic, only the normalized SupplierOutcome (ADR-032).
 *  - checkStatus() is a literal re-submit of the topup request with
 *    the same ref_id — confirmed from the real docs it also needs the
 *    original buyer_sku_code + customer_no, not just the ref_id (see
 *    SupplierStatusCheckRequest's own doc comment for the correction
 *    this forced against ADR-030/032's original text).
 *  - No player-validation endpoint exists (ADR-005 fallback applies
 *    to every game on this supplier, same as Gamevion).
 *  - customer_no's real per-game format (e.g. MLBB) isn't documented
 *    publicly — normalizeCustomerNo() is config-driven so the actual
 *    separator can be corrected at app:digiflazz-smoke-test time
 *    without touching this class.
 */
final class DigiflazzAdapter implements SupplierAdapter
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $apiKey,
        private readonly bool $testing = false,
        private readonly string $customerNoSeparator = '|',
        private readonly ?string $proxyUrl = null,
        private readonly int $timeoutSeconds = 10,
        private readonly int $connectTimeoutSeconds = 5,
    ) {}

    public function checkBalance(): SupplierResponse
    {
        $response = $this->client(callType: 'checkBalance')->post('/v1/cek-saldo', [
            'cmd' => 'deposit',
            'username' => $this->username,
            'sign' => $this->sign('depo'),
        ]);

        if ($failure = $this->failureFrom($response)) {
            return $failure;
        }

        $data = $response->json('data', []);

        return SupplierResponse::success([
            'account_name' => null,
            'account_email' => null,
            'membership' => null,
            'balance' => isset($data['deposit']) ? (float) $data['deposit'] : null,
        ]);
    }

    public function listProducts(): SupplierResponse
    {
        $response = $this->client(callType: 'listProducts')->post('/v1/price-list', [
            'cmd' => 'prepaid',
            'username' => $this->username,
            'sign' => $this->sign('pricelist'),
        ]);

        if ($failure = $this->failureFrom($response)) {
            return $failure;
        }

        $items = $response->json('data', []);

        return SupplierResponse::success(array_map(
            fn (array $item) => $this->normalizeProduct($item),
            $items,
        ));
    }

    public function createOrder(SupplierOrderRequest $request): SupplierResponse
    {
        return $this->submitTransaction(
            refId: $request->referenceNumber,
            buyerSkuCode: $request->productRef,
            customerNo: $this->normalizeCustomerNo($request->playerId, $request->serverId, $request->customerNoSeparator),
            callType: 'createOrder',
            orderId: $request->orderId,
        );
    }

    /**
     * ADR-030 decision 1 (corrected while building this adapter): the
     * real docs confirm this re-submits the SAME topup request —
     * buyer_sku_code and customer_no included, not ref_id alone.
     */
    public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
    {
        return $this->submitTransaction(
            refId: $request->supplierRef,
            buyerSkuCode: (string) $request->productRef,
            customerNo: $this->normalizeCustomerNo((string) $request->playerId, $request->serverId, $request->customerNoSeparator),
            callType: 'checkStatus',
            orderId: $request->orderId,
        );
    }

    public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
    {
        throw new ValidationNotSupportedException(
            'Digiflazz has no player-validation endpoint — validation happens implicitly at order time (ADR-005).',
        );
    }

    /**
     * createOrder()/checkStatus() both run inside OrderFulfillmentService's
     * DB row lock — same reasoning as GamevionAdapter::client() for
     * keeping the timeout short.
     */
    private function client(string $callType = 'unknown', ?int $orderId = null): PendingRequest
    {
        $client = Http::baseUrl($this->baseUrl)
            ->timeout($this->timeoutSeconds)
            ->connectTimeout($this->connectTimeoutSeconds)
            ->acceptJson()
            // ADR-014: same shared retry predicate every adapter uses —
            // connection failures and real 5xx only. `throw: false` means
            // a business `rc` failure never becomes a RequestException the
            // predicate could see, so it is never retried regardless of
            // the HTTP status Digiflazz wraps it in (2xx or 4xx) — the
            // classification happens in submitTransaction()/failureFrom().
            ->retry([200, 500, 1000], when: TransientFailureRetryPolicy::shouldRetry(), throw: false);

        if ($this->proxyUrl !== null) {
            $client = $client->withOptions(['proxy' => $this->proxyUrl]);
        }

        // ADR-051 — every real outbound call from this adapter gets a
        // supplier_request_logs row.
        return SupplierRequestLogger::attach($client, 'digiflazz', $callType, $orderId);
    }

    /**
     * The transaction endpoint's three-way outcome (Sukses/Pending/Gagal,
     * rc 00/03/02) is exactly ADR-032's SupplierOutcome — createOrder()
     * and checkStatus() share this since Digiflazz's own docs describe
     * checkStatus as a literal re-submit of the same request shape.
     */
    private function submitTransaction(string $refId, string $buyerSkuCode, string $customerNo, string $callType, ?int $orderId): SupplierResponse
    {
        $response = $this->client(callType: $callType, orderId: $orderId)->post('/v1/transaction', array_filter([
            'username' => $this->username,
            'buyer_sku_code' => $buyerSkuCode,
            'customer_no' => $customerNo,
            'ref_id' => $refId,
            'sign' => $this->sign($refId),
            // ADR-030 decision 3 — sent only when true; array_filter
            // drops it entirely when false, never a stray `false` key.
            'testing' => $this->testing ?: null,
        ], fn ($value) => $value !== null));

        // A real 5xx means Digiflazz itself is down — surface it as a
        // server error so the circuit breaker sees it, whatever any body
        // claims. Everything below trusts the response body.
        if ($response->serverError()) {
            return SupplierResponse::failure(
                (string) $response->status(),
                "Digiflazz request failed with HTTP {$response->status()}",
                isServerError: true,
            );
        }

        $data = $response->json('data');

        // Digiflazz wraps a well-formed {rc, status, message} envelope
        // even under an HTTP 4xx (proven live 2026-09-02: HTTP 400 +
        // `rc 44` "Saldo tidak cukup" — ADR-030's 2026-09-03 addendum).
        // Trust the envelope's own three-way outcome whenever it is
        // present; a business rejection is never a server error, even
        // wearing a 4xx, so it never counts against the breaker.
        if (is_array($data) && isset($data['status'])) {
            return match ($data['status']) {
                'Sukses' => SupplierResponse::success([
                    'supplier_ref' => $data['sn'] ?? $data['ref_id'] ?? null,
                    'status' => $data['status'],
                    'rc' => $data['rc'] ?? null,
                    'message' => $data['message'] ?? null,
                    'price' => isset($data['price']) ? (float) $data['price'] : null,
                ]),
                'Pending' => SupplierResponse::pending([
                    'status' => $data['status'],
                    'rc' => $data['rc'] ?? null,
                    'message' => $data['message'] ?? null,
                ]),
                default => SupplierResponse::failure(
                    (string) ($data['rc'] ?? 'unknown'),
                    $data['message'] ?? 'Unknown Digiflazz error',
                ),
            };
        }

        // An envelope carrying an `rc` but no `status` can only be a
        // failure — Pending is impossible to assert without `status`.
        if (is_array($data) && isset($data['rc'])) {
            return SupplierResponse::failure(
                (string) $data['rc'],
                $data['message'] ?? 'Unknown Digiflazz error',
            );
        }

        // No usable envelope — a genuine transport-level 4xx (a gateway
        // error page, an empty body, a connection reset that exhausted
        // the retries).
        if ($response->failed()) {
            return SupplierResponse::failure(
                (string) $response->status(),
                "Digiflazz request failed with HTTP {$response->status()}",
                isServerError: false,
            );
        }

        return SupplierResponse::failure('unknown', 'Unknown Digiflazz error');
    }

    /**
     * Digiflazz's own error shape is inconsistent: an IP-whitelist
     * rejection came back as `rc: 45` under HTTP 200 (ADR-030 Context),
     * but the transaction endpoint returns a business failure — `rc 44`
     * "Saldo tidak cukup" — under HTTP **400** (proven live 2026-09-02,
     * ADR-030's 2026-09-03 addendum). So: a 5xx is always a transport
     * error (breaker-counting); anything else is read from the body —
     * a non-'00' `rc`, whether it rode a 2xx or a 4xx, is the real
     * failure and is surfaced verbatim. checkBalance()/listProducts()
     * have no Sukses/Pending/Gagal `status`, only a bare `rc`.
     */
    private function failureFrom(Response $response): ?SupplierResponse
    {
        if ($response->serverError()) {
            return SupplierResponse::failure(
                (string) $response->status(),
                "Digiflazz request failed with HTTP {$response->status()}",
                isServerError: true,
            );
        }

        $data = $response->json('data');

        if (is_array($data) && isset($data['rc']) && $data['rc'] !== '00') {
            return SupplierResponse::failure(
                (string) $data['rc'],
                $data['message'] ?? 'Unknown Digiflazz error',
            );
        }

        if ($response->failed()) {
            return SupplierResponse::failure(
                (string) $response->status(),
                "Digiflazz request failed with HTTP {$response->status()}",
                isServerError: false,
            );
        }

        return null;
    }

    private function sign(string $suffix): string
    {
        return md5($this->username.$this->apiKey.$suffix);
    }

    /**
     * ADR-030 decision 5 — config-driven so the real separator (or
     * lack thereof) for a specific game's customer_no format can be
     * corrected at smoke-test time without touching this class. Mirrors
     * GamevionAdapter's own playerId|serverId convention as the
     * best-guess default, unconfirmed against a live game until then.
     *
     * ADR-097 decision 2/15: `$separatorOverride` is the GAME-level
     * named enum ('concat'|'space'|'pipe'), never the literal char —
     * this method is the one place that translates it, same as it
     * already owns `$this->customerNoSeparator` (the supplier-level
     * default, which stays a literal character, unchanged by this
     * ADR — SupplierConfigSchema's own `customer_no_separator` field
     * is still free text). A null override falls back to the supplier
     * default exactly as it did before this ADR.
     */
    private function normalizeCustomerNo(string $playerId, ?string $serverId, ?string $separatorOverride = null): string
    {
        if ($serverId === null) {
            return $playerId;
        }

        $separator = $separatorOverride !== null
            ? self::translateSeparator($separatorOverride)
            : $this->customerNoSeparator;

        return "{$playerId}{$separator}{$serverId}";
    }

    /**
     * ADR-097 decision 2 — self-documenting named enum over a literal
     * character, which risks silent whitespace/escaping confusion in
     * JSON (`Game.validation_rules['customer_no_separator']`). Any
     * value outside the enum (shouldn't reach here — `Rule::in()` at
     * the admin FormRequest layer already guards it) falls back to
     * concatenation rather than throwing — fail safe, never fail loud
     * on a money-critical delivery path.
     */
    private static function translateSeparator(string $name): string
    {
        return match ($name) {
            'space' => ' ',
            'pipe' => '|',
            default => '',
        };
    }

    /**
     * `buyer_product_status` and `seller_product_status` are both real
     * booleans in Digiflazz's own API (confirmed live 2026-09-02) —
     * mapped to the same 'active'/'inactive' string convention
     * GamevionAdapter uses, since PendingReactivationFinder and admin
     * display both read `SupplierProduct.status_raw` as a string
     * uniformly across suppliers. ADR-067 decision 3: an item is
     * 'active' only when BOTH flags are true — a seller disabling a
     * product makes it dead even if we left it enabled in the buyer
     * area.
     *
     * ADR-067 decision 4 (+ its region addendum): `groupLabel` is
     * `brand` joined with `type`. Digiflazz's `category` is a flat
     * `"Games"` for every game; `brand` is the game ("MOBILE LEGENDS"),
     * and `type` is its **region variant** ("Umum" / "Malaysia" /
     * "Indonesia" / "Global" / ...) — exactly the region tabs in
     * Digiflazz's own buyer-area UI. Grouping by `brand` alone would
     * collapse every MLBB region into one group; Gamevion already keeps
     * them apart ("Mobile Legends (Indonesia)" is its own `category`),
     * so this matches. `type` is still carried raw for a future
     * checkout need. A game with no `type` falls back to `brand` alone.
     */
    private function normalizeProduct(array $item): SupplierCatalogItem
    {
        $isActive = ($item['buyer_product_status'] ?? false)
            && ($item['seller_product_status'] ?? false);

        $brand = $item['brand'] ?? null;
        $type = $item['type'] ?? null;

        return new SupplierCatalogItem(
            productRef: $item['buyer_sku_code'] ?? '',
            name: $item['product_name'] ?? null,
            category: $item['category'] ?? null,
            price: isset($item['price']) ? (float) $item['price'] : null,
            status: $isActive ? 'active' : 'inactive',
            groupLabel: $this->groupLabel($brand, $type),
            type: $type,
            // ADR-069 decision 10 — Digiflazz quotes in Indonesian
            // rupiah; `price_sen` is that value converted to MYR sen
            // at sync time (ADR-033). Keep the original for a sanity
            // line in the Product Manager.
            rawPrice: isset($item['price']) ? (float) $item['price'] : null,
            rawCurrency: isset($item['price']) ? 'IDR' : null,
        );
    }

    private function groupLabel(?string $brand, ?string $type): ?string
    {
        $brand = $brand !== null ? trim($brand) : '';
        $type = $type !== null ? trim($type) : '';

        if ($brand === '') {
            return $type === '' ? null : $type;
        }

        return $type === '' ? $brand : "{$brand} — {$type}";
    }
}
