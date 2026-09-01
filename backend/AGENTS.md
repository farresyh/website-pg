# backend/ — Laravel API

See the repo-root `AGENTS.md` first for project-wide conventions. This file is
Laravel-specific, loaded only when working inside `backend/`.

## Conventions specific to this app

- **All money is an integer in sen** (RM 1.00 = `100`), never a float —
  `cost_price`, `standard_selling_price`, `final_amount`, everything. Pricing is
  computed via `App\Services\Pricing\PricingService` /
  `CheckoutTotalService`, never hand-rolled inline.
- **Balances are ledger-only, never a mutable column** (ADR-002) —
  `App\Services\Ledger\LedgerService` is the sole writer to `ledger_entries`.
  Anything that moves money (withdrawal approval, voucher issuance, order
  profit) goes through it.
- **No cash refunds, ever** (ADR-004) — a failed/refundable order becomes a
  store-credit `Voucher`, not a reversed payment.
- **Supplier and payment integrations are behind adapters** —
  `SupplierAdapter` (`GamevionAdapter` is the one real implementation) and
  `PaymentGateway` (`ChipGateway` is the one real implementation since
  ADR-022's 2026-09-01 addendum removed Xendit; still resolved per-channel
  via `PaymentGatewayFactory`, which stays for a future multi-region
  gateway). Add a new supplier/gateway by implementing the interface, not
  by branching inside a controller or service.
- **Money-critical concurrency is lock-guarded, proven with real
  subprocess tests** — see `LedgerService::withdraw()`,
  `VoucherService::redeem()`, `OrderFulfillmentService::fulfill()`, all
  `DB::transaction()` + `lockForUpdate()`, each backed by a
  `tests/Concurrency/*ConcurrencyTest.php` that spawns a second real process
  (`DatabaseMigrations`, not `RefreshDatabase` — see AGENTS.md's gotcha).
  Follow this pattern for any new code that touches balance or delivery
  state under possible concurrent access (e.g. duplicate webhook delivery).
- **Fulfillment is queued, never inline on the request/webhook thread**
  (ADR-014) — `FulfillOrderJob`/`ResendOrderDeliveryJob`/
  `SyncSupplierPricesJob`. A controller that needs to call a supplier or
  payment gateway synchronously on the customer-facing path is very likely
  wrong; dispatch a job instead.
- **Public API responses never leak internal financial fields** —
  `cost_price`, `standard_selling_price`, `platform_profit`, `reseller_profit`,
  `supplier_response`, `payment_ref` stay out of any customer-facing
  endpoint (`CatalogController`, `TrackOrderController`). Mirror their
  existing narrow-response-shape pattern for new public endpoints. Raw
  customer contact (`customer_name`/`customer_email`/`customer_phone`)
  stays out too — the one exception is `TrackOrderController` /
  `OrderStatusUpdated`, which carry the buyer's own contact **masked**
  via `App\Support\ContactMask` plus the customer-facing money they saw
  at checkout (`payment_method`/`selling_price`/`voucher_discount`/
  `transaction_fee`), per ADR-065. `standard_selling_price` / profit /
  `payment_ref` are still never exposed there.
- **`Cache::remember()` values must be plain arrays, never a raw
  Eloquent Model/Collection or an un-cast `Carbon` instance** — the
  `database` cache driver silently corrupts nested objects on the next read.
  Call `->toArray()` / `?->toISOString()` before caching. Full story: ADR-014's
  addendum in `docs/adr.md`.
- **Every mutating route gets a `Http\Requests\*` FormRequest** — structural
  validation (types, `exists:`) lives in the FormRequest; cross-field/DB-
  dependent business rules live in the controller. Don't validate via
  `$request->validate()` inline in a controller for anything non-trivial,
  and never build a model from `$request->all()`.

## Build & Test

```bash
composer run dev                                          # serve + queue:listen + pail + vite together
php artisan test                                           # fast suite (sqlite, no Docker)
docker compose up -d && php artisan test -c phpunit.concurrency.xml  # concurrency suite, needs real MySQL
php artisan app:chip-smoke-test                             # hits the real CHIP API (test-mode key; no separate sandbox URL)
php artisan app:gamevion-smoke-test                         # hits the real Gamevion sandbox
```

Migrations: verify new foreign-key columns actually get a standalone index on
real MySQL via `SHOW INDEX FROM <table>` — `foreignId()->constrained()` has
already been found, once, to not reliably leave one on its own (see the
`packages.supplier_id` fix, `docs/adr.md`). Also check any new multi-column
`unique()`/`index()` (or a long table name + long FK column) against MySQL's
64-character identifier limit — Laravel auto-names these as
`table_col1_col2..._suffix`, sqlite never enforces the limit so `php artisan
test` won't catch it, and this has already bitten `player_region_mappings`
and `player_validations` once each (see ADR-021's addendum, `docs/adr.md`).
