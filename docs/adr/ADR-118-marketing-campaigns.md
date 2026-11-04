# ADR-118: Marketing Campaigns — multi-use KOL discount codes, separate from `Voucher`

> **Standing (2026-11-04):** Accepted (design); v1 scope is the 2026-11-04 addendum (V1–V20). Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted (design) — 2026-10-02, grilled across four rounds via `/mattpocock-skills:grilling`, plus a dedicated adversarial stress-test pass that caught and corrected two real design flaws before any code was written (decisions 9 and 11 below). Build not started.

**Context:**

- The founder wants to pay KOLs/influencers a marketing budget to promote PekanGame, but the KOL does not want to run their own branded Affiliate storefront (ADR-060) — there is no existing way to attribute an order to a specific KOL's promotion, which makes it impossible to justify or compare marketing spend across KOLs.
- Two industry-standard attribution mechanisms were weighed: a tracked referral **link** (leaky — this storefront is guest-checkout only, ADR-011, with no persistent customer identity to survive a cross-device or return-later gap, unlike a logged-in marketplace) versus a **discount code** (ties directly to a real, captured checkout action — more reliable here specifically because of the guest-checkout architecture, not despite it). Decision: code is the authoritative revenue/ROI signal; a link is kept only as a soft, decoupled traffic/click counter for funnel diagnostics (see decision 14) — never used for revenue attribution, so no cookie or cross-device tracking is needed at all.
- The existing `Voucher` model (ADR-004/ADR-024) is architecturally a single-customer **compensation credit** — `vouchers.customer_email` is a required column from its first migration, and `VoucherService::assertUsable()` hard-rejects any redeemer who isn't the original named customer. It cannot become a multi-use marketing code without breaking that invariant, which a real refund/compensation flow still depends on. **`Voucher` is explicitly untouched by this ADR** — this is a new, parallel model family.
- Real-world benchmarking (2026-10-01/02): live-tested a direct competitor's (`warungvamos.com`) KOL promo code ("Kiktzy") across three live price points (RM4.22→RM0.21, RM447.64→RM22.38, RM4,236.73→RM211.84) — confirmed exactly **5% flat, linear, uncapped**. Cross-checked against this platform's own real production package margins (SSH query, 1,191 active packages): markup range 9.97%–16.22%, median 15%. The breakeven markup for a 5% price discount to avoid an outright order-level loss is `discount / (1 - discount)` = 5.26% — every active package clears that line, so a 5% discount can only compress Platform's own margin (e.g. 15%→~9.25%), never flip a Platform-funded order negative.
- Shopee/TikTok-style **minimum-spend** thresholds were considered and rejected: that mechanic exists specifically to cap the effective discount % of a **flat-RM** voucher and to inflate multi-SKU basket size — neither reason applies here, since this discount is %-based (self-scaling, already margin-safe at any order size per the above) and this platform sells single-item fixed-denomination top-ups (no "add another item" basket-building behaviour).
- An adversarial stress-test pass (after the main grill) found two real design flaws, both corrected before Accepted status (see decisions 9 and 11): a naive "live-join against a mutable attribution tag" would have let a customer's later campaign switch silently rewrite earlier, already-reported months' attributed revenue — violating this codebase's own established "freeze at the moment" convention (ADR-105 decision 3, the same discipline `orders.markup_percent`/`wholesale_markup_pct` already follow). And a naive "decrement budget on redeem, never reverse" would let a string of failed-delivery orders silently burn a KOL's entire budget cap without ever generating real delivered revenue.

**Decision:**

1. **New, separate model family: `Campaign` → many `PromoCode`.** `Campaign` is the reporting/grouping parent (one KOL's marketing push); each `PromoCode` under it is one redeemable code (a campaign may run 2–3 codes at once, e.g. one per platform — TikTok vs Instagram — to measure which performs better). `Voucher`/`VoucherService` are not modified; a new `CampaignService` owns this entire family, matching this codebase's one-service-per-domain-concept convention (`AGENTS.md` — extend via a new implementation behind a new seam, don't reshape `VoucherService`'s existing one).
2. **Redemption lock, layered:** the game account ID being topped up (hard-to-fake anti-abuse backstop — a real external asset, unlike a freely-typed email/phone) **and** customer email-or-phone (soft identity lock, matching `Voucher::assertUsable()`'s existing "same customer" definition, used here for attribution rather than exclusivity). No OTP verification layer on redemption — residual abuse risk is accepted as bounded by each `PromoCode`'s own budget cap (decision 8).
3. **Discount: a flat percentage per `PromoCode`** (benchmarked at 5% against the real competitor and this platform's own real margin data, above), applied only to the **Standard** price. **No per-order RM cap** — benchmarking showed the competitor runs theirs fully uncapped, and this platform's own margin data proves no order-level loss is possible regardless of order size. **No minimum-spend threshold** (see Context — the two reasons that mechanic exists don't apply here).
4. **Never stacks with Member pricing.** When a signed-in member applies a `PromoCode`, checkout is forced to the Standard basis by the backend simply never passing `membershipId` into `OrderPricingResolver` for that request — a server-side decision, never inferred from what the client happens to omit. This is "free": `OrderPricingResolver::resolveStorefront()` only ever resolves the Member basis when a non-null `membershipId` is supplied, and `decrementMembershipQuotaIfApplicable()` already no-ops whenever `order.membership_id` is null — no new quota-skip mechanism needed. Storefront `ReviewModal.tsx` reuses its existing `chargedAsMember`-driven branch (the same visual path already used for "member quota insufficient, fell back to standard price") plus one new, neutral/info-styled (not danger-styled — this is the customer's own informed choice, not a limitation) explanatory message. Rationale for the exclusion itself: Member pricing and a `PromoCode` are both *fresh* discounts off cost — stacking two fresh discounts on one order is the one combination that genuinely compounds margin risk (unlike decision 5 below).
5. **DOES stack with `Voucher`.** A `Voucher`'s liability was already booked as a lump debit the moment it was *issued* (compensating an earlier failed order) — redeeming it later costs Platform nothing new regardless of what else is applied to that redemption order, so it is economically inert alongside a `PromoCode` discount (confirmed by tracing the ledger: only the `PromoCode`'s own discount reduces `order_profit` at redemption; the `Voucher` portion is purely a payment-method split, zero incremental profit impact). This matches `Voucher`'s already-existing (and now-understood-to-be-safe) behaviour of stacking with Member pricing today, for the identical reason. Storefront UI (decision 15) keeps the existing "Voucher Code" field exactly as-is and adds a progressive-disclosure "+ have a promo code?" link beneath it, revealing a second field — not two always-visible fields, and no change to the proven Voucher flow.
6. **Admin-only creation for v1** — no self-service campaign creation from the Reseller/Affiliate portal, and no Super-Admin approval threshold the way `Voucher`'s standalone path has (VCH-6, `VOUCHER_MAKER_CHECKER_THRESHOLD_SEN`, RM500 default). A `Voucher`'s threshold exists because any admin/support staff can issue one for a day-to-day refund; a `Campaign` is a deliberate, infrequent strategic marketing decision the founder makes personally, so an added approval gate has no real principal to protect against.
7. **Budget cap lives on `PromoCode`, not `Campaign`.** Explicitly rejected a shared campaign-level pool: if two codes under one campaign (e.g. two KOLs, or one KOL's two platforms) drew from a shared budget, whichever redeemed faster would exhaust it first and starve the other — not because it performed worse, but because it happened to run first. A fair side-by-side comparison (the entire point of running multiple codes under one campaign) requires each code to have its own ring-fenced budget. `Campaign`-level reporting (decision 13) still rolls all its codes' figures up for an overall total.
8. **At budget exhaustion: hard, binary reject — never a partial-honoured discount.** Simpler to build, simpler to reason about, and consistent with decision 10's specific customer-facing message (a partial discount has no clean way to explain itself in one line). The check-and-increment against the cap happens inside one `lockForUpdate()`-guarded transaction at redemption — the exact concurrency pattern `VoucherService::redeem()` already uses and that this codebase already has a dedicated concurrency test for (`VoucherRedeemConcurrencyTest.php`) — not a new concurrency mechanism.
9. **Budget consumption follows a reserved → committed/restored lifecycle, mirroring `VoucherService::commit()`/`restore()` exactly — not a blind decrement.** *(Stress-test correction — the original design decremented the budget at redemption with no reversal path.)* A `PromoCode` redemption reserves its discount against the cap at checkout time (payment success), exactly like `VoucherService::redeem()` does; `CampaignService::commit()` is called from the same `OrderFulfillmentService` call sites that already call `VoucherService::commit()` on successful delivery, and `CampaignService::restore()` from the same sites that already call `VoucherService::restore()` on payment-never-completed or delivery-abandonment. Without this, a run of failed-delivery orders (each still compensated normally via `Voucher`, per ORD-11's independent payment/delivery state machines) would silently burn a KOL's entire budget without ever producing one delivered, revenue-generating redemption — directly undermining the ROI-justification goal this whole feature exists for.
10. **Customer-facing message on an exhausted/expired code is specific, not generic.** Unlike `Voucher` (deliberately generic/indistinguishable, to defeat enumeration probing of a *private* per-customer code — `foundation-security.md` §4), a `PromoCode` is *public* by design (the KOL shares it openly), so enumeration-obscurity has no value here; a clear "this promo has reached its limit" is better trust/UX than a cold "invalid code" that reads as a typo or a broken/scam code.
11. **Attribution is snapshotted onto every order at creation time, immutable thereafter — not read live from a mutable tag.** *(Stress-test correction — the original design computed a campaign's reported lifetime customer value via a live join against a per-customer "current campaign" tag that could change later.)* `orders` gains an `attributed_promo_code_id` (nullable FK), stamped once at checkout and never rewritten — exactly the existing pattern `affiliate_id`/`wallet_reseller_id` already use, and the same discipline ADR-105 decision 3 established for `orders.markup_percent`/`wholesale_markup_pct` ("freeze at the moment... so a later [change] doesn't retroactively alter historical reports"). A separate, small, genuinely-mutable table (`customer_campaign_attribution`, keyed on `customer_email`) tracks each customer's **most-recent** code redemption and is consulted only when stamping a **new** order — never read live for past reporting. This gives the founder's requested "most-recent-touch" behaviour (a customer's later orders, even without reapplying a code, attribute to whichever campaign they most recently touched) while keeping every already-reported period's numbers permanently stable.
12. **Funding split: `platform_share_percent` (0–100), set per `Campaign`.** Locked to 100 (not editable) whenever the campaign's target brand has `affiliates.is_owned = true` — this covers **both** the single `is_primary` brand and every other brand the founder personally operates (ADR-061's ownership model has multiple `is_owned` rows, only one of which is `is_primary`) — there is no genuine third-party profit to split in either case, it is all the founder's own money regardless of which internal ledger bucket it lands in. Configurable (suggested default 60/40 platform/affiliate) only when the target is a genuine third-party affiliate (`is_owned = false`) who has agreed to co-fund their own storefront's campaign. **Deliberately no floor at zero** for the affiliate's share — if a chosen split still exceeds a thin-margin affiliate's own markup on a given order, their recorded profit for that order goes genuinely negative rather than being silently absorbed back onto Platform; this keeps the accounting honest and gives the founder a real signal to pick a gentler split for that affiliate's next campaign, matching this platform's own margin-compression finding for Platform itself (decision 3's benchmarking) rather than hiding it.
13. **Ledger integration happens at redemption, per order — not a lump-sum liability at code-creation like `Voucher`'s `voucher_issued`.** A `PromoCode`'s total eventual cost is unknowable at creation (a %, against however many future orders redeem it), so it cannot reuse `Voucher::issue()`'s pattern. Instead, the discount reduces the **`order_profit` ledger credit itself**, split by decision 12's ratio between `LedgerOwnerType::Platform` and `LedgerOwnerType::Affiliate` (when applicable) — written by the same `OrderFulfillmentService::creditProfit()` call that already writes both owner types' `order_profit` rows on successful delivery (confirmed: this is the **only** place "profit" is ever written system-wide; `ReportService`'s own doc-pinned rule says profit is never read off an `Order`'s own snapshot columns, only from `ledger_entries` type `order_profit`). This is deliberately **not** symmetric with `Voucher`: `Voucher`'s liability is booked once, independent of delivery outcome; a `PromoCode`'s cost is only ever recognised against an order that actually generates (reduced) profit, which also means a failed-delivery order automatically contributes zero `PromoCode` cost to the ledger — consistent with how every other channel already behaves on delivery failure, with no special-casing needed.
14. **`ReportService` gains a `campaignBreakdown()` method, following the exact existing shape of `affiliateBreakdown()`/`resellerBreakdown()`** (group `scopedOrders()` by the new `attributed_promo_code_id` → `campaign_id`, `profitByGroup()` for the ledger-sourced, owner-type-split profit side) — Reports remains the one cross-channel source of truth, not a parallel silo, with a drill-down from a campaign's rollup to its individual `PromoCode`s' own figures (the same grouping mechanism, a finer key). `CustomerAnalyticsService` gains a matching optional `campaignId` scope filter alongside its existing `affiliateId`/`resellerId` filters, same `customer_email`-keyed grouping.
15. **A tracking link is a native, lightweight build — no open-source link-shortener/analytics service.** The link is a plain query-param deep link into the existing storefront (`?promo=CODE`), never a redirecting short-link (this platform already owns the destination). A new lightweight table logs one click-event row when the storefront detects the param; the storefront also auto-fills the (decision 5's progressively-disclosed) promo code field from it for convenience. **Deliberately no cookies, no cross-device/session persistence** — the link is explicitly a soft traffic/funnel-diagnostic signal only (click count vs. redemption count, to tell "low traffic" apart from "traffic that doesn't convert"), never a revenue-attribution mechanism; that job belongs entirely to `PromoCode` redemption (decision 11), which is precisely why no cookie engineering is needed at all here.
16. **Admin UI: a new, standalone top-level sidebar group "Campaigns"** (not nested under Affiliates/Resellers, not merged into Vouchers) — mirrors the existing "Accounting" group's shape (a dropdown with several sub-pages, not a flat link) with three sub-pages for v1: **Campaigns** (create/edit, status), **Promo Codes** (list across all campaigns, budget-used/cap progress, redemption count), **Campaign Analytics** (the Reports-style breakdown from decision 14, plus the click-vs-redemption funnel from decision 15).
17. **Terminology pinned for PRD §13 Glossary:** `Campaign` and `PromoCode` — confirmed as clear, non-colliding with existing pinned terms (`Affiliate`, `Reseller`, `Voucher`).

**Rationale:**

The single thread running through almost every decision here is **reuse over invention** — this codebase's own established idioms (snapshot-at-creation FKs, `lockForUpdate()`-guarded reserved/committed/restored redemption, one-service-per-domain, `ReportService`'s grouped-breakdown shape) already solved the exact structural problems this new feature re-encounters, and every genuinely new mechanic here (decisions 12 and 13 especially) was sized to solve a problem those idioms don't already cover, not to replace them. The two stress-test corrections (decisions 9 and 11) are the clearest evidence this discipline matters in practice: both of the original, uncorrected designs would have shipped something that *worked* under casual testing but quietly produced wrong numbers — a wasted KOL budget with no revenue to show for it, and historical reports that silently rewrote themselves — exactly the class of money-critical bug this project's existing conventions (ADR-105's freeze-at-the-moment discipline, `Voucher`'s reserved/restored lifecycle) already exist to prevent.

**Consequence to track:**

- `Voucher` and `PromoCode` are now two genuinely different discount mechanisms with different stacking rules (decisions 4–5) — any future third discount mechanism must have its own stacking interaction explicitly reasoned through against both, not assumed by analogy to either.
- `ReviewModal.tsx`'s `showMembershipPromo` upsell card (the "Become a Member" card shown to non-members) must be confirmed to stay suppressed for an existing member who chooses to apply a `PromoCode` instead of their member price — expected to already be false for any signed-in member via its existing prop wiring, but not yet verified against real code; flag at build time, not assumed.
- Decision 12's funding split, once built, should surface in the affected third-party affiliate's own portal ledger/earnings view (a correctly-typed `order_profit`-reducing ledger entry ought to render there automatically via whatever generic ledger-entry display the portal already has) — confirm this renders sensibly at build time rather than silently reducing an affiliate's balance with no visible explanation.
- This ADR's `ENGINE_TYPE`-is-global discrepancy is unrelated and already tracked separately under ADR-075's 2026-10-02 addendum — not duplicated here.
- No automatic build trigger — per `AGENTS.md`'s task lifecycle, this is "Accepted (design)", not yet scheduled; build begins only when the founder says so, same convention as ADR-115.

### Review addendum — 2026-10-02 (code-trace pass + four grill rounds)

**Status:** Accepted (design). Build not started. **This addendum supersedes the original decisions wherever they conflict**; build from the two together.

**Context:**

- The original ADR passed four grill rounds and a stress test, but its claims about existing code were made by analogy. A later code-trace pass ran three parallel forks (checkout and pricing; ledger, fulfillment and compensation; downstream readers), each reading real code. The model then re-verified the highest-impact findings itself.
- That pass found four gaps that would have produced silently wrong money if built as written, plus a set of smaller ones:
  1. **The ADR never said where the discount is stored.** Four paths recompute profit as `selling_price − cost − affiliate_profit`:
     - real-cost reconcile `OrderFulfillmentService::reconcileRealCostProfit` (ADR-111)
     - the combo residual (ADR-107)
     - `OrderResendService` (ADR-105)
     - `Order`'s cost-basis detection

     If `selling_price` kept the pre-promo price, each of these would add the discount back into platform profit.
  2. **Resend erases the affiliate's share of the discount.** `OrderResendService.php:173-191` recomputes `affiliateProfit` from scratch at full markup and gives the whole remainder to platform. A 60/40 split becomes 100/0 after one resend.
  3. **The margin claim was too broad.** The "5% can never make an order lose money" proof (Context, decision 3) only holds for the Standard basis. On an affiliate store with a wholesale tier, platform profit is only the tier markup (`PricingService.php:64-72`). The best-tier floor is about 3% over cost (ADR-027 decision 14), and it is admin-editable. Worked example: cost RM100, tier 3%, affiliate markup 10% gives a customer price of RM113.30 and platform profit of RM3.00. A 5% discount is RM5.67, so a 60% platform share is RM3.40 and the platform loses RM0.40. Combos whose `combo_override_price` equals cost have zero margin.
  4. **Decision 9's timing claim was wrong.** `VoucherService::redeem()` does not run at payment success. It runs after the CHIP purchase is created (`CheckoutService.php:279-281`), and a failed redeem is logged and the order proceeds anyway (`logAcceptedVoucherRedemptionRace`, `:389`). That is safe for a `Voucher` only because a single customer owns it. A public `PromoCode` raced by many customers would overrun its cap with no bound.
- **Smaller findings:**
  - No redemption-count limit was stated.
  - No brand scoping was stated.
  - There are three separate price calculations (`previewTotal`, `VoucherPreviewController`, `initiate()`).
  - Consequence #2's assumption was wrong: the "Become a Member" card *does* show for a Tier-1 member who applies a promo (`OrderForm.tsx:270`).
  - The admin sidebar has no top-level "Accounting" section to mirror; Accounting is a dropdown inside Commerce.
- The method lesson is now codified in `AGENTS.md` Task Lifecycle step 2 (the money-critical code-trace pass).

**Decision (numbered R1–R16 so they don't collide with the original 1–17):**

- **R1. Storage.**
  - `orders.selling_price` holds the price **after** the promo.
  - New snapshot columns, frozen at checkout and never rewritten: `promo_discount_sen`, `promo_platform_share_sen`, `promo_affiliate_share_sen`.
  - The pre-promo price is `selling_price + promo_discount_sen`.
  - `normal_selling_price` is **not** reused. It stays Member-only, and the Member "margin forgone" readers depend on it (`MembershipController.php:152`, `ReportService.php:422`).
  - This keeps `selling_price`'s existing meaning ("the price this customer was sold at, before voucher") and leaves every existing profit formula correct, along with `final_amount`, the compensation voucher amount (`final_amount − transaction_fee`, `VoucherController.php:248`) and CHIP settlement matching.
  - **`final_amount`'s formula is unchanged:** `selling_price − voucher_discount + transaction_fee`. The promo is already inside `selling_price`, so subtracting it again would count it twice.
- **R2. Order of operations and rounding.**
  - Order: promo, then voucher (capped at the post-promo `selling_price`), then transaction fee (on `selling_price − voucher_discount`).
  - The discount is computed once in sen.
  - The rounding remainder of the split goes to platform, so `promo_platform_share_sen + promo_affiliate_share_sen = promo_discount_sen` always holds.
- **R3. Discount base (clarifies decision 3).** The percentage is taken off the **pre-promo price the customer would pay** (`selling_price` before promo, including any affiliate markup), not off `standard_selling_price`. The customer sees exactly the advertised percentage off the displayed price.
- **R4. Every profit writer must honour the frozen split.**
  - `OrderResendService` subtracts `promo_affiliate_share_sen` from its recomputed `affiliateProfit` on both branches.
  - Each of the four `selling_price`-based writers gets a test proving the split survives it.
- **R5. Eligible bases and loss guard.**
  - Eligible: **Standard** and **Affiliate** (wholesale tier).
  - Rejected: **Member** (decision 4 stands) and **ResellerWallet**. The Reseller API, Bot and portal reject any code explicitly; today they have no code field at all.
  - **Server-side guard on every order:** if `platform_profit` after the platform's share would be `< 0`, the promo is rejected for that item with a clear message. Combos go through the same guard.
  - The campaign-creation screen lists the packages the guard would reject under the chosen split.
- **R6. Affiliate negative profit (decision 12 stands).**
  - The affiliate's share may push that order's affiliate profit below zero.
  - The partner portal labels such ledger rows with the campaign (not a bare "Order margin", `reseller/src/lib/format.ts:25`).
  - `LedgerService::withdraw()`'s existing balance check blocks withdrawal while the balance is negative; no new mechanism is needed.
  - Campaign creation warns when the split would make some of that affiliate's orders negative.
  - An affiliate negative caused by a promo is excluded from ADR-111's profit-drift flag (`isProfitDriftFlagged`), so real cost anomalies stay visible.
- **R7. Budget reservation is fail-closed (corrects decision 9's timing).**
  - The budget is checked and reserved under `lockForUpdate()` **before** the CHIP payment link reaches the customer, and inside `settleWithVoucher()` on the full-voucher-cover path.
  - If the reserve fails, the order is marked Failed and checkout throws, following the M-6 pattern (`CheckoutService.php:200-223`). It is never log-and-proceed.
  - Unpaid checkouts are bounded by the existing `CheckoutVelocityGuard` (per IP, 10-minute window) and CHIP's 30-minute `due_strict` expiry, which restores the budget. A separate "one open promo checkout per email" cap was considered and dropped: email is freely typed and it adds nothing over those two.
- **R8. Budget lifecycle across delivery outcomes (completes decision 9).**
  - **Restore** at the existing payment-level sites: CHIP webhook Failed (`ChipWebhookController.php:133-136`) and `PaymentReconciliationService::markFailed`. Also restore at Issue Voucher / Restore Voucher (`VoucherController.php:311`).
  - **Commit** at the existing four delivery-success sites (wherever `VoucherService::commit()` runs today).
  - **A delivery that ends Failed but is not yet compensated keeps its reservation.** Founder's call: restoring immediately would let a later successful resend overrun an exhausted cap. Holding it keeps the counter exact. These orders already surface in the ADR-108 Need Action KPI, so they are not silently stuck. Neither option creates new monetary loss; the discount was given at payment time. The difference is only the budget counter's accuracy.
  - A CHIP-null-`payment_ref` failure (gateway call failed) restores inside the `CheckoutFailedException` path, because `ReconcilePendingPaymentsCommand` never scans those rows.
- **R9. Redemptions live in their own table (refines decision 11).**
  - New `promo_code_redemptions` (reserved, committed, restored, plus the amount), mirroring the voucher redemption lifecycle. The budget cap, the per-customer limit and the discount cost key on this table only.
  - `orders.attributed_promo_code_id` stays a reporting-only snapshot. It is also stamped on later "most-recent-touch" orders that applied no code, so it must never drive budget.
  - Reports distinguish "redeemed" orders from "attributed only" ones.
- **R10. Redemption limit (fills decision 2's gap).**
  - **Once per `PromoCode` per player ID *or* per email**: a match on either blocks.
  - The count is taken over non-restored redemptions while the `PromoCode` row is locked; a plain unique index can't exclude restored rows.
  - A restored redemption frees the slot, so a customer whose payment failed can retry.
  - Repeat-purchase attribution is already handled by decision 11, so the limit doesn't hurt ROI measurement.
- **R11. Brand scoping.**
  - A `PromoCode` is valid only on its `Campaign`'s target brand, mirroring `Voucher`'s brand lock (`VoucherService.php:372`).
  - `customer_campaign_attribution` is keyed on `(affiliate_id, customer_email)`, not `customer_email` alone.
- **R12. One pricing path.** The promo goes through the shared `computeTotal()` that `previewTotal`, `VoucherPreviewController` and `initiate()` all call, so the ReviewModal total always equals what CHIP charges. The client sends only the code string (ORD-9 holds).
- **R13. Member who applies a promo.**
  - Pricing falls to Standard (decision 4), but the order still takes the member's verified email (`resolveMemberEmail()`) so attribution and Customer Analytics don't split across two emails.
  - The preview returns a `standard_reason` (`quota` | `promo`). The "Become a Member" upsell and the "quota ran out" copy then key on it.
  - The existing `showPromo` / `showMembershipPromo` props (the membership upsell) are renamed first, to avoid a collision with `PromoCode`.
- **R14. Accounting treatment** (founder decision; confirm with the external accounting reviewer before build):
  - The customer discount is **contra-revenue**. Sales are reported net (post-promo), matching CHIP's settled `final_amount`. Monthly Accounting Summary gains a "Promo discounts" line from `promo_discount_sen`.
  - A **direct fee paid to a KOL** (the founder sometimes pays one) is a **marketing expense**, recorded through the Envelope Ledger, not through orders.
  - `Campaign` gains an optional `kol_fee_sen` so Campaign Analytics can show true ROI: attributed profit − discount cost − KOL fee.
- **R15. Reporting hygiene.**
  - The Membership tab's Member-vs-Standard comparison excludes promo orders (`attributed_promo_code_id IS NOT NULL` with a redemption), so promo-driven Standard orders don't skew it.
  - Every reader of `voucher_discount` also shows `promo_discount_sen`:
    - admin Order Detail and CSV export
    - `TrackOrderController`
    - the affiliate order API
    - storefront track-order and checkout
    - the reseller portal order view
    - the LLM assistant's `llm_report_orders` view and its schema prompt
- **R16. Admin UI (replaces decision 16).**
  - A "Campaigns" dropdown inside the existing **Customers** section, next to Customer Analytics (which gains the `campaignId` filter). No new top-level section.
  - Two sub-pages:
    - **Campaigns:** list, create and edit. The detail page holds the campaign's codes (%, budget used vs cap, redemptions), each with an auto-generated `?promo=CODE` tracker link and a copy button, plus that campaign's own funnel and ROI.
    - **Campaign Analytics:** side-by-side comparison of campaigns and codes, date filter, click-vs-redemption funnel, export.
  - The original standalone "Promo Codes" page is dropped. Codes are always managed through their campaign, and cross-code comparison lives in Campaign Analytics.
  - The public click-log endpoint gets `throttle:60,1` (precedent: `/seo/redirects/record-hit`). The storefront reads `?promo=` in the browser so cached pages don't hide it. Clicks are a soft signal only.

**Rationale:** R1 is the root decision. Folding the discount into `selling_price` uses the column's existing meaning, so four existing profit writers, compensation and settlement matching stay correct without being touched. The alternative needs every current and future reader to remember a subtraction. Most remaining decisions follow from "a public, budget-capped code is not a private single-owner voucher":
- fail-closed reserve (R7)
- brand lock (R11)
- explicit per-customer limit (R10)
- redemption table separate from attribution (R9)

The loss guard (R5) replaces a snapshot-based safety proof with a per-order check, because margins and tier markups are admin-editable at any time.

**Consequence to track:**

- Build must include a test per profit writer (R4), a concurrency test for the fail-closed reserve (R7, next to `VoucherRedeemConcurrencyTest.php`), and a test that a restored redemption frees the per-customer slot (R10).
- R14's accounting treatment is the founder's working decision, pending the external reviewer.
- Original consequence #2 (upsell card) is resolved by R13; consequence #3 (affiliate portal display) is resolved by R6.

### 2026-11-04 addendum — v1 for two PekanGame KOLs (founder decisions, code trace, accountant, stress test)

**Status:** Accepted (design), 2026-11-04. Accepted after four inputs:
- the founder's decisions;
- a three-fork money code-trace pass;
- the external accountant's answers to R14;
- an adversarial stress test, also three forks: abuse and concurrency; accounting and ledger; lifecycle and hygiene.

The model re-checked every key finding against code or prod. **This addendum overrides decisions 1–17 and R1–R16 wherever they conflict. Build v1 from all three; the "Superseded for v1" list below names every overridden part.**

**Context:**

- **Trigger met** (PRD §16 item 57): the founder has two KOLs. KOL 1 asks RM500 a month. KOL 2 starts at RM2,000 and is still negotiating. Both are local individuals. Each is paid a fixed monthly fee. The founder's goal: know, per KOL and per month, whether the KOL is worth paying again.
- **The 2026-10-02 margin facts are stale.** On 2026-11-04 every one of the 1,269 active packages has a 10% markup on landed cost (ADR-134), about **9.1% margin on price**. The original assumed a 15% median.
- **Retail scale on prod** (read-only, paid non-wallet orders since 2026-10-05): 41 orders, RM2,338.09, average RM57.03; 24 customers, 10 bought again, 1.71 orders each; 33 of the 41 on OhahaStore. That is about RM8.85 platform profit per customer per month.
  - Breakeven new customers a month, if each buys like today's customers for one month: KOL 1 ≈ 57, KOL 2 ≈ 226. If each keeps buying for three months: ≈ 19 and ≈ 75.
  - The discount is not the main cost: 5% of a RM57 first order is RM2.85, while the fee is RM500 ÷ 57 ≈ RM8.80 per customer.
- **Prod facts from the stress test** (79 non-wallet orders):
  - 1 of 35 player accounts appears under more than one email.
  - 16 of 62 paid orders have no `server_id`.
  - Every stored phone normalises with `PhoneNumber`.
  - 5 customers left a CHIP link to expire (~40 minutes Pending), then checked out again 1–8 minutes later.
- **The accountant's answers (2026-11-04):**
  - The discount is contra-revenue (MPERS Section 23), shown as gross − discount = net.
  - The KOL fee is an advertising expense in the month of service: a prepayment when paid ahead, an accrued expense when paid after.
  - Withholding tax: none for a local individual; 10% for a non-resident.
  - SST is not recoverable, so it is part of the cost.
  - An individual KOL gets a self-billed e-invoice.
  - A free product given to a KOL is a marketing expense at real cost, never sales.

**Superseded for v1:**
- Decision 2's lock and R10: replaced by V5.
- Decision 11's `customer_campaign_attribution` table, and R11's key for it: dropped by V6. R11's brand lock stays.
- Decisions 12–13's funding split, R4 and R6: out of v1 (V1).
- Decision 14: replaced by V14.
- Decision 15's "no persistence": replaced by V16.
- Decision 16 and R16: replaced by V15.
- R7: replaced by V8.
- R8's site list: replaced by V9.
- R12: replaced by V7.
- R14's `kol_fee_sen`: replaced by V12.

**Decision (V1–V20, so the numbers don't collide with 1–17 or R1–R16):**

- **V1. Scope: PekanGame only.**
  - `campaigns.affiliate_id` exists from v1 and is validated as the `is_primary` brand.
  - Owned brands price as the platform (`Affiliate::storefrontPriceTerms`), so the basis is Standard and the platform takes the whole discount.
  - The split (`platform_share_percent`, an affiliate share column, R4, R6) comes back later as additive columns, with its own trace, when a third-party brand first runs a campaign. That needs no backfill: every v1 row has affiliate share 0.
- **V2. Eligible orders.**
  - Standard basis on the campaign's brand only.
  - Rejected server-side: Member (decision 4 and R13 stand), ResellerWallet, Fixed (Joki), membership purchases, and any other brand.
- **V3. Discount.**
  - A percentage per `PromoCode`, taken off the pre-promo price the customer would pay (R3).
  - Optional `max_discount_sen` per code. Null means uncapped, as the benchmarked competitor; the founder chooses per code.
  - Computed once in sen and rounded down.
- **V4. The customer sees RM, never the percentage.**
  - Storefront review and checkout show "Jimat RMx.xx" and the new total.
  - A flat-RM discount was rejected: on the smallest packages (RM0.93, about RM0.08 profit) any useful flat amount is a loss.
- **V5. One KOL discount per customer per brand (replaces decision 2's lock and R10).**
  - **Identity seam.** One matcher, used by V5 and V6 and nowhere re-implemented. Two identities match on any of:
    - email, case-insensitive;
    - phone, via `PhoneNumber::same`;
    - player account: (`games.family`, `player_id`, `server_id`), where a null `server_id` matches null.
  - **A code is rejected when the identity matches any of:**
    - (a) a **recognised** order on the brand: delivered, or a settled partial (ADR-127 `recognised_at`). Reseller wallet orders (`wallet_reseller_id` not null) are excluded, because they carry the primary brand but are not customers. A failed and compensated order is not recognised, so its customer can redeem again.
    - (b) a live (non-restored) redemption of **any** code on the brand;
    - (c) a reseller's registered email or phone.
  - **Locking.** The check runs in the reserve step (V8) under one brand-level lock: the brand's active `PromoCode` rows, locked in id order. That closes the race of two concurrent first orders with the same or different codes.
    - `ponytail:` the phone and player-account match is a PHP scan of the brand's recognised orders (phone is stored as typed). Fine at today's scale; add a stored normalised phone column when the scan shows in Pulse. The lock covers a few codes per brand; move to a per-brand lock row if campaigns multiply.
  - **Messages are generic.** "This code is for new customers" or "This code is in use in an unfinished payment, try again in 30 minutes". They never name an order or say which detail matched. The promo preview runs under the existing checkout throttles.
- **V6. Attribution: a customer belongs to the one KOL code they redeemed (replaces decision 11's most-recent-touch table).**
  - With V5 a customer can hold only one redemption per brand, so "most recent" and "only" are the same.
  - `orders.attributed_promo_code_id` is stamped once at order creation: the code redeemed on that order, or, for a later order, the code of a **committed** redemption whose identity matches through the V5 seam on that brand.
  - The stamp is never rewritten. Reports show redeemed and attributed-only orders apart.
  - `customer_campaign_attribution` is not built.
- **V7. One pricing seam (replaces R12).**
  - `CheckoutService::computeTotal()` is already shared by `initiate()` and `previewTotal()`. `VoucherPreviewController`, which prices on its own today, is routed through `previewTotal()`.
  - The promo is applied to the pricing resolution **before** `computeTotal()`, lowering both `sellingPriceSen` and `platformProfitSen` in the draft that `OrderFactory` writes. Applying it after the draft would leave `platform_profit` pre-promo: ledger profit would be overstated where real cost is missing, and ADR-111 would flag every promo order for profit drift.
  - The preview request gains `promo_code`, `player_id`, `server_id` and phone.
  - The checkout idempotency `payloadHash` includes `promo_code`.
- **V8. Reserve (replaces R7).**
  - **Where.** The promo is a third instrument in `reserveOrClose()` / `reserveAll()` (ADR-024 2026-10-04 addendum): after the CHIP link exists, before the customer sees it, and on the full-voucher path (`settleWithVoucher`).
  - **Lock order:** Order → Voucher → Membership → the brand's PromoCodes. If the promo loses, the voucher and quota roll back and the order closes, as today.
  - **Checks at reserve:** the code is active, inside its validity window, and has budget for this order's discount (decision 8's binary reject). A later change to the code never revokes a reservation already made.
  - **Cancel and replace.** Suppose the identity's live redemption is held by an unpaid Pending order whose email **and** phone **and** player account all equal the new checkout's. Then the new checkout cancels the old CHIP purchase (`PaymentGateway::cancelPayment`), `applyFailed` restores the old reservation, and the new order reserves. The customer sees "your unfinished payment was cancelled".
    - With any detail different, the generic in-use message is shown instead. So knowing a stranger's phone or player ID cannot cancel their checkout.
    - An old link paid anyway follows V9's Paid-after-Failed rule.
- **V9. Commit and restore (replaces R8's site list).**
  - **Commit** at the four delivery sites that commit a voucher today, each paired with `creditProfit`. They are pulled into one private `commitInstruments()` in `OrderFulfillmentService`, which commits the promo too.
  - **Restore** in `OrderPaymentOutcomeService::applyFailed` (the one seam the CHIP webhook, the reconciliation sweep and `SettlementRecheckService` share) and in `OrderSettlementService::settle`. A partial delivery restores its undelivered share, mirroring the voucher.
  - **A commit on a restored redemption re-reserves it unconditionally.** This is the Paid-after-Failed order delivered by an admin. The re-reserve never throws or rolls back a delivery, may exceed the budget or the one-per-customer rule, and flags the code ("over cap by RMx"). The discount was already given.
  - R8's hold of a Failed-but-uncompensated reservation stands.
- **V10. Loss guard with a margin floor (refines R5).**
  - The promo is rejected for an item when platform profit after the promo would fall below the panel-set minimum margin (V19, default 2% of the post-promo price). Combos whose override price equals cost fall to the same guard.
  - The checkout guard sees catalog cost only; real cost and FX drift land later (ADR-111). At a 9.1% margin, a 5% promo leaves about 4.1% of headroom.
  - The code form lists the packages the guard would reject at the chosen percentage.
- **V11. Budget cap per code stands (decisions 7–8).** It bounds the total discount. V3's optional per-order maximum stops one large first order from taking the whole budget.
- **V12. KOL fee: an agreed fee schedule plus tagged payments (replaces R14's `kol_fee_sen`).**
  - **Why.** The accountant requires the fee in its month of service. The Envelope Ledger is dated by cash (`transaction_date`), so a payment alone cannot carry that. This follows affiliate commission: the expense comes from the obligation, the cash from the payout.
  - **Schedule (the agreement).**
    - A campaign cannot be created without a fee row. RM0 is allowed, and the first row starts in an open month.
    - A row is a monthly fee in sen, a start month and an optional end month. The fee includes SST when the KOL charges it.
    - Rows are append-only with an effective-from month. A renegotiation adds a row; a package ("RM1,500 for 3 months") is entered as its monthly fee with an end month, with any remainder sen in the last month.
    - Only `end_month` can be set after creation, and only to an open month. Nothing that touches a closed month can change (`AccountingPeriod::assertOpen`).
  - **Payments (the cash).**
    - Recorded on the Envelope Ledger as today, as `Expense` or `DirectorPaidExpense`, so envelope balances and the director's loan stay right. Category `Advertising`, plus a nullable `campaign_id`. No new posting type.
    - A payment can be tagged only to an existing campaign. `BudgetEnvelopeService::write()` takes `campaign_id`, and `void()` copies it to the reversal.
    - A campaign's paid total = Σ its tagged postings, reversals included.
    - The form previews the balance after the payment ("Prepaid RM1,000, covers to Jan 2027"), so a wrong schedule shows before saving.
  - **Balance.** Prepaid / (accrued) = Σ schedule to month-end − Σ tagged payments to month-end. It is computed, never typed.
  - **End of a campaign.** When a campaign ends with a prepaid balance, the page shows it. Writing it off is a final schedule row (an advertising expense). A refund from the KOL has no posting type and is out of v1.
  - Rejected: a service period on every Envelope expense. It changes the meaning of the whole Envelope Ledger and still cannot accrue a fee paid after its month closed. Revisit only if non-KOL prepayments need spreading.
- **V13. Accounting (R14 confirmed by the accountant).**
  - **Discount is contra-revenue. No profit writer changes.**
    - Every `platform_profit` writer and reader already works from `selling_price`: real-cost reconcile, combo residual, resend, partial settlement, manual confirm, `RecognisedRevenue`.
    - Compensation (`final_amount − fee`) and CHIP matching (`final_amount`) are already on the post-promo amount.
    - A uniform percentage leaves the combo leg ratio `u` unchanged.
  - **Operating profit and the month-close allocation are untouched.**
    - `operatingProfit()` already leaves Envelope expenses out (ADR-083 Q9), because they reduce their envelope when recorded and the close allocates operating profit into envelopes.
    - The KOL fee is therefore never added to `operatingProfit()`, `profitLines()` or the allocation; doing so would deduct it twice.
    - `envelopeExpensesByCategory()` skips campaign-tagged postings.
    - The Monthly Summary adds three journal lines: "KOL fees: expense (accrued)" from the schedule, "KOL fees: paid (cash)" from tagged postings, and the KOL prepaid / (accrued) closing balance.
    - The close preview's `net_sen` = operating profit − (untagged Envelope expenses + accrued KOL fees).
  - **Gross − promo = net.**
    - Net is today's recognised revenue. The promo line is Σ `Order::recognisedPromoSen()` over the `RecognisedRevenue` set.
    - That is the whole `promo_discount_sen` for a delivered order, and promo × kept ÷ `selling_price` (rounded down) for a settled partial, beside `keptSen`.
    - The scorecard uses the same method.
  - **Founder's paperwork, outside the system.** A self-billed e-invoice (LHDN MyInvois), the payment voucher and the bank receipt, attached to the Envelope posting. The KOL's IC number and TIN are **not** stored in the platform. A non-resident KOL's 10% WHT is handled by the founder.
  - **Free product to a KOL is not in v1.** Never give it through the storefront with an admin voucher: that order would be recognised as sales and create phantom profit. It needs a "marketing order" type with its own design.
- **V14. Scorecard (replaces decision 14).**
  - Built as `ReportService::campaignBreakdown()` on the existing `scopedOrders()` / `profitByGroup()`, so profit is the ledger's. No parallel profit query in the campaign service.
  - **Per campaign, for a range and cumulative:**
    - clicks (V16) and redemptions;
    - **new customers** and **qualified new customers**. A qualified customer's first order is ≥ the panel minimum (V19, default RM10), or they bought again. This blocks a scorecard padded with tiny orders.
    - median first-order value, and the share that never returned;
    - sales and ledger profit of attributed orders, redeemed and attributed-only apart;
    - discount cost (V13);
    - fees = the accrued schedule (never the payment dates, so CAC does not spike in a prepaying month);
    - **CAC** = accrued fees ÷ qualified new customers;
    - **cumulative net** = profit − accrued fees (profit is already net of the discount);
    - 30-day repeat rate.
  - **Per code:** redemptions, new customers and sales. CAC is campaign-level only.
  - New customers are counted from `promo_code_redemptions`, not Customer Analytics (which keys on email only).
  - With target CAC and trial length empty, no verdict is shown.
- **V15. Admin UI (replaces decision 16 and R16).**
  - One **Campaigns** page, a plain link under **Customers**.
  - The list shows the V14 scorecard side by side.
  - The detail page holds the fee schedule, the balance, and the codes (percentage, max, budget used vs cap, redemptions, tracker link with copy).
  - Customer Analytics' campaign filter is not in v1.
- **V16. Tracker link (replaces decision 15's "no persistence").**
  - `?promo=CODE` works on any storefront page. The storefront reads it in the browser, as `OrderForm` already reads `player_id`.
  - It keeps the code in `localStorage` for 7 days (founder). That is prefill only and never attributes an order. Reads and writes are wrapped in try/catch.
  - It logs one click per tab through a public endpoint that checks the code exists, throttled with its own prefix (`throttle:60,1,promo-click`).
  - No cookie. An in-app browser keeps its own storage, so the KOL also tells followers to type the code.
- **V17. Code and campaign lifecycle.**
  - **The code string.**
    - The admin chooses it: 3–20 letters and digits, stored uppercase.
    - The customer's input is matched case-insensitively, with spaces trimmed.
    - Unique forever, ended codes included, and never reused, because old KOL videos keep circulating. It cannot start with `VC-` (the voucher format), and each checkout field hints when given the other kind of code.
  - **Status.** `is_active`, plus optional `starts_at` / `ends_at`. Pausing a code means setting it inactive.
  - **What can change.**
    - The percentage, the string and the campaign are immutable after the first redemption; a new offer is a new code. Reservations store sen, so an edit could not corrupt them, but the scorecard would mix two offers.
    - The budget and the max stay editable. Lowering a budget below what is already reserved only stops new redemptions.
  - **No hard delete** once a code has a redemption or a click; archive it instead.
  - **Ending.** Ending a campaign stops its codes. Attribution continues.
- **V18. Readers (completes R15).**
  - R15's list, plus the two admin cards it missed: `OrderDetailCards` and `RefundInformationCards`' "Discount Applied".
  - The affiliate order API and the portal order view also get the field, one line each.
  - WhatsApp, email and the CS assistant read no discount field and need no change.
- **V19. Settings.**
  - `PlatformSettings`, editable in the panel:
    - campaign minimum margin (2%);
    - qualified first-order minimum (RM10);
    - target CAC (empty);
    - trial months (empty).
  - Constants: the 7-day prefill and the click throttle.
- **V20. Not in v1:**
  - the funding split (V1);
  - a separate analytics page and the Customer Analytics filter (V15);
  - `customer_campaign_attribution` (V6);
  - portal or self-service campaign creation (decision 6 stands);
  - free-product marketing orders (V13).

**Rationale:**
- v1 keeps exactly what answers "is this KOL worth paying again": a code that is safe on money, an exact budget, attribution with one owner per customer, and a scorecard built from the ledger and from the accountant's accrual. It drops what only matters for third-party brands.
- R1 ("the promo lives inside `selling_price`") is confirmed as the root decision. Two traces found no profit writer, compensation path or settlement match that needs to change.
- The stress test's corrections close the paths that would have silently produced wrong numbers:
  - a fee deducted twice through the month-close allocation;
  - a void that drops its campaign tag;
  - a full discount counted on a partial delivery;
  - one customer collecting every KOL's discount;
  - a delivery blocked by its own budget;
  - a scorecard padded with RM0.93 orders.
- Each correction lands in a seam the code already has (`reserveAll`, `applyFailed`, `settle`, `RecognisedRevenue`, `BudgetEnvelopeService::write`, `ReportService`), not in a new parallel path.

**Consequence to track:**

- **Tests (build is test-first):**
  - one per profit writer: a promo order's ledger profit = post-promo selling price − cost;
  - preview total = CHIP amount, with promo and voucher, through both preview endpoints;
  - the idempotency hash changes with the promo;
  - the margin floor rejects, including a cost-priced combo;
  - Member, wallet, Joki and membership purchases are rejected;
  - the reserve concurrency test next to `VoucherRedeemConcurrencyTest.php`: two concurrent first orders by one customer with the **same** and with **different** codes;
  - cancel-and-replace happens only when all three details match;
  - a restored redemption frees the slot;
  - a Paid-after-Failed delivery re-reserves over the cap without throwing;
  - a partial settlement restores only its share, and `recognisedPromoSen` is proportional;
  - a wallet order and a gifted player account behave as V5 says, and a reseller's email or phone is rejected;
  - each Envelope ringgit is counted once, and operating profit and the allocation are unchanged by KOL fees;
  - void → re-record moves the payment to the right campaign;
  - the prepaid / accrued balance across a schedule change and a closed month;
  - gross − promo = `RecognisedRevenue`;
  - code case-insensitivity, uniqueness forever, the `VC-` rejection, and immutability after the first redemption.
- KOL 2 at RM2,000 needs about 75–226 new customers a month to break even. The founder negotiates with KOL 1's first month of scorecard data in hand.
- Each month the founder records the KOL payment on the Envelope Ledger with its campaign.
- When a third-party affiliate brand first runs a campaign: bring back V1's dropped parts with their own code trace.
