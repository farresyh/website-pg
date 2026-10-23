<?php

namespace App\Http\Controllers\Middleware;

use App\Http\Controllers\Controller;
use App\Http\Requests\Middleware\BulkUpdateSupplierPackagesStatusRequest;
use App\Http\Requests\Middleware\CreateSupplierRequest;
use App\Http\Requests\Middleware\UpdateSupplierRequest;
use App\Http\Requests\Middleware\UpdateSupplierStatusRequest;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\WhatsAppGroup;
use App\Services\CircuitBreaker\CircuitBreaker;
use App\Services\Supplier\Manual\ManualSupplierStatusService;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Supplier\SupplierConfigSchema;
use App\Services\Supplier\SupplierNotConfiguredException;
use App\Services\Supplier\SupplierPackageStatusService;
use App\Services\Supplier\UnsupportedSupplierException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-046 — Supplier Management, trimmed to SUPP-1/CRUD/SUPP-5 (SUPP-2/3/4
 * are deliberately not built here; Product Manager already covers
 * catalog browse/add/search — see ADR-046's Context). Super Admin only,
 * per routes/api.php's own "supplier config, per PRD §3" convention.
 */
class SupplierController extends Controller
{
    /**
     * SUPP-1 — cards with connection status (CircuitBreaker::state(),
     * same cache-derived read DashboardService::health() already
     * performs, never a live ping) and balance. Also returns per-row
     * reference counts so the frontend can grey out Delete without a
     * second round-trip, has_credentials (never the credential itself
     * — SUPP-5, api_config stays $hidden on the model), and
     * visible_config (only the non-secret keys — base_url/sandbox/
     * testing/etc. — so the Edit form can pre-fill those without ever
     * exposing a real secret).
     */
    public function index(): JsonResponse
    {
        $breakerConfig = config('services.circuit_breaker');

        // ADR-126 F5: the joki pool holds no balance and is switched with Joki.
        $suppliers = Supplier::query()->exceptAssignmentPool()->orderBy('name')->get()->map(function (Supplier $supplier) use ($breakerConfig) {
            $breaker = new CircuitBreaker(
                name: $supplier->slug,
                failureThreshold: $breakerConfig['failure_threshold'],
                cooldownSeconds: $breakerConfig['cooldown_seconds'],
            );

            return array_merge($supplier->toArray(), [
                'circuit_state' => $breaker->state()->value,
                'has_credentials' => ! empty($supplier->api_config),
                // Found live, 2026-08-28: has_credentials alone reads
                // "Configured" the moment ANY key is saved (e.g. just
                // base_url), which is exactly what let a supplier one
                // Refresh Balance click away from crashing look
                // finished. This is the field the "Configured" badge
                // should actually gate on — every key
                // SupplierConfigSchema defines for this slug present,
                // not merely "not empty".
                'is_fully_configured' => SupplierConfigSchema::missingKeys($supplier->driver, $supplier->api_config ?? []) === [],
                // Per-secret-field presence (never the value itself,
                // same SUPP-5 boundary visibleConfig() already
                // enforces) — lets the Edit form's per-field badge
                // show which secret is actually set instead of every
                // secret field reusing has_credentials' one supplier-
                // wide flag.
                'configured_secret_keys' => $this->configuredSecretKeys($supplier),
                'visible_config' => $this->visibleConfig($supplier),
                'is_sandbox' => $this->isSandbox($supplier),
                // ADR-125 decisions 2 and 18: a manual supplier has no API
                // to poll, so its balance is its funding ledger.
                'is_api_backed' => $supplier->isApiBacked(),
                'display_balance' => $supplier->displayBalance(),
                'reference_counts' => $this->referenceCounts($supplier),
            ]);
        })->values();

        return response()->json($suppliers);
    }

    /**
     * ADR-046 decision 4 — backs the Create modal's driver dropdown with
     * the same source of truth CreateSupplierRequest validates
     * against, so the two can never drift apart.
     */
    public function availableDrivers(SupplierAdapterFactory $adapters): JsonResponse
    {
        return response()->json($adapters->registeredDrivers());
    }

    public function store(CreateSupplierRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('api_config', $data)) {
            $data['api_config'] = SupplierConfigSchema::normalizeConfig($data['driver'], $data['api_config']);
        }

        // ADR-125 decision 56: a manual supplier starts inactive. It is
        // switched on once its WhatsApp group is linked (Partners → Suppliers).
        if ($data['driver'] === Supplier::DRIVER_MANUAL) {
            $data['is_active'] = false;
        }

        $supplier = Supplier::query()->create(array_merge(['api_config' => []], $data));

        return response()->json($supplier, 201);
    }

    /**
     * ADR-046 decision 3: `api_config` is merged onto the existing
     * stored value, never replaced wholesale — SUPP-5 means a secret
     * field is never sent back to the frontend to re-submit, so a
     * blank/omitted secret in this request must leave that key
     * untouched rather than being read as "clear it." Only the keys
     * actually present in this request's api_config overwrite their
     * counterpart; every other existing key survives.
     *
     * ADR-067 decision 2: after merging, `list`-type fields
     * (`category_whitelist`) are coerced to a real `string[]` — the
     * form submits them comma-separated.
     */
    public function update(UpdateSupplierRequest $request, Supplier $supplier, SupplierAdapterFactory $adapters): JsonResponse
    {
        $data = $request->validated();
        $configChanged = array_key_exists('api_config', $data);

        // ADR-125 decision 18: an API supplier is always prepaid.
        if (array_key_exists('funding_mode', $data) && $supplier->isApiBacked() && $data['funding_mode'] !== Supplier::FUNDING_PREPAID) {
            throw ValidationException::withMessages(['funding_mode' => ['An API supplier is always prepaid.']]);
        }
        // ADR-125 decision 9: a manual supplier is paid in RM.
        if (array_key_exists('currency', $data) && ! $supplier->isApiBacked() && $data['currency'] !== 'MYR') {
            throw ValidationException::withMessages(['currency' => ['A manual supplier is paid in MYR.']]);
        }

        if ($configChanged) {
            $data['api_config'] = SupplierConfigSchema::normalizeConfig(
                $supplier->driver,
                array_merge($supplier->api_config ?? [], $data['api_config']),
            );
        }

        $supplier->update($data);
        $supplier->refresh();

        $payload = $supplier->toArray();

        // ADR-069 decision 11 — a credential change that silently fails
        // to take (the masked-merge of ADR-046 decision 3 is exactly
        // such a path) must surface now, not only when the next order
        // fails. Probe the connection right after saving and hand the
        // result back for the form to show. Never blocks the save.
        if ($configChanged && $supplier->isApiBacked()) {
            $payload['connection_probe'] = $this->probeConnection($supplier, $adapters);
        }

        return response()->json($payload);
    }

    /**
     * ADR-069 decision 11 — a read-only checkBalance() through the same
     * circuit-breaker-wrapped adapter path a real order uses, run after
     * a credential change so a silently-broken rotation shows up on the
     * spot. Writes balance / last_tested_* exactly as refreshBalance()
     * does, so the two never disagree.
     *
     * ADR-069 stress-test Q3 — a `CIRCUIT_OPEN` failure
     * (`CircuitBreakingSupplierAdapter`, from *earlier* unrelated
     * failures) is NOT evidence the credential just saved is wrong, so
     * it is worded distinctly and does not stamp `last_test_result`.
     *
     * @return array{connection_ok: bool, balance: mixed, error: string|null, breaker_open?: bool}
     */
    private function probeConnection(Supplier $supplier, SupplierAdapterFactory $adapters): array
    {
        try {
            $adapter = $adapters->for($supplier);
        } catch (UnsupportedSupplierException|SupplierNotConfiguredException $e) {
            return ['connection_ok' => false, 'balance' => null, 'error' => $e->getMessage()];
        }

        $response = $adapter->checkBalance();

        if (! $response->success && $response->errorCode === 'CIRCUIT_OPEN') {
            return [
                'connection_ok' => false,
                'balance' => null,
                'breaker_open' => true,
                'error' => 'Connection check skipped — the circuit breaker is open from earlier failures. '
                    .'Retry after the cooldown, or use Refresh Balance once it closes.',
            ];
        }

        $update = [
            'last_tested_at' => now(),
            'last_test_result' => $response->success ? 'success' : "failed: [{$response->errorCode}] {$response->errorMessage}",
        ];

        if ($response->success && isset($response->data['balance'])) {
            $update['balance'] = $response->data['balance'];
        }

        $supplier->update($update);

        return [
            'connection_ok' => $response->success,
            'balance' => $response->success ? ($response->data['balance'] ?? null) : null,
            'error' => $response->success ? null : "[{$response->errorCode}] {$response->errorMessage}",
        ];
    }

    /**
     * ADR-046 decision 5: activating is a real, immediate action (the
     * next scheduled sync picks this supplier up — ADR-031 decision
     * 4) — the confirm warning itself is a frontend concern, this just
     * flips the flag.
     */
    public function updateStatus(UpdateSupplierStatusRequest $request, Supplier $supplier, ManualSupplierStatusService $manual): JsonResponse
    {
        // ADR-125 decision 64: a manual supplier's switch also takes its
        // packages on and off sale; an API supplier's flag stays as it was.
        if (! $supplier->isApiBacked()) {
            $manual->setActive($supplier, $request->validated('is_active'), $request->user()->id);

            return response()->json($supplier->fresh());
        }

        $supplier->update(['is_active' => $request->validated('is_active')]);

        return response()->json($supplier->fresh());
    }

    /**
     * ADR-046 decision 6: unconditional delete is unsafe (packages.
     * supplier_id restricts, supplier_products cascades, orders nulls
     * historical supplier_id — three different behaviors) — only a
     * same-session, never-used row (zero references everywhere) may
     * be hard-deleted; anything else must go through updateStatus
     * (deactivate) instead.
     */
    public function destroy(Supplier $supplier): JsonResponse
    {
        $counts = $this->referenceCounts($supplier);

        if (array_sum($counts) > 0) {
            throw ValidationException::withMessages([
                'supplier' => ['This supplier has existing packages, synced products, or orders — deactivate it instead of deleting.'],
            ]);
        }

        DB::transaction(function () use ($supplier) {
            WhatsAppGroup::query()->where('owner_type', WhatsAppGroup::OWNER_SUPPLIER)->where('owner_id', $supplier->id)->delete();
            $supplier->delete();
        });

        return response()->json(null, 204);
    }

    /**
     * ADR-046 decision 8: a real, on-demand checkBalance() call through
     * the same circuit-breaker-wrapped adapter path as a real order —
     * unlike Payment Methods' "Test This Channel" (a real RM1.00
     * payment-intent), this is a genuinely free read-only supplier
     * call, so there's no cost concern firing it on demand.
     */
    public function refreshBalance(Supplier $supplier, SupplierAdapterFactory $adapters): JsonResponse
    {
        if (! $supplier->isApiBacked()) {
            throw ValidationException::withMessages(['supplier' => ['A manual supplier has no API balance; its balance is its funding ledger.']]);
        }

        try {
            $adapter = $adapters->for($supplier);
        } catch (UnsupportedSupplierException|SupplierNotConfiguredException $e) {
            throw ValidationException::withMessages(['supplier' => [$e->getMessage()]]);
        }

        $response = $adapter->checkBalance();

        $update = [
            'last_tested_at' => now(),
            'last_test_result' => $response->success ? 'success' : "failed: [{$response->errorCode}] {$response->errorMessage}",
        ];

        if ($response->success && isset($response->data['balance'])) {
            $update['balance'] = $response->data['balance'];
        }

        $supplier->update($update);

        return response()->json($supplier->fresh());
    }

    /**
     * ADR-046 decisions 9/10 — one endpoint, both directions
     * ("Deactivate All"/"Deactivate by Game" and their paired
     * "Reactivate") differentiated by is_active. Deactivating needs no
     * new pricing/routing logic: ADR-034's storefront dedup already
     * filters to is_active=true packages before picking the cheapest
     * match per (game_id, denomination), so turning this supplier's
     * packages off is sufficient by itself for a healthy supplier's
     * equivalent package to take over.
     *
     * Reactivate is scoped to deactivated_reason='supplier_issue'
     * only — never a blanket is_active=false→true for the supplier,
     * since that could also re-activate a package Price Sync
     * deactivated for an unrelated reason (supplier_sync/price_anomaly),
     * silently bypassing ADR-025's swing-review safety net.
     */
    public function updatePackagesStatus(BulkUpdateSupplierPackagesStatusRequest $request, Supplier $supplier, SupplierPackageStatusService $packages): JsonResponse
    {
        $gameId = $request->validated('game_id');

        return response()->json($request->validated('is_active')
            ? $packages->reactivate($supplier, $request->user()->id, $gameId)
            : $packages->deactivate($supplier, $request->user()->id, $request->validated('reason'), $gameId));
    }

    /**
     * @return array<string, mixed>
     */
    private function visibleConfig(Supplier $supplier): array
    {
        $definition = SupplierConfigSchema::fieldsFor($supplier->driver);
        $apiConfig = $supplier->api_config ?? [];

        $visible = [];
        foreach ($definition as $key => $type) {
            if ($type === 'secret') {
                continue;
            }

            $visible[$key] = $apiConfig[$key] ?? null;
        }

        return $visible;
    }

    /**
     * @return list<string> secret-type keys (per SupplierConfigSchema)
     *                      that actually have a non-empty value —
     *                      never the value itself, same $hidden
     *                      boundary visibleConfig() already respects.
     */
    private function configuredSecretKeys(Supplier $supplier): array
    {
        $definition = SupplierConfigSchema::fieldsFor($supplier->driver);
        $apiConfig = $supplier->api_config ?? [];

        return collect($definition)
            ->filter(fn (string $type) => $type === 'secret')
            ->keys()
            ->filter(fn (string $key) => ! empty($apiConfig[$key] ?? null))
            ->values()
            ->all();
    }

    /**
     * ADR-046 addendum — the card's "Sandbox"/"Production" badge reads
     * this rather than the frontend hardcoding a per-supplier field
     * name ('sandbox' for Gamevion, 'testing' for Digiflazz): whichever
     * key SupplierConfigSchema marks 'boolean' for this supplier *is*
     * its sandbox/testing-mode flag, by this codebase's own convention
     * (see SUPPLIER_FIELD_DEFINITIONS' mirrored comment). Null when a
     * supplier has no such field at all, not false — "unknown" and
     * "definitely production" are different things.
     */
    private function isSandbox(Supplier $supplier): ?bool
    {
        return SupplierConfigSchema::isSandbox($supplier->driver, $supplier->api_config ?? []);
    }

    /**
     * @return array{packages: int, supplier_products: int, orders: int}
     */
    private function referenceCounts(Supplier $supplier): array
    {
        return [
            'packages' => Package::query()->where('supplier_id', $supplier->id)->count(),
            'supplier_products' => DB::table('supplier_products')->where('supplier_id', $supplier->id)->count(),
            'orders' => DB::table('orders')->where('supplier_id', $supplier->id)->count(),
        ];
    }
}
