# ADR-004 (D4): No cash refunds, ever

> **Standing (2026-10-09):** In force. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted — 2026-07-23

**Decision:** Failed or undeliverable orders are resolved by (a) retry delivery, or (b) issuing a Voucher (store credit) — never a refund back to the original payment method.

**Rationale:** Eliminates an entire class of payment-gateway refund-API integration risk (partial refunds, reconciliation) and closes a common cash-out fraud vector (stolen-card fraud can't be laundered into cash back via a refund). Directly reinforced by ADR-005.
