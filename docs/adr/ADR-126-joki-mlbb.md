# ADR-126: Joki MLBB — owned-brand `/joki` page, a `Fixed` pricing basis, each penjoki a pay-after manual supplier with its own WhatsApp group, payout booked as cost

> **Standing (2026-10-17):** Accepted (design). Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted (design), 2026-10-17. Grilled with the founder (`/mattpocock-skills:grilling`, Q-A to Q-J plus the earlier scoping answers), after a money-critical trace in three read-only forks (joki checkout/pricing, supplier funding/accounting, reports/analytics), top findings re-checked by hand. Builds on [ADR-125](./ADR-125-login-top-up-manual-supplier.md) (manual suppliers, finalize seam, supplier groups, its 2026-10-17 addendum) and [ADR-124](./ADR-124-customer-account-credentials.md) (credentials). Built after both (PRD §16 item 114).

**Context:**

- The founder wants a joki (rank-boosting) service: the customer picks a current and a target MLBB rank, pays, and hands over their login; one of the founder's own penjoki contacts plays the account to the target. **Owned brands (PekanGame) only**, MLBB only to start.
- References (2026-10-17):
  - **rapspoint.com/joki/ranked**, viewed in the founder's browser. Big rank icons with ‹ › to change tier and ▲▼ for stars. Priced **per star at the rate of the tier the star is won in**, minimum 5 stars. Express adds about Rp1,000 per star. Mythic Grading is a flat Rp170,000. Rates (Reguler/Express, IDR per star): Master 6,000/6,500, GM 6,000/7,000, Epic 7,000/8,000, Legend 8,000/9,000, Mythic Romawi 16,000/17,000, Honor 19,000/20,000, Glory 24,000/25,000, Immortal 29,000/30,000. Step 2 asks for login type (Moonton/Facebook/VK/TikTok/Google Play), user ID, username, email, password, hero request, role request and a play-time window.
  - **Gamevion's "Road to Mythic" pricelist** (founder's image): fixed route packages in RM, e.g. Epic II→Mythic RM65, Legend V→Mythic RM55, Mythic→Honor RM150, Mythic→Glory RM275, Mythic→Immortal RM500, Mythic 200/500/1000 stars RM900/2000/3500, Global Hero "PM for quotation".
- **Trace findings (2026-10-17):**
  - Every storefront price is derived **from cost** (`PricingService::calculate`, `calculateForAffiliate`, via `OrderPricingResolver::resolveStorefront`, always called by `CheckoutService::initiate`). Joki fixes the **selling** price and derives the cost. Through the normal chain, the owned brand's wholesale tier (production: "Owned Brand", 10%) would turn RM65 into RM71.50.
  - `OrderResendService::preflight` rewrites `cost_price` from the target package, even for a same-package resend: a joki payout would be silently overwritten.
  - `voucher_discount` is a **payment** by store credit (the customer's own earlier money, ADR-004), not a discount: it lowers neither `selling_price` nor `platform_profit`. A payout based on "after voucher" would book the old store credit as platform profit and underpay the penjoki.
  - The hidden-game rule exists in `AffiliateGame::hiddenGameIdsFor` plus two drifted copies (`Affiliate/Storefront/PricingController::visiblePackages`, `StorefrontGameController`). `AffiliateGame` is opt-out, so a new game is visible and checkout-able on every affiliate host.
  - About 15 readers use `package->name`; `CheckoutController` does `findOrFail` on `package_id`.
  - `finalizePendingDelivery` reads `$locked->supplier` at finalize time; the only post-checkout writer of `orders.supplier_id` is the resend package swap. Changing `supplier_id` on a Pending order before any drawdown is safe under a lock.
  - No per-order notes or timeline model exists. Customer WhatsApp events are one method each on `CustomerNotificationService` (ADR-116), sent from the customer-support session.
  - A Failed order never draws down; `recordManualAdjustment` must reference an existing transfer and adds to operating profit with a + sign, so a partial-work payment cannot go through Supplier Funding cleanly.

**Decision:**

*Catalog, pricing, checkout*

1. **A joki order is an ordinary `Order`** on a "Joki MLBB" game flagged `owned_brands_only`, always pointing at a real `Package`: one "Joki Ranked (calculator)" package and one per route package. Rank detail (from/to tier, division, stars, Reguler/Express, hero request, role, play window) lives in a `joki_orders` side table, and the WhatsApp status card appends it.
2. **New `PricingBasis::Fixed`** via `OrderPricingResolver::resolveFixed(sellingSen, payoutSharePct)`: selling = the server-computed table price, cost = round(selling × share), `affiliate_profit` = 0, `platform_profit` = selling − cost. `CheckoutService::initiate` uses a pre-resolved `PricingResolution` instead of `resolveStorefront`. No tier markup, no member pricing (ADR-125 decision 7: `member_pricing_enabled` off), by construction. Readers of `pricing_basis` (reports' breakdown, `OrdersWorkbook`, report assistant) get a `fixed` label.
3. **Price is computed server-side** (ORD-9) by a `JokiPricingService` from the admin rate table: per tier a per-star rate for Reguler and Express, or a flat price for a flat tier (Mythic Grading), and a minimum star count. The client sends ranks, stars and the Express flag only. Route packages carry an admin-set joki price; a "quotation" package opens WhatsApp instead of checkout.
4. **Payout base = selling price (Q-A, revised).** The penjoki gets share % (panel, default 100) × `selling_price`, which already excludes the gateway fee. The customer may pay by gateway, by refund store credit, or both. No promo codes (ADR-118 not built; joki eligibility decided when it is).
5. **Owned brands only, one seam.** `hiddenGameIdsFor(Affiliate $brand)` also hides `owned_brands_only` games when `! $brand->is_owned`; the two drifted copies route through it; the joki checkout asserts `is_owned` server-side. A platform toggle switches joki off entirely.
6. **Package-swap resend is blocked** for `pricing_basis = fixed`; plain Retry stays.
7. **Express** = a higher per-star rate and priority, with a panel-set description and **no promised time** (Q-F).

*Penjoki, fulfilment, money*

8. **Each penjoki is a manual supplier** (ADR-125 `driver = manual`) with `funding_mode = pay_after` and its own WhatsApp group. Paid joki orders land on a **"Joki – Unassigned"** manual supplier whose group is the founder's own. **"Assign penjoki"** (admin, Pending only, manual → manual, under a lock) sets `supplier_id`; the ADR-125 post job, keyed on (order, reference, supplier), posts the order and a fresh login link to that penjoki's group. Reassigning before completion kills the old link (the signed link re-checks the supplier) (Q-I).
9. **Payout = the order's cost.** `.done` and the admin's Delivered pass the order's `cost_price` snapshot as the real cost (ADR-125 addendum). The drawdown makes the penjoki's ledger negative: the amount owed. Paying a penjoki is a `SupplierTransfer` with a receipt.
10. **Progress (Q-G, Q-H):** `.progress PG-… <tier> <division> <stars>` (Mythic and above: `<tier> <stars>`), plain text, no emoji, validated against the rank model; the bot confirms in the group. The admin can also update from Order Detail. Updates go into `joki_progress_updates` (order, rank, stars, actor, time) and `joki_orders.current_*`. Shown on admin Order Detail (timeline), on the storefront Track Order page (current and target rank, through the customer-safe payload) and by WhatsApp to the customer from the customer-support session through a new `CustomerNotificationService::jokiProgress()`: the first update sends at once, then at most one message per order per 2 hours (panel setting), carrying only the latest.
11. **Refund is always full** as store credit, excluding the gateway fee as for every order. A partial-work payment to a penjoki on a failed joki is a Budget Envelope expense (category "Joki – partial work", order number in the note), a visible platform loss, never a Supplier Funding adjustment (Q-B).

*Customer page and terms*

12. **`/joki`** on owned brands (backend-gated like membership, matched `loading.tsx`): rank icons with ‹ › for tier and ▲▼ for stars, Reguler/Express, the route-package grid, then the data step (ADR-124 credential fields, consent, hero/role/play window), then payment.
13. **"Joki Terms & Rules"** (Q-E): one rich-text field in the joki settings (`RichTextEditor`), shown as a section on `/joki` and linked from the consent checkbox. A full snapshot of the terms is stored on each `joki_orders` row.
14. **Panel settings (admin):** joki on/off, rate table, flat tiers, minimum stars, route packages and quotation flags, login-type options, role list, payout share %, Express description, terms, progress-message interval.

**Rationale:** joki as an Order on a real Package reuses every reader, voucher and report. A `Fixed` basis is the one honest way to price a service whose selling price is the fixed fact. Penjoki as pay-after manual suppliers reuses ADR-125's groups, finalize seam and Supplier Funding, so payables per penjoki need no new ledger.

**Consequence to track:**

- **Launch prerequisites:** research the current MLBB rank model (stars per division, Mythic sub-tiers by star count) and prepare a proposed RM rate table from the rapspoint rates at a stated exchange rate, compared with Gamevion's route prices; the founder reviews it and enters it in the panel.
- Accounting presentation of penjoki payables and the month scope of orders delivered in the next month go to the accountant (PRD §16 item 81).
- Auto-assignment (round robin) and a penjoki login or portal are later addenda, when volume calls for them.
