# ADR-130: FPX bank-direct — the customer picks the bank on our page, not on CHIP's

> **Standing (2026-11-01):** In force. The bank grid replaces the single FPX button; the bank is a property of the click, applied where the checkout URL leaves the API, never stored. Extends [ADR-022](./ADR-022-multi-gateway-payment-strategy.md) (CHIP-only, `payment_method_whitelist` pass-through); changes nothing in its webhook or reconcile decisions. Build and live status: [`prd.md` §15](../prd.md); open work: §16 items 138 and 139. The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted (design), 2026-10-29. Grilled with the founder (two rounds, `/mattpocock-skills:grilling`), live-tested against CHIP in test mode, then code-traced against every reader and writer of `channel_code` / `checkout_url` before writing, because it sits on the checkout money path (AGENTS.md). The trace overturned the first plan; see "What the code trace changed".

## Context

- Today FPX is one `payment_methods` row (`channel_code = fpx`, flat RM1). The customer is sent to CHIP's hosted page and picks the bank there. The founder wants the customer to see the banks on our own page and go straight to the bank.
- CHIP's documented mechanism ("Direct Post", `docs.chip-in.asia/chip-collect/overview/direct-post/fpx`): append `?preferred=fpx&fpx_bank_code=<code>` to the purchase's `checkout_url`. The doc lists 21 B2C codes; `Maybank2E` (`MBB0228`) is the corporate variant, so 20 retail banks. The doc is silent on a down bank or an unknown code.
- The founder supplied the official logo of all 20 banks. They are full lockups (symbol + name), several on a solid brand colour, aspect ratios from 1.5 (AmBank, stacked) to 7.5 (Bank Muamalat).
- **Live test, 2026-10-29, CHIP test mode** (banner "Test mode", JW CORE VENTURE): a purchase opened with no parameter shows CHIP's simulator page; opened with a fake code (`XXX9999`) or an empty code, it skips the page, shows "Payment processing" and lands on the success redirect. So CHIP skips its page for *any* `preferred=fpx` value and does not reject an unknown code. The same silent acceptance as `dnqr` (ADR-022 2026-10-28 addendum). Test mode cannot tell a valid code from a fake one, and it does not simulate a real bank, so what a real bank page does is only provable with one real RM1 payment.
- `fpxstatus.chip-in.asia/index.json` is a public feed of per-bank status (20 B2C banks, names like `FPX-Maybank2u`). It is not an official CHIP API.

## Decision

1. **The customer sees a grid of the 20 banks instead of the FPX button**, on the shared `PaymentChannelPicker` (top-up order page and `/joki`). Choosing a bank sets `channelCode = fpx` and a separate `bankCode`. Below the grid, a small "Other bank → choose on CHIP's page" link sends the same request without a bank, so a customer whose bank is not on our list (a bank CHIP adds later, Maybank2E) is not locked out. If the bank list is empty, the old single FPX button shows.
2. **One source for the list: `backend/config/fpx-banks.json`** (CHIP code, name). The backend config reads it for validation. `scripts/sync-shared-files.mjs` copies it and the logos to the storefront and to the portal, under CI's existing drift job. No endpoint, no cache, no database table, no admin switch.
3. **The bank is a property of the click, not of the purchase. It is applied where the checkout URL leaves the API** (one small helper in `Services/Payment/Chip`), at the three response builders: `CheckoutController::buildCheckoutResponse`, `MembershipController` and `WalletController`. The helper appends `?preferred=fpx&fpx_bank_code=<code>` only when the code is on the list and the channel is `fpx`. The stored `checkout_url` stays plain. Nothing about the bank is stored: no column, no snapshot.
4. **A typed request field, not `channel_properties`.** `fpx_bank_code` is a nullable field on `CreateCheckoutRequest`, `SubscribeRequest` and `TopupRequest`, validated `Rule::in(list)` and `prohibited_unless:channel_code,fpx`. A code not on the list is a 422.
5. **Applied only to a payable URL.** A purchase that is already `paid` returns an `.../invoice/` URL from `getPayment`; the helper leaves it alone (order `payment_status` must be Pending).
6. **The idempotency hash does not include the bank** (`CheckoutController::payloadHash`). A replay with a different bank is the same order and gets a URL for the new bank, not `idempotency_mismatch`.
7. **Logos are shown as supplied** (full lockup, no cropping or redrawing of a bank's artwork), optimised once to a uniform tile height, named by CHIP code, in `storefront/public/images/banks/`. The bank name is the `aria-label` / `title`; no repeated text beside the logo.
8. **Order: popular first (Maybank2u, CIMB, Public, RHB, Hong Leong, AmBank, Bank Islam), then alphabetical.** Three columns on a phone.
9. **No bank-down handling in this ADR.** There is no evidence yet that it is needed, the feed is unofficial, and test mode can't show the real behaviour. It is built only if the first real payments show customers failing at a down bank, as its own addendum (fail-open, advisory, never a hard dependency on the money path).
10. **B2B1 (FPX Business), DuitNow QR and every other channel are unchanged.** The bank field is refused for them.
11. **Build in two PRs.** PR 1: the helper, the field on checkout, the grid and the "Other bank" link on checkout and `/joki`, the JSON and logo sync. PR 2: membership (moved onto the shared picker, because it has its own radio list today) and the partner portal's wallet top-up, through the same helper.

## What the code trace changed

The first plan appended the parameter inside `ChipGateway::createPayment` and carried the bank in `channel_properties`. Tracing every reader found it would have failed silently:

- **Orders** never use the URL `createPayment` returns. `buildCheckoutResponse` calls `getPayment($order->payment_ref)` on every create and every replay, and CHIP returns the plain URL. Appending in `createPayment` would have done nothing: the customer lands on CHIP's page with no error and no failing test.
- **Membership and wallet** store `checkout_url` once and `ensureCheckoutUrl` returns the stored value on a retry. A customer who picks Maybank, then CIMB, would still get Maybank.
- `channel_properties` is allowlisted to two URL keys on checkout and unrestricted on the other two requests; a bank code there would be a free-form key.
- Membership does not use `PaymentChannelPicker` (own radios, `MembershipSubscribe.tsx`), so a grid there is a second copy unless it moves.
- Replacing CHIP's page, which lists every bank, with a fixed list of 20 loses any customer whose bank is not on it.

## Rationale

- **No stored bank means no state to drift.** The webhook, `ReconcilePendingPaymentsCommand`, the purchase on CHIP, fees (RM1 flat for any bank) and `orders.channel_code` are untouched, so none of the money paths is re-opened. This is the "one shared seam" the money-bug rule asks for: all three flows route through one helper.
- **Server-side validation is the only guard.** CHIP accepts any value, so a typo or a tampered code would otherwise silently send the customer to the wrong place or to a page that does nothing.
- **One JSON drives validation, UI, logo sync and the drift guard**, instead of a database table or an endpoint for a list CHIP defines and that changes a few times a year. A change is a code change and a release, which is right for a list that touches a payment URL.
- **Showing a bank's logo as supplied** avoids altering trademarked artwork; the logos are the banks' own marks, used the way FPX bank pickers use them.

## Consequence to track

- **Proof before "done".** One real RM1 payment through a real bank, with a bank code, on production after release, since test mode can't show it. If it lands on CHIP's normal page or an error, revert the helper call; nothing else changed.
- **The 20 codes are pinned by a test against the CHIP doc's table**, and a second test asserts every bank has a logo file. The doc's own blog example (`MB2U0228`) disagrees with its table, so the doc table is the source.
- **Bank-down handling** (decision 9) is built only if needed (PRD §16 item 139); the real-bank proof is item 138.
- **A new CHIP bank** is one JSON line plus a logo and a release; until then its customers use the "Other bank" link.
- **Wording to fix at build:** the `ChipGateway` doc comment says "no bank code is ever sent by this adapter". It stays true (the helper lives outside the gateway adapter), but the comment should point here.

### 2026-10-29 addendum — PR 2 built: what it settled

- **Membership** moved onto the shared `PaymentChannelPicker`, as decision 11 said. Its two auto-select-when-one-channel lines were removed (with a bank grid they would show "Other bank" as chosen without a click); "Continue to Payment" waits for a bank or "Other bank". The wallet top-up behaves the same: its default channel skips the bank-grid channel.
- **The validation rule takes the channel field's name.** Membership's request calls the channel `payment_method`, not `channel_code`; `FpxBankRedirect::rules($channelField)` is the one rule for all three requests, so `prohibited_unless` cannot silently check a field that does not exist.
- **The portal has its own grid component** (`FpxBankGrid`, the portal's tokens, ADR-128) over generated copies of the one JSON and the logos; only the styling differs from the storefront's.
- **The "BNM Compliant • 256-bit SSL" line was removed** from the shared picker (PRD item 134, founder's decision), and the two wallet lines that said the fee is shown on "CHIP's page" now say "payment page", since bank-direct skips CHIP's page.

### 2026-10-30 addendum — the "Other bank" link is removed

- **Decision 1 is revised (founder, 2026-10-30):** the 20 banks are enough, so the "Other bank? Choose it on the next page" link is gone from both bank grids (storefront `PaymentChannelPicker`, partner portal `FpxBankGrid`). Choosing FPX now always means tapping a bank, so checkout never sends FPX without a bank code from the grid.
- **What this accepts:** a customer whose bank is not one of the 20 (a bank CHIP adds later, or the corporate Maybank2E) has no FPX path on our page and pays by DuitNow QR instead. The "A new CHIP bank" line under Consequence to track is now: one JSON line, one logo, one release, and until then those customers cannot use FPX.
- **Unchanged:** the backend still accepts an FPX request with no `fpx_bank_code` (the helper adds none), and the plain FPX button still shows when the bank list is empty.
- **Also in the same change:** the tiles are smaller (storefront: 6 columns on desktop, 4 on mobile, tile `h-10`, logo `max-h-5`; the portal grid is unchanged in size).
