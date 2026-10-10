# ADR-034: Storefront best-price selection — `packages.denomination` (built 2026-08-25)

> **Standing (2026-10-09):** In force. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted (design) — 2026-08-24 (grilled with the founder one decision at a time via `/mattpocock-skills:grilling`, before any code touched)

**Context:** Once Digiflazz exists, the same product is sold by two suppliers — e.g. "14 Diamonds" on both Gamevion and Digiflazz. The storefront would show two near-identical `Package` rows with no way to know they are equivalent, and the founder explicitly wants: **only the cheaper supplier's package shows**. `Package.name` is free text with no stable equivalence key, and the money rule (ORD-9) requires the winning package to be chosen server-side from stored `cost_price`+`markup`, never by the client.

**Decision:**

1. **New `packages.denomination` (nullable int)** — the package's inherent value (diamond amount). It is a property of the *package itself*, not a supplier link. Equivalence key is `(game_id, denomination)`.
2. **`CatalogController` dedups by `(game_id, denomination)`:** among active packages, compute the customer-facing price server-side (existing `PricingService`) and expose only the cheapest. Its `supplier_id`/`supplier_package_ref` flow into checkout (already supported end-to-end).
3. **Backfill: leave existing packages empty.** When an admin promotes a Digiflazz package, they set `denomination` on it and on the equivalent Gamevion package if it is still empty; dedup activates at that point. No mass backfill now — there is only one supplier today.
4. **`denomination` is admin-curated at promote/edit time** — no auto-matching by product name (names differ across suppliers; guessing equivalence from free text is worse than explicit curation).

**Rationale:** A stable equivalence key plus server-side price selection keeps the feature correct and supplier-agnostic — it works for any N suppliers without the code knowing who they are. Deferring the column's population until a second supplier exists avoids building dedup behavior against a hypothetical.

**Consequence to track:**
- `denomination` fits integer-amount products (MLBB diamonds); non-integer products leave it null and render exactly as today (no dedup).
- If a future admin override ("feature this package even though it's not cheapest") is wanted, it is a separate flag decision later, not part of this ADR.
