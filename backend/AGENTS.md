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
  `SupplierAdapter` (`GamevionAdapter` and `DigiflazzAdapter` are the two
  real, production-wired implementations — ADR-030/067; only Digiflazz is
  funded/live since 2026-09-24, Gamevion stays integrated but unfunded; resolved per-order
  by `supplier_id` via `SupplierAdapterFactory`, ADR-031) and `PaymentGateway`
  (`ChipGateway` is the one real implementation since ADR-022's 2026-09-01
  addendum removed Xendit; still resolved per-channel via
  `PaymentGatewayFactory`, which stays for a future multi-region gateway).
  Add a new supplier/gateway by implementing the interface, not by branching
  inside a controller or service.
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
  wrong; dispatch a job instead. Order jobs pick their queue lane from the
  order itself (`Order::orderLane()`: `orders` retail, `orders-reseller`
  wallet, `orders-combo` on the `redis-long` connection — ADR-048's
  2026-09-29 addendum); `HorizonQueueCoverageTest` guards that every queue
  is supervised and every supervisor timeout < its connection's retry_after.
- **An ambiguous supplier outcome is never guessed.** Confirmed Gagal →
  Failed; genuinely unknown → Pending + same-reference poll for a supplier
  whose re-submit replays the stored result
  (`SupplierAdapterFactory::resubmitReplaysOutcome()`, Digiflazz), else
  NeedsReview (ADR-102 + its 2026-09-29 addendum). Never mint a new
  reference for an order that may already have reached the supplier (M-1).
- **Customer messages go only through `CustomerNotificationService`**
  (ADR-116): WhatsApp from the OpenWA `customer-support` session, never
  email, never a direct `OpenWaClient` call. It owns scope, the master
  switch, opt-in, de-duplication (`customer_notifications.dedupe_key`),
  per-brand wording and pacing (the one-worker `whatsapp` lane). A new
  customer-facing event is a new method there. Compare or send a phone
  number only through `App\Support\PhoneNumber`, since stored
  `customer_phone` is raw customer input and is never rewritten.
- **Public API responses never leak internal financial fields** —
  `cost_price`, `standard_selling_price`, `platform_profit`, `affiliate_profit`,
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
- **`Cache::remember()` values should be plain arrays, not a raw Eloquent
  Model/Collection or an un-cast `Carbon` instance** — call `->toArray()` /
  `?->toISOString()` before caching. The cache driver is Redis since ADR-077
  (was `database`), which does *not* corrupt nested objects, so this is now
  belt-and-braces / portability discipline rather than load-bearing — but keep
  following it. Full story: ADR-014's addendum + ADR-077 in `docs/adr.md`.
- **Every mutating route gets a `Http\Requests\*` FormRequest** — structural
  validation (types, `exists:`) lives in the FormRequest; cross-field/DB-
  dependent business rules live in the controller. Don't validate via
  `$request->validate()` inline in a controller for anything non-trivial,
  and never build a model from `$request->all()`.

## Build & Test

```bash
composer run dev                                          # serve + horizon + pail + vite together (needs local Redis — ADR-048)
php artisan test                                           # fast suite (sqlite, no Docker)
docker compose up -d && php artisan test -c phpunit.concurrency.xml  # concurrency suite, needs real MySQL
php artisan app:chip-smoke-test                             # hits the real CHIP API (test-mode key; no separate sandbox URL)
php artisan app:gamevion-smoke-test                         # hits the real Gamevion sandbox
```

## Local dev gotchas (moved from the root `AGENTS.md`, 2026-10-02)

Read these when a local symptom looks like a code bug — each one has fooled
a session before.

1. **Queued work does nothing.** Price Sync / fulfillment / resend need a
   worker. `composer run dev` runs one (`php artisan horizon`); bare
   `php artisan serve` or Herd alone does not. A stuck "Syncing…" is almost
   always this. Two quieter causes of the same symptom:
   - `backend/node_modules` was never installed (`npm install` inside
     `backend/`). The `vite` step fails, and `concurrently --kill-others`
     tears down `horizon` with it, visible only in the backend terminal
     (`docs/build-log-archive.md`, 2026-07-28).
   - Redis isn't up (`docker compose up -d redis`). Horizon has no
     `database` fallback since ADR-048, so its pane shows connection-refused.
     That's easy to miss in interleaved output.
2. **A new migration runs only on the test DBs** (sqlite `:memory:`, the
   concurrency MySQL), never on the local dev DB. So a green
   `php artisan test` proves nothing about the dev DB. A "no such table" or
   "unknown column" error in the browser means: run `php artisan
   migrate:status`, then plain `php artisan migrate`. **Never
   `migrate:fresh`.** It drops every table, and the gitignored dev sqlite has
   no backup (`docs/build-log-archive.md`: the 2026-07-29 Blacklist entry and
   the 2026-08-31 ADR-061 PR-B data-loss incident).
3. **`php artisan serve` drops your exported env vars.** It re-reads
   `.env` and passes through only `ServeCommand::$passthroughVariables`
   (`APP_ENV`, `PATH`, a few Herd/Xdebug vars). An exported `DB_DATABASE`
   is silently ignored, so you serve against the real dev DB with no error.
   Any script booting `serve` with env set outside `.env` needs
   `--no-reload` (`e2e/scripts/boot-backend.sh`, ADR-023).
4. **Capturing artisan output with `$(...)` needs `--no-ansi`.** Symfony
   Console force-adds ANSI codes whenever `GITHUB_ACTIONS` is set, even
   when piped. The captured value is corrupt on CI but clean locally. A
   corrupted `APP_KEY` surfaced only at the first encrypted write, as
   Playwright's generic "webServer was not able to start"
   (`docs/build-log-archive.md`, 2026-08-27).
5. **Local `CACHE_STORE` may be `database`, not `redis`.** `Cache::tags()`
   throws against it. For a local smoke test that touches tag-flushing
   (`GameController::forgetIndexCache()`), run
   `CACHE_STORE=redis php artisan serve --no-reload`.

## Migrations

Migrations: verify new foreign-key columns actually get a standalone index on
real MySQL via `SHOW INDEX FROM <table>` — `foreignId()->constrained()` has
already been found, once, to not reliably leave one on its own (see the
`packages.supplier_id` fix, `docs/adr.md`). Also check any new multi-column
`unique()`/`index()` (or a long table name + long FK column) against MySQL's
64-character identifier limit — Laravel auto-names these as
`table_col1_col2..._suffix`, sqlite never enforces the limit so `php artisan
test` won't catch it, and this has already bitten `player_region_mappings`
and `player_validations` once each (see ADR-021's addendum, `docs/adr.md`).
