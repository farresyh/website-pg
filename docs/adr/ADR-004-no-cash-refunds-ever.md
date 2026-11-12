# ADR-004 (D4): No cash refunds, ever

> **Standing (2026-11-12):** In force. The 2026-10-22 revision (refund to the original payment method, PRD §16 item 129) was withdrawn by the founder on 2026-11-01: storefront orders stay voucher-only. The 2026-11-12 addendum lets a super_admin pay a failed-order voucher's balance out by bank transfer, as a way of closing the voucher, and stops revoke from touching a customer's own money. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted — 2026-07-23

**Decision:** Failed or undeliverable orders are resolved by (a) retry delivery, or (b) issuing a Voucher (store credit) — never a refund back to the original payment method.

**Rationale:** Eliminates an entire class of payment-gateway refund-API integration risk (partial refunds, reconciliation) and closes a common cash-out fraud vector (stolen-card fraud can't be laundered into cash back via a refund). Directly reinforced by ADR-005.

### 2026-11-01 addendum: the 2026-10-22 revision is withdrawn

**Decision (founder, 2026-11-01):** a paid storefront order that cannot be delivered is resolved by retry or a store-credit Voucher only. There is no refund to the original payment method, and none will be built. The decision above stands unchanged.

**Why this is possible now:** the 2026-10-22 wording was published for CHIP's merchant review (a refund policy page that allows a refund to the original payment method). CHIP approved FPX and DuitNow QR on 2026-10-31 and is still reviewing its other methods; the founder had said on 2026-10-23 that the policy would go back to voucher-only once CHIP approved.

**Scope:** storefront orders only. A reseller's failed order still returns to its Purchase Balance (the order's payment source), and unused balance is still refunded to the original payer on account closure (ADR-131); neither is a refund of a storefront payment.

**Exposure checked:** read-only on production, 2026-11-01: 14 orders paid since 2026-10-22, all delivered; none was owed a refund under the published wording.

**Consequence to track:** the published Refund Policy (`/refund-policy`, admin content under Settings → Footer) still carries the refund wording until the founder edits it back to voucher-only; that is a production content edit, not a code change.

### 2026-11-12 addendum: a failed-order voucher can be paid out by bank transfer; revoke no longer touches a customer's own money

**Context.** A customer whose order failed and who plays no other game has no use for a voucher. The only manual route was to issue the voucher, transfer the money and revoke the voucher. A revoke books the unspent balance as voucher breakage (income, ADR-127 P1), so the transfer would have disappeared from the books while profit rose by the same amount. Read-only on production, 2026-11-12: 231 paid retail orders, 16 failed or partly delivered, 14 order vouchers (RM573.13), none paid out. One merged order voucher, VC-FO21NVDK, was revoked by mistake on 2026-11-12 with RM6.54 left: the revoke button had no confirmation.

**Decision (founder, grilled and stress-tested 2026-11-12).** The text above still holds for every storefront order: retry or a voucher, never a refund to the original payment method through CHIP. One exception is added, for rare cases decided by an admin:

1. **The voucher stays the only compensation record.** A cash refund is a way of closing a voucher, not a new kind of compensation. `settle()`, `isAlreadyCompensated()`, `scopeCompensated()`, `cashCompensationSen()`, `compensationAmountSen()` and every reader of them stay as they are.
2. **`vouchers.source`** is `customer_cash` or `gift`. It is written by two writers only: `issue()` (`customer_cash` when it is issued for a non-sandbox order) and `merge()` (`customer_cash` when every source is). `merge()` refuses to mix the two. A migration backfills the existing rows (all `customer_cash` on 2026-11-12; production has no gift voucher).
3. **`VoucherService::refundInCash()`** is the one writer. It locks the voucher and requires: `source = customer_cash`, `status = active`, no reserved redemption (`assertNoOpenRedemption()`), a balance above zero, and no source order with a recorded late supplier result. The amount is the remaining balance, computed by the server. A partly spent voucher refunds only what is left. The payment fee is never refunded, the same as the voucher (`final_amount − transaction_fee`).
4. **The late supplier result** is one `Order` scope, shared with the automatic wallet refund (`RefundFailedWalletOrdersCommand`, ADR-139). A late Success never changes `delivery_status` (ADR-127 decision 11), so checking the status alone would never catch it. The source orders are the voucher's order, or, for a merge target, its sources' orders.
5. **Transfer first, then record.** This is the closure pattern (ADR-131): a transfer date that is a Kuala Lumpur day of the current month, not in the future, in an open month. That check is moved to `AccountingPeriod`, so the closure and this action share it. The record also holds the bank name, account number, account holder, bank reference, a required proof file (`AccountingFileStore`, category `accounting/voucher-cash-refunds`), the reason and the admin. It goes in a new `voucher_cash_refunds` table, one row per voucher, and the voucher becomes `refunded`.
6. **Access.** super_admin only, behind the MFA step-up. The admin checks for fraud. The account holder is not matched against the payer.
7. **Accounting.** `Voucher::balanceAsOf()` is 0 from the moment of the refund. Cash Position's voucher claim and the bank balance therefore fall together, and the gap does not move. A cash refund settles a liability: it is never income, never breakage, and never in operating profit. The Transaction Register gets a cash-out row and the Monthly Summary an information line. Paid sales and Reports are unchanged: Paid sales nets a wallet refund only because that balance is spent again (build-log-archive, Reports bug 4). A cash refund never comes back.
8. **Revoke** refuses a `customer_cash` voucher. It is for gift credit only: super_admin, a confirmation dialog showing the code and balance, a required reason, and the MFA step-up.
9. No automatic customer message (the admin is already talking to the customer). The published Refund Policy is not changed.

**Rationale.** A third compensation instrument would need a branch in at least eight readers. Closing the voucher needs none of them to change, and the accounting falls out of one dated fact read in one place (`balanceAsOf`).

**VC-FO21NVDK.** It stays as history (the founder's rule for small leftovers from since-fixed code). The RM6.54 is November 2026 breakage. If the customer asks, an admin issues a new voucher for RM6.54 with the reason "replaces VC-FO21NVDK, revoked by mistake". Its admin-issued cost then offsets the breakage.

**Consequence to track.** A late supplier Success that arrives after the transfer leaves the customer with the item and the cash, and the cash cannot be recovered. Accepted: the refund screen shows the supplier's last response and the admin checks the supplier before paying (0 of 14 compensated orders had a late result on 2026-11-12). A transfer made on the last day of a month and recorded on the 1st is refused, the same as a closure. Its one-day difference is written in that month's close note.
