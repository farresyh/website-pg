# ADR-004 (D4): No cash refunds, ever

> **Standing (2026-11-01):** In force. The 2026-10-22 revision (refund to the original payment method, PRD §16 item 129) was withdrawn by the founder on 2026-11-01: storefront orders stay voucher-only. See the 2026-11-01 addendum. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted — 2026-07-23

**Decision:** Failed or undeliverable orders are resolved by (a) retry delivery, or (b) issuing a Voucher (store credit) — never a refund back to the original payment method.

**Rationale:** Eliminates an entire class of payment-gateway refund-API integration risk (partial refunds, reconciliation) and closes a common cash-out fraud vector (stolen-card fraud can't be laundered into cash back via a refund). Directly reinforced by ADR-005.

### 2026-11-01 addendum: the 2026-10-22 revision is withdrawn

**Decision (founder, 2026-11-01):** a paid storefront order that cannot be delivered is resolved by retry or a store-credit Voucher only. There is no refund to the original payment method, and none will be built. The decision above stands unchanged.

**Why this is possible now:** the 2026-10-22 wording was published for CHIP's merchant review (a refund policy page that allows a refund to the original payment method). CHIP approved FPX and DuitNow QR on 2026-10-31 and is still reviewing its other methods; the founder had said on 2026-10-23 that the policy would go back to voucher-only once CHIP approved.

**Scope:** storefront orders only. A reseller's failed order still returns to its Purchase Balance (the order's payment source), and unused balance is still refunded to the original payer on account closure (ADR-131); neither is a refund of a storefront payment.

**Exposure checked:** read-only on production, 2026-11-01: 14 orders paid since 2026-10-22, all delivered; none was owed a refund under the published wording.

**Consequence to track:** the published Refund Policy (`/refund-policy`, admin content under Settings → Footer) still carries the refund wording until the founder edits it back to voucher-only; that is a production content edit, not a code change.
