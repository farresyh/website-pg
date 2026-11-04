# ADR-137: Gamevion v2 API — rewrite the adapter for `gamevion.cloud`, async delivery with a signed webhook and same-reference replay

> **Standing (2026-11-04):** Proposed. When accepted it replaces the API contract of [ADR-040](./ADR-040-gamevion-sandbox-integration.md) (old `api.gamevion.com`, sandbox header, `telp`, `callback_url`) and, for Gamevion only, the `duplicate_reference` → NeedsReview routing of [ADR-026](./ADR-026-delivery-side-reconciliation-ord-10.md). Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record.

**Status:** Proposed, 2026-11-04. Decisions 1–4 were made with the founder (in Malay) on 2026-11-04. Decisions 5–12 are Claude's draft from the vendor's API document and a first code-trace; they go through the technical grill and the money code-trace (AGENTS.md lifecycle step 2) before Accepted. The open questions at the end are settled in that grill.

## Context

- Gamevion moved to a new system. Its API document, "Gamevion Dokumentasi API" v62.25 (generated 2026-10-25, held by the founder, not in the repo), describes a different API at `https://gamevion.cloud`. The founder confirmed on 2026-11-04 that the old API no longer takes orders: an order sent there fails.
- Read-only on prod, 2026-11-04: in 14 days, 79 of 672 Price Sync runs were `partial`, and every one was Gamevion failing to connect to `api.gamevion.com` (cURL 7, connect timeout, SSL timeout). On 2026-11-04 (UTC) it was 19 of 29 runs. Digiflazz never failed. Gamevion has 71 packages, none active, and no order has ever been routed to it, so no customer was affected. The founder switched the Gamevion supplier off in `/middleware` on 2026-11-04 (14:11 UTC), so Price Sync skips it.
- The founder keeps Gamevion as the backup supplier. Backup means an admin points a package at Gamevion by hand; there is no automatic failover.
- Today's `GamevionAdapter` treats Gamevion as synchronous: a create-order answer is final, there is no webhook, and any failure is ambiguous (`resendUnsafeWithSameReference`, never confirmed), so every Gamevion failure goes to NeedsReview.

What the new API offers (from the document):

| | Old (`api.gamevion.com`) | New (`gamevion.cloud`) |
|---|---|---|
| Auth | Bearer token + `X-API-KEY` | One key, `Authorization: Bearer gvk_…`, optional IP allowlist |
| Sandbox | `X-ENVIRONMENT: sandbox` | None documented |
| Catalog | One `POST /api/product` | `GET /api/games` (code, `kind`), then `GET /api/pricelist/{code}` per game (`sku`, `price` MYR, `available`); pricelist limited to 40/min, refreshed every 15 s |
| Player fields | `data: id\|server`, `telp` | `GET /api/games/{code}/fields`: `userid`, `serverid`, `charname` as required per game |
| Order | `product_code`, `referenceNumber`; answer is final | `POST /api/order` with `game`, `denom` (sku), player fields, `client_ref` (≤ 64, `[A-Za-z0-9_.:-]`), optional `quantity` 1–5 and `max_price`; answer is usually `pending` |
| Final status | none | `pending` → `successful` / `error` / `cancelled` / `refunded`; webhook `order.updated`, or `GET /api/orders/{order_id or client_ref}` |
| Idempotency | 409 with no status | Same `client_ref` again returns the original order with `duplicate: true`, never a second charge; a concurrent repeat gets 409 `request_in_progress`; `GET /api/orders/{client_ref}` 404 means it was never created |
| Webhook | none | `X-Gamevion-Signature: v1=` HMAC-SHA256(secret, timestamp + "." + raw body), reject if the timestamp is over 5 min old; reply 2xx within 8 s; retried 3 times (5 s, 30 s, 5 min); may arrive more than once |
| Errors | undocumented | JSON `code`: `invalid_request` 400, `unauthorized` 401, `insufficient_balance` 402, `forbidden` 403, `not_found` 404, `price_changed` / `duplicate_order` / `request_in_progress` 409, `invalid_player_id` 422 (no charge), `rate_limited` 429, `service_unavailable` 5xx |
| Validate | none | `POST /api/validate` (player ID + nickname) |

## Decision

Founder decisions (2026-11-04):

1. **Rewrite the adapter for the new API; keep Gamevion as the backup supplier.** Build after PRD §16 item 28, whose trigger falls on 2026-11-05.
2. **v1 covers `kind = gametopup` only.** `vialogin`, `manual` and `giftcard` are left out until a need shows.
3. **No `max_price` in v1**, the same as Digiflazz today (`DigiflazzAdapter` sends none). Margin is protected the way it is for Digiflazz: Price Sync every 30 min, the drift guard, and the real cost recorded at delivery (ADR-111/134). Add it later if a real order shows a price jump.
4. **No top-up to test for now.** Build and verify without spending (decision 11). Every Gamevion package stays inactive until one real test order has been delivered, after the founder funds a small amount.

Draft for the grill (Claude, 2026-11-04):

5. **Same driver, same supplier row.** Rewrite `GamevionAdapter` in place under driver `gamevion` (supplier #1). The old API is dead and no order ever used it, so there is nothing to keep. `api_config` becomes `base_url`, `api_key` (secret), `webhook_secret` (secret); `bearer_token` and `sandbox` go. The `SupplierAdapter` interface does not change.
6. **Product reference is `{game}:{sku}`** (for example `mlbb_sgmy:86`), because a sku is unique only inside its game. `listProducts()` reads `/api/games`, keeps `gametopup`, then each game's pricelist, paced under the 40/min limit. `available: false` maps to an inactive status. The 187 old catalog rows and 71 packages are relinked by an admin in the Product Manager.
7. **Async, like Digiflazz.** `createOrder()` maps `pending` to `SupplierResponse::pending()`, `successful` to success, and `error` / `cancelled` to a confirmed failure (`outcomeConfirmedFailed`, Gamevion has returned the balance). The final status arrives by webhook, with the existing poll as backstop.
8. **`checkStatus()` re-submits the same order with the same `client_ref`**, exactly as `DigiflazzAdapter::checkStatus()` re-submits with the same `ref_id`. A duplicate answers with the stored order and its current status; a missing order is created once. So `gamevion` joins `SupplierAdapterFactory::RESUBMIT_REPLAYS_OUTCOME`, and an ambiguous outcome (timeout, 5xx, `request_in_progress`) goes to Pending with a same-reference poll instead of NeedsReview (ADR-102 addendum). `client_ref` is our `reference_number` (`REF-` + ULID, 30 characters, valid), or a combo leg's own reference.
9. **A webhook endpoint, `POST /api/webhooks/gamevion`**, mirroring `DigiflazzWebhookController`: verify the signature on the raw body with `hash_equals`, reject a timestamp over 5 minutes old, find the order or combo leg by `client_ref`, check it belongs to the Gamevion supplier, and finalize through `OrderFulfillmentService::finalizePendingDelivery()` / `finalizePendingDeliveryLeg()`, which already tolerate a repeat. Reply 2xx fast; no supplier call on the request thread.
10. **`refunded` after `successful` never auto-fails a delivered order.** It is logged and raised to an admin, because the customer may already have the credit.
11. **Verified without money:** the free endpoints (`/api/balance`, `/api/games`, `/api/pricelist`, `/api/games/{code}/fields`, `/api/validate`) through the real adapter, a webhook signed with our own secret against our endpoint, and an order sent at RM0 balance that must answer 402 `insufficient_balance` (no charge). The first successful delivery, its webhook and its real price are proven only by the funded test order of decision 4.
12. **Error mapping:** `invalid_player_id` 422, `not_found` 404 and `invalid_request` 400 are confirmed failures (no order made, no charge). `insufficient_balance` 402 is a confirmed failure too, but `price_changed` cannot occur without `max_price`. `unauthorized` / `forbidden` trip the circuit breaker like any auth failure. 429 and 5xx are transient.

## Rationale

- The new API is the stronger contract: idempotent `client_ref` replay with a status, a documented error catalogue and a signed webhook let Gamevion run through the same proven async seams as Digiflazz, and an ambiguous Gamevion order stops needing a human (today every failure is NeedsReview).
- Rewriting behind `SupplierAdapter` keeps the seam (AGENTS.md: extend an implementation, not the interface); fulfillment, ledger and pricing code learn nothing new about Gamevion.
- `gametopup` only and no `max_price` keep v1 to what the platform already sells.

## Consequence to track

- **Money code-trace before Accepted** (AGENTS.md step 2): every writer and reader of `supplier_ref`, `reference_number`, real cost (`OrderFulfillmentService`, ADR-111, ADR-134's `LandedCostRate` face value for an MYR supplier), the resend and combo paths (ADR-105, ADR-107), and every `slug = 'digiflazz'` scope in the reconcile sweep and webhook that the replay decision must widen or deliberately keep.
- **Open questions for the grill:** does a `client_ref` stay unique beyond the 31 days `GET /api/orders` covers; does Gamevion's balance check come before field validation (decides what the 402 test proves); games whose `/fields` require `charname` (our order has no such field: exclude them in v1?); which outbound IP Gamevion should allow (the box, or `SUPPLIER_PROXY_URL` when the proxy is on); `insufficient_balance` as Failed or retriable after a top-up (Digiflazz's ADR-098 table has the same case); the pacing of a full catalog sync under 40/min.
- **Founder steps at build time:** generate a `gvk_` key, set the IP allowlist, set the webhook URL and keep the secret, then enter the key and secret in `/middleware` → Suppliers and switch Gamevion back on.
- ADR-115's single-supplier scope stays: Gamevion is still unfunded.
- `GamevionSmokeTest`, `SandboxOrderController` and the Developer Tools read the old config shape; they change with the adapter.
