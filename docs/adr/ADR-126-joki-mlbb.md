# ADR-126: Joki MLBB — an owned-brand-only `/joki` page, server-priced from an admin rate table, sold as an ordinary Order through the Manual Queue, penjoki payout booked as the order's cost

> **Standing (2026-10-17):** Proposed (design, grill pending). Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Proposed, 2026-10-17. Shape agreed with the founder in chat; the grill and the money-critical code-trace pass are still to run. Builds on [ADR-125](./ADR-125-login-top-up-manual-supplier.md) (Manual supplier + Manual Queue) and [ADR-124](./ADR-124-customer-account-credentials.md) (credentials). Built after both.

**Context:**

- The founder wants a joki (rank boosting) service: the customer picks a current and a target MLBB rank, pays, and hands over their login; one of the founder's own penjoki contacts plays the account up to the target. **PekanGame (owned brands) only**, MLBB only to start.
- References (2026-10-17):
  - **rapspoint.com/joki/ranked**, viewed in the founder's browser. A calculator with big rank icons, ‹ › to change tier and ▲▼ for stars. Priced **per star at the rate of the tier the star is won in**, with a minimum of 5 stars. An Express checkbox adds about Rp1,000 per star. Mythic Grading is a flat Rp170,000. Rates (Reguler/Express, IDR per star): Master 6,000/6,500, GM 6,000/7,000, Epic 7,000/8,000, Legend 8,000/9,000, Mythic Romawi 16,000/17,000, Honor 19,000/20,000, Glory 24,000/25,000, Immortal 29,000/30,000. There are also fixed "Joki Paket" bundles. Step 2 asks for login type (Moonton/Facebook/VK/TikTok/Google Play), user ID, username, email, password, hero request, role request and a "play time" window.
  - **Gamevion's "Road to Mythic" pricelist** (founder's image): fixed route packages in RM, for example Epic II→Mythic RM65, Legend V→Mythic RM55, Mythic→Honor RM150, Mythic→Glory RM275, Mythic→Immortal RM500, Mythic 200/500/1000 star RM900/2000/3500, and Global Hero "PM for quotation". Gamevion is already one of our API suppliers, so an external-supplier joki path is plausible later.
- Founder decisions (2026-10-17): the rapspoint rates are converted to RM; route packages in Gamevion's style are wanted; a refund is always **full as store credit**, which still excludes the gateway fee as for every order; the penjoki gets **100% for now**, with a configurable platform/penjoki split later; the payout base is **what the customer actually paid** (after voucher/discount, excluding the gateway fee); there is **no member discount** on joki; and joki is built after Login Top-up.
- Survey (2026-10-17): Membership is a separate payment entity (own checkout attempt, CHIP webhook branch, reconciliation, ledger credit). Cloning it would mean re-plumbing refunds, reports and accounting. `Affiliate::is_owned` already marks owned brands, and the membership page's backend-gated `notFound()` + `loading.tsx` is the storefront pattern to copy.

**Decision:**

1. **A joki order is an ordinary `Order`** on a "Joki MLBB" game, supplier `manual` (ADR-125), so vouchers, Track Order, reports, accounting and the Manual Queue work unchanged. Joki-specific data (from/to rank and stars, Reguler/Express, hero/role request, play window, assigned penjoki, progress) lives in a `joki_orders` side table.
2. **Price is computed server-side** by a `JokiPricingService` from an admin rate table: per tier, a per-star rate for Reguler and Express, or a flat price for a flat tier (Mythic Grading), plus a minimum star count. The client sends only ranks, stars and the Express flag (ORD-9). **Route packages** (Gamevion style) are `Package` rows on the Joki game with a fixed price, or a "quotation" flag that sends the customer to WhatsApp instead of checkout.
3. **The penjoki payout is the order's cost.** At checkout, `cost_price` = payout share % × (selling price − member/voucher discount), share % from the panel (default 100). So `platform_profit` is 0 at 100% and never negative. Joki is excluded from member pricing and member quota.
4. **Penjoki records in admin:** name and WhatsApp number. Each joki order is assigned a penjoki, with a payout status (unpaid/paid, date, note). No penjoki login or portal for now (PRD §16 trigger: when joki volume makes manual assignment painful).
5. **Progress:** the admin updates the current rank on the order; each update can send the customer a WhatsApp progress message (a new `CustomerNotification` event). The Manual Queue's Delivered / Failed actions (ADR-125 decision 5) close the order; Failed refunds in full as store credit.
6. **Storefront `/joki`** on owned brands only (`is_owned` + a platform toggle, backend-gated like membership, matched `loading.tsx`). Rank icons + tier arrows + star stepper, Reguler/Express, a route-package grid, then the data step (ADR-124 credential fields + hero/role/play window), then payment.
7. **Panel settings (admin):** the joki toggle, the rate table, flat tiers, minimum stars, route packages and quotation flags, login-type options, role list, penjoki list and payout share %.

**Rationale:** joki as an Order reuses every downstream reader; booking the payout as cost makes profit correct on every basis without a new ledger owner type. A full Withdrawal-style payee ledger for penjoki is deferred until there are enough penjoki to need it.

**Consequence to track:**

- A joki cancelled after partial work still refunds in full; the penjoki's work on that order is a platform cost no ledger row captures. Track it in the payout status note until volume says otherwise.
- RM rates need a pricing pass with real numbers before launch (memory: data-driven pricing): the rapspoint IDR table converted at a stated rate, rounded, compared with Gamevion's route prices.

**Open for the grill:**

- Rank model: the exact tier list and stars-per-division for the current MLBB season (Mythic sub-tiers by star count), and how a calculator price compares with the matching route package (Epic II→Mythic RM65).
- Does a voucher-paid amount (store credit from an earlier real payment) count as "actually paid" for the payout base?
- Partial result (e.g. account banned, or the customer logs in mid-run): is it always a full refund, or can the founder choose a partial refund for stars won?
- Express: just a higher rate, or also a promised time window shown to the customer?
