# PekanGame — Build Log

> The running chronological record of what shipped, when, and why — every
> session, the bugs hit along the way, and the live-verification notes.
> Moved out of `prd.md` §14 on 2026-09-11 once it outgrew the spec doc.
>
> **This file is append-only history.** Current per-feature status lives in
> `prd.md` §15; the live backlog is `prd.md` §16; decision rationale is the
> ADR log (`adr.md` index → `adr/`). **Everything before 2026-10-28** lives verbatim in
> [`build-log-archive.md`](./build-log-archive.md): the foundation build, the
> old §14 table and PrimeReact tracker, and the production-era entries
> 2026-09-01 → 10-27 (moved in ten passes, the last on 2026-11-04) to keep
> this file quick to read at session start.

---

## 2026-10-28 — DuitNow QR was sent to CHIP under the wrong code; Test action fixed (ADR-022 addendum)

**Found (read-only prod + CHIP portal).** The founder's DuitNow test order ended `Cancelled`, "No matching terminal", Visa. Cause: our code `duitnow_qr` vs CHIP's `dnqr` (`GET /payment_methods/` with the live credentials: `fpx`, `fpx_b2b1`, `dnqr`). CHIP kept `["duitnow_qr"]` as the whitelist (the purchase JSON echoes it), so no error. The Test button's HTTP 400 on every channel was a separate bug: no `client.email`. Prod had 28 real FPX orders while Test said FPX "failed".

**Built** on `fix/chip-dnqr-channel-code`: seeder + rename migration, `verifyChannel` in the Test probe, email in the probe, CHIP field errors in `failureFrom`. `php artisan test`: 3084 passed.

**Gotchas.** (1) Live CHIP credentials come from the `payment_gateways` row (`ChipCredentialResolver`), not `.env`: reading `config('services.chip')` in tinker used a different key (a purchase GET returned 404). (2) An unknown whitelist value is not rejected by CHIP, so checking the purchase response for an empty whitelist would not have caught this. **Not yet verified:** a paid DuitNow QR payment; after release, run Test on `dnqr`, then pay RM1 for real. Until released, DuitNow QR should be switched off in Payment Methods.

## 2026-10-28 — MLBB validator: Redx → UniPlay replaces Redx → MooGold (ADR-005 addendum)

**Found (read-only).** Redx's `POST /tools/check` answered 503 "temporarily unavailable" for `mobile-legends` three times in a row, while `free-fire` and `genshin-impact` returned 200 with the identical session/CSRF handshake. So our `RedxGameValidator` was fine; Redx had turned its MLBB checker off. Our code already treats a non-check response as unavailable and falls back. The public `/check-id` MLBB page was Redx-only, so it showed "busy".

**Built** on `feature/uniplay-validator-replace-moogold`: `UniplayValidator` (token read from the page and cached, renewed once on 401/403; `status:false` = invalid), chain Redx → UniPlay for checkout and the public checker (ADR-123 addendum), MooGold validator/test/config deleted. `php artisan test`: 3080 passed.

**Gotcha.** The UniPlay token regex first rejected the test token `tok-1` because base64 has no `-`. **Not verified from our server:** the live UniPlay call (this session's automation was blocked from using their page token); the founder confirmed the response shape in a browser. After release, check `player_validations.provider` for an `uniplay` row.


## 2026-10-29 — Docs sync after #582–#584 (merged to `staging`, not released)

Merged #582, #583, #584 into `staging` (merge commits). #584 conflicted with #583 on `build-log.md` (both appended at the end); both entries kept. #584's first CI run failed `scramble-drift`: its migration called `Cache::forget`, and that job migrates with no Redis; the cache calls were removed (catalog caches expire in 60 s).

Docs corrected against prod and git (read-only, 2026-10-28/29): PRD §14 said DuitNow QR "stays off (checked 2026-10-26)" and "staging = main"; prod showed DuitNow QR on since 2026-10-28 13:28 UTC (unpayable with the wrong code) and `staging` ahead of `main` by #582–#584. Also corrected: the Game ID Checker row and `foundation-security.md` ("Redx only, never MooGold"), ADR-076/093 notes ("only MooGold remains"), ADR banners and index dates. Prod runs #581 (`2db6d62`): MLBB checks go Redx (HTTP 503 since 2026-10-28) → MooGold; the three `player_validations` rows since then are `moogold`.

Correction to this session's earlier count: prod has 23 *paid* FPX orders, not 28 (28 includes failed and pending).

## 2026-10-29 — FPX bank-direct, PR 1 (ADR-130): the customer picks the bank on our page

**Designed first, then built.** ADR-130 (#586) records the decision, the 2026-10-29 CHIP test-mode run and the code trace that overturned the first plan: appending the parameter in `ChipGateway::createPayment` would have done nothing for orders (they re-read the URL through `getPayment`) and would have pinned the first bank on membership/wallet (stored `checkout_url`).

**Built (PR 1, branch `feature/fpx-bank-direct`, not yet released):**
- `backend/config/fpx-banks.json` (20 B2C codes from CHIP's FPX doc table, popular first) read by `config/fpx-banks.php`; `FpxBankRedirect` appends `?preferred=fpx&fpx_bank_code=<code>` to the `WEB_URL` action only for a listed code and never to an `/invoice/` URL.
- `CheckoutController::buildCheckoutResponse` applies it to a Pending order's response (create and every replay); `CreateCheckoutRequest` gets `fpx_bank_code` (`Rule::in` the list, `prohibited_unless:channel_code,fpx`). Nothing is stored; `payloadHash` is unchanged, so a replay with another bank is the same order.
- Storefront `PaymentChannelPicker`: a 20-logo grid (3 columns on a phone, 4 from `sm`) replaces the FPX button, plus "Other bank? Choose it on the next page" (same request, no bank). `OrderForm` and `JokiOrderForm` keep `bankCode` separate from `channelCode` and send `fpx_bank_code`. `CheckoutPayloadSchema` needed the key too: Zod strips unknown keys, so without it the bank vanished before reaching the API.
- Logos: the founder's 20 files, resized to 240 px wide, named by lower-cased CHIP code in `storefront/public/images/banks/`.
- `scripts/sync-shared-files.mjs` copies the JSON to `storefront/src/lib/fpx-banks.json`; CI's `theme-preset-drift` now diffs that path too (it only covered `reseller/` before).
- Four e2e specs clicked the removed "Online Banking (FPX)" button; they now pick Maybank2u.

**Verified:** backend suite 3097/3097 (new: `FpxBankRedirectTest` pins the 20 codes, `CheckoutControllerFpxBankTest` 7 cases: bank applied, plain without, replay with another bank, replay via the other-bank link, nothing stored on the order, unlisted code 422, bank on another channel 422); storefront `tsc`, `lint`, `build`. In a real browser, on a temporary preview page (deleted): 20 tiles render, selecting a bank sets `fpx` + its code, "Other bank" sets no bank, DuitNow clears the bank; at a 358 px container the grid is 3 columns of 103x48 px with no horizontal overflow.

**Gotchas hit:** the local backend's `/api/catalog/games` 500s on `CACHE_STORE=database` (backend/AGENTS.md gotcha 5), so the storefront showed no games and the full order flow could not be clicked through locally; the picker was checked on a throwaway page instead. Chrome blocks iframing a storefront page, so the phone width was simulated by narrowing the container.

**Not done / owed:** PR 2 (membership onto the shared picker, wallet top-up, the portal's copy of the JSON and logos); one real RM1 payment through a real bank after release (test mode accepts any code, ADR-130); bank-down status stays unbuilt (ADR-130 decision 9). The review modal still shows "Online Banking (FPX)" without the bank name.

## 2026-10-29 — FPX bank-direct, PR 2 (ADR-130): membership and wallet top-up

**Built (PR #588, `feature/fpx-bank-direct-pr2`, not yet released):**
- Backend: `FpxBankRedirect::rules($channelField)` is the one rule for `fpx_bank_code` (`SubscribeRequest` names the channel `payment_method`, so a hard-coded `channel_code` would have silently never fired there; `CreateCheckoutRequest` now uses it too) and `applyToUrl()` the one URL helper (`apply()` for the order's `actions` array uses it). `MembershipController::subscribe` and `WalletController::topup` apply the bank to the response `checkout_url` for a Pending attempt only; the stored `checkout_url` stays plain, so a retry with another bank gets the right URL.
- Storefront: `MembershipSubscribe` drops its own radio list and "Secured via CHIP" strip for the shared `PaymentChannelPicker`. The two auto-select-when-one-channel lines are gone (they would have shown "Other bank" as chosen without a click), so "Continue to Payment" waits for a bank or "Other bank". `subscribe()` sends `fpx_bank_code`. The "BNM Compliant • 256-bit SSL" line is removed from the picker (item 134, founder's decision).
- Partner portal: `components/money/FpxBankGrid.tsx` in the portal's own styling over generated copies of `fpx-banks.json` and the 20 logos (`sync-shared-files.mjs`; the CI drift diff now covers `reseller/src/lib/fpx-banks.json`). The wallet's default channel skips the bank-grid channel, so nothing is pre-selected on it. Two lines that said the fee/payment is on "CHIP's page" now say "payment page", since bank-direct skips CHIP's page.

**Verified:** backend suite 3104/3104 (new: membership 3 and wallet 2 feature cases, `applyToUrl` and `rules` unit tests); storefront `tsc`/`lint`/`build`; reseller `tsc`/`lint`/8 tests (incl. the colour-token test)/`build`. In a real browser on a throwaway preview page (deleted, plus the test cookie): the portal grid renders 20 tiles, a click sets the bank and `aria-pressed`, "Other bank" clears it, `cyan-600` resolves, no horizontal overflow.

**Gotchas hit:** the portal's `proxy.ts` redirects every unauthenticated path to `/login`, so the preview page needed the presence-only `kerox_reseller_session` cookie (not a credential). PR #587's CI failed once on `Adr126ManualOrderSeamsTest` (a 1 s flake: `credentialFor()` reads `now()` per call; fixed by freezing time in that test).

**Not done / owed:** the membership e2e and the full membership and wallet click-throughs run only in CI or need a verified member / reseller login; one real RM1 payment through a real bank after release (item 138); bank-down status (item 139, only if needed).

## 2026-10-29 — A failed payment attempt is final, PR A backend (ADR-110 addendum, item 140)

**Why.** The founder cancelled at a bank's login page after FPX bank-direct went live. Read-only prod: purchase `be09ba53-…` went `created` 04:33:24 → `error` 04:33:30 (CHIP attempt error `1C`) → `expired` 05:03:23; CHIP showed its payment form again (the customer could pay the same purchase) and our order stayed Pending until the sweep failed it at 05:15:05, 42 minutes later. The founder's rule: after a cancel the customer starts a new checkout.

**Tested first, CHIP test mode (the founder ran the commands):** with `purchase.single_attempt: true` and the simulator's "Test Failure", CHIP moves the purchase `error` → `cancelled` one second later, sends the customer to `failure_redirect`, and opening the link again goes straight to `failure_redirect`. So no `cancel` call is needed.

**Built (PR A, `feature/failed-attempt-final`, not yet released):**
- `ChipGateway::createPayment` sends `purchase.single_attempt: true` (every purchase: orders, membership, wallet top-up and the bot's, the admin Test probe). `normalizePurchase` adds `failure_is_final` (cancelled/expired/overdue/blocked always; `error` only on a single-attempt purchase, so a purchase created before this ships is never failed early). `PaymentResponse::isFinalFailure()` is the one reader.
- Orders: `PaymentReconciliationService::reconcileOrder($order, earlyCheck: true)` applies a failure only when final (paid answers still applied). Used by a new public `POST /api/track-order/{orderNumber}/check-payment` (brand-scoped, Pending orders with a `payment_ref` only, `throttle:10,1` plus a 10 s per-order cache lock so one CHIP call per window, a failed lookup returns the order unchanged) and by the checkout replay path in `CheckoutController::respondForExistingOrder` (a replay whose purchase ended now fails the order and answers `checkout_closed` instead of handing out the dead link). `RouteAccessTest`'s public-route list got the new route on purpose.
- Membership: `initiate` fails a recent Pending attempt whose purchase ended and creates a new one; a replay on the same idempotency key of a failed attempt answers 422 `checkout_closed` (`MembershipAttemptClosedException`).
- Wallet top-up: `failPendingIfFinal` runs before the transaction that holds the ledger-account lock (no CHIP call under the lock), so a reseller who cancelled at the bank is no longer blocked 30 minutes and the bot no longer re-sends a dead link. The lock's own check stays for races.
- Every failure goes through the existing seams (`OrderPaymentOutcomeService::applyFailed`, `LeavesPendingOnce::leavePending`), so voucher and quota are given back as before.

**Verified:** `php artisan test` 3129/3129; new `CheckoutControllerFailedAttemptTest` (10: cancelled fails the order, voucher given back, retryable error left pending, open purchase pending, a paid answer applied, one CHIP call per window, non-Pending not looked up, 404, replay closed, replay of an open purchase returns the link), `ChipGatewayTest` (+11: single_attempt sent, the `failure_is_final` matrix), membership (+3) and wallet (+2) cases; concurrency suite for wallet top-up and membership (3) on local MySQL; `scramble:export` shows no drift.

**Gotchas hit:** the concurrency run first failed on leftover `llm_report_*` views (backend/AGENTS.md gotcha 8, dropped with the documented command). `RouteAccessTest` fails on any new public POST until it is listed.

**Not done / owed:** PR B (storefront status page: one check on open, "Payment not completed" + "Try again"; membership clears its key on `checkout_closed`); one real RM1 cancel at a bank after release (item 138/140): CHIP error `1C`, our failed page, order Failed within seconds, a new checkout works.

## 2026-10-29 — Release #589 live, verified read-only on prod; PR B (#592) built; docs synced

**Release #589 (`staging`→`main`, `c7cfcf06`, 12:15 MYT).** The box repointed `current` at 04:27 UTC (release `81523398`). Read-only on prod at 06:31 UTC: the rename migration is in `migrations`; `payment_methods` is `fpx` on, `fpx_b2b1` off, `dnqr` on; `player_validations` since the deploy has 3 `valid` rows from `uniplay` (latest 04:33 UTC), none from `moogold`.

**What the founder tested, and what the data shows.** The founder reported DuitNow paid, FPX redirected straight to the bank and the MLBB check worked. Prod data: **no order has been paid since 2026-10-28**; the three orders since the deploy are all Failed. `PG-6DGYYPTUUEXR` and `PG-UOOPLDNB9GVW` (FPX) reached the bank through the grid (CHIP attempt `fpx_buyerBankId` `BCBB0235`, CIMB) and were cancelled at the login page (error `1C`). `PG-XM0J3KQRTKJF` (DuitNow) reached DuitNow (attempt `payment_method: dnqr`, which proves the code fix) and was cancelled by the user ("Transaction cancelled by user"); its CHIP purchase ended `expired`, not paid. So the routing fixes are proven and a completed payment through either is not. Recorded as it is; the PRD says the same (§14, §16 item 138). The Test action's stored results for `fpx` and `dnqr` are still the old HTTP 400 ones: it has not been run since #584.

**The cancel finding that led to item 140.** `PG-UOOPLDNB9GVW`'s purchase went `created` 04:33:24 → `error` 04:33:30 → `expired` 05:03:23; CHIP showed its payment form again (the same purchase could be paid) and our order was Failed only at 05:15:05 (sweep every 15 minutes, orders older than 30). Fix designed and built the same day: ADR-110 addendum (#590), backend #591, storefront #592 (see the entry above for #591).

**PR B (#592, merged to `staging`, not released):** `OrderStatusTracker` asks `POST /api/track-order/{n}/check-payment` once per page open for a Pending order and shows "Confirming your payment…" meanwhile; a failed payment shows "Payment Not Completed", "Nothing was charged…" and "Try Again" as the primary button (`/order/{slug}`, `/joki` for a joki order); `MembershipSubscribe` clears its idempotency key on 422 `checkout_closed`. A first version cancelled the check's answer in an effect cleanup that ran on every poll update (the page would have stayed on "Confirming…"); caught on review and removed before the PR. `tsc` and `lint` clean.

**What was and was not verified for #591/#592.** Proven against CHIP test mode with local orders (Herd serving the merged branches): after the simulator's "Test Failure" the check fails the order at once, a second call within 10 s does not call CHIP again, a replay answers `checkout_closed` with and without a prior check, and the purchase carries `single_attempt: true`, ends `cancelled`. **Not verified:** the status-page copy in a browser. The Chrome profile the assistant uses does not trust Herd's local certificate, so the page could not load the API (the assistant did not click through the warning), and an in-page `fetch` rewrite did not survive Next's full navigations. Local order `PG-CSITBGSMJJI3` was left Pending (its CHIP purchase is cancelled) in the dev DB for a hand check; the dev DB also holds two local test orders, both Failed. Herd's web service was not answering mid-session (nothing listening on 80/443); the assistant ran `herd start` and it answered afterwards.

**Docs synced (this entry's PR).** PRD §14: the "payments are switched off" paragraph is replaced by the checked payment state, the unreleased-work paragraph by the #589 release and the new unreleased #590–#592; §15 rows for Payment Gateway, Image Gallery (live since #571, not "on staging"), Middleware Panel and Game ID Checker (UniPlay released) and the Reseller wallet (payable by FPX); §16 items 134 and 137 closed, 138–140 added, group A empty. Facts came from git (`origin/main`, merge commits), the Forge `current` symlink and SELECT-only queries.

**Owed, in order:** release #590–#592; then item 138 (a completed RM1 FPX payment through a grid bank, a completed DuitNow QR payment, Test on `fpx` and `dnqr`) and item 140 (a real cancel at a bank).

## 2026-10-29 — ADR-131 PR A built: Purchase Balance name, limits, receipts (on `feature/purchase-balance-limits-receipt`, not released)

**Why.** CHIP's activation conditions for LWF Group's merchant account (ADR-131; PRs #594 design, #595 test fix). PR A covers the rename, the limits and the top-up receipt; PR B (account closure and the two Transaction Register rows) and PR C (the affiliate-domain payment gate) are next, and all three ship in one release before CHIP is answered.

**Built.**
- Migration `2026_10_29_150000_add_purchase_balance_limits`: `platform_settings.topup_max_sen` (RM10,000), `balance_cap_default_sen` (RM100,000), `company_legal_name`/`company_reg_no`/`company_address`; `resellers.balance_cap_sen` (null = the default).
- `ResellerWalletService::limits()` and `assertWithinLimits()`: one check for the CHIP top-up start (portal and bot) and the admin manual credit. It takes the reseller's `ledger_accounts` mutex first, because `LedgerService::credit()` takes no lock. `completeTopup` and the order `wallet_refund` never call it. Two exception subclasses of `InvalidWalletTopupAmountException` (over the maximum; over the cap) so the portal answers 422 with the numbers and the bot answers in Malay instead of "minimum RM10".
- `PUT /settings/purchase-balance-policy` and `PUT /resellers/{id}/balance-cap`, both `mfa.stepup` (`AdminMfaStepUpTest` list updated); the generic reseller update cannot touch the cap (a missing field is a 422, not a reset). Seller details through the existing platform settings (`sometimes`).
- Payment Receipt: `ResellerWalletReceiptService` + `GET /reseller-portal/wallet/receipts/{number}` (dompdf, no new dependency), `PB-<attempt reference>` / `PB-M-<ledger entry id>`. The fee printed is `total_charged_sen - amount_sen`; CHIP's own `gateway_fee_sen` is never shown. Another reseller's number is a 404.
- Rename of every Reseller-visible string: portal (route `/wallet` -> `/balance`, permanent redirect, so in-flight CHIP return URLs still land), Reseller API messages and descriptions (`openapi.json` regenerated), docs-site prose, bot replies, the description sent to CHIP. Kept on purpose: admin panel, code names, ledger types, JSON field names, error code `INSUFFICIENT_BALANCE`, the bot commands, the e-Wallet payment icon.
- Admin: a "Purchase Balance" settings tab (limits with step-up, company details) and a "Balance cap" card in the reseller wallet modal.

**Verified.** `php artisan test` 3164 passed; reseller 8/8, admin 19/19, lint and `astro check` clean; the `tsc` error left in `reseller/` is only the stale, gitignored `.next/types` and clears on the next dev or build. Concurrency (real MySQL): `test_two_simultaneous_manual_credits_cannot_both_pass_the_balance_cap` passes 3/3 with the lock and **fails 3/3 with the lock removed**. Dev DB migrated with plain `php artisan migrate`.

**Gotchas hit.**
- `backend-concurrency` failed on docs-only #594: the wallet top-up test helper's fake gateway threw from `getPayment()`, which #591 made `initiate()` call. A timing flake, fixed in #595 (proved by forcing the order).
- A manual-credit-vs-top-up-start race test was dropped: a pending CHIP attempt is deliberately not counted against the cap (ADR-131 decision 6), so both may legitimately pass. Only the two-manual-credits race is asserted.
- Existing admin wallet tests created a reseller with no ledger account; the limits check locks it, as `initiate()` always did. Real resellers get it at creation, so the test fixture was fixed, not the code.

**Browser check (local, Playwright against the founder's `./scripts/dev.sh`).** Reseller portal `/balance` and dashboard: no "wallet" or "top-up" word left on either page; limits line, terms card, an amount of RM20,000 shows "The maximum per payment is RM 10,000.00." with the submit disabled; the Receipt button downloads a real PDF (200, `PB-WT-LOCALTEST01.pdf`); the longer "Purchase Balance" nav label fits the phone bottom bar at 390px. Admin: the new Purchase Balance settings tab renders; Company details save (`PUT /settings/platform` 200); the reseller wallet modal shows the Balance cap card ("Currently RM 100000.00 (platform default)", using the modal's own `toFixed` format like its balance). The receipt layout was checked by rendering the same Blade view to HTML (no poppler to render the PDF). Found and fixed here: step 2 of "How adding balance works" still said "No receipt to send", and the terms card touched the card above it.

**Redirect verified after a restart.** The first check failed because the old dev servers (a leftover run that held ports 3000-3002, 8000, 8080) had not loaded `next.config.ts`; after stopping them and starting `./scripts/dev.sh` fresh, `GET /wallet?topup=success` answers `308` with `location: /balance?topup=success`, query kept (in-flight CHIP returns still land).

**Not verified in a browser.** The step-up save path: the test admin has no MFA, so "Save Limits" got the existing 403 "require MFA on your account" toast (same as Customer Login Policy); the route is covered by `AdminMfaStepUpTest` and `PurchaseBalanceAdminTest`.

**Not done / owed.**
- PR B and PR C; the docs for them (`foundation-security.md` closure wording, ADR-060 domain gate line); production screenshots from a test reseller after the release.
- `TrustFooter.tsx` hard-codes "LWF Group Sdn. Bhd. (202601038896)": the receipt reads its seller details from the new settings instead, so the two can drift; the founder decides whether to seed the settings from it and point the footer at them.

## 2026-10-29 — ADR-131 PR B built: reseller account closure and the two Register rows (on `feature/reseller-account-closure`, not released)

**Prod check first (read-only, SELECT).** `wallet_topup` ledger rows: 3, all CHIP (`wallet_topup_attempt`), 0 manual; 2 resellers, both active, none deleted. So the new manual-credit Register rows change no existing figure. (The `accounting_periods` table I guessed does not exist; the real one is `accounting_period_closes`.)

**Built.**
- `ResellerClosureService::close()` (one transaction): locks the wallet `ledger_accounts` row, then the reseller row; refuses while a wallet order is not `closed()` or an Add Balance attempt is `Pending` (whatever its expiry); debits the whole balance as `wallet_closure_refund` (none for a zero balance; the transfer date and bank reference go in `reason`); revokes API keys, portal logins and tokens, unlinks WhatsApp groups, disables the webhook; deactivates and soft-deletes. `POST /resellers/{id}/close` (super_admin + `mfa.stepup`, listed in `AdminMfaStepUpTest`), read-only `GET /resellers/{id}/close-check`; the bare `DELETE` is gone (405).
- The transfer date must be a day of this Kuala Lumpur month, not in the future, in an open month (a ledger row is always dated now, so this is what keeps the debit and the cash in the same month). A repeat on a closed reseller is a 404 from the route; the service has its own 422 for a race.
- `OrderSettlementService::settle()` refuses a closed reseller's order (the one seam `refundToWallet`, the voucher-from-order action and the test command all use); `completeTopup` still credits and logs `critical`.
- Transaction Register: `reseller_wallet_manual_credit` (cash in by bank transfer, the pre-existing gap) and `reseller_wallet_closure` (cash out); `WalletTopupAttempt::reseller()` is `withTrashed()` so a closed reseller keeps its name.
- Admin: "Close account" replaces "Delete" on the Resellers page, with a modal that runs the preflight, lists blockers, and asks for the transfer date and bank reference; the two new Register types and the closure ledger label; the portal's ledger label.

**Verified.** `php artisan test` 3193 passed. New: 23 closure tests (each blocker, the date rules, a closed accounting month, revocations, the settlement guard, a late payment); Register row tests; `FinancialScenarioTest` closes the scenario reseller and the cash-equation gap is unchanged (it **fails** if the bank movement is left out), and a Failed unrefunded wallet order blocks it until refunded. Real MySQL, two processes: a closure racing an order placement conserves money, and two closures refund once, 3/3 each; the double-closure test **fails 3/3 with both row locks removed**. admin 19/19 and reseller 8/8, lint and `tsc` clean. Browser (local, Playwright): the modal shows the balance, the instructions and the date/reference fields; with a pending Add Balance attempt it lists the blocker and hides the form.

**Gotchas hit.** Two old controller tests exercised the removed `DELETE`; replaced by tests on the close route (which needs a stepped-up admin). `AccountingPeriodClose` has many NOT NULL columns, so the closed-month test fills them all. Pint flagged style on my own edits only (baseline was clean), fixed.

**Not verified in a browser.** The final submit: the test admin has no MFA, so the existing step-up refusal applies; the route is covered by backend tests.

**Owed.** PR C (the affiliate-domain payment gate); the glossary note for "closed" accounts is not needed (no new term); production screenshots from a test reseller after the one release; the founder's recording of the first real closure's bank reference in the build-log if one ever happens.

## 2026-10-29 — ADR-131 PR C built: a branded domain takes no CHIP payment until approved (on `feature/affiliate-domain-payment-gate`, not released)

**Built.**
- Migration `2026_10_29_170000_add_payments_approval_to_affiliate_domains`: `payments_approved_at` (null = not approved) and `payments_approved_by`. It approves the rows of the `is_primary` affiliate and nothing else (an `is_owned` brand's rows stay unapproved). Run for real on the dev DB with a seeded primary-affiliate row: rolled back and re-applied, the primary row is approved, the scenario affiliate's row stays null.
- `StorefrontBrand::assertPaymentsEnabled()` / `paymentsEnabled()`, fed by `ResolveStorefrontBrand` from the domain row it already loads (no second query). Only a resolved custom hostname can say no: the primary host and every non-storefront caller carry no hostname and are never gated. Called at the top of `CheckoutService::initiate()` (before the Order exists; skipped when a voucher covers the whole order), in the no-link branch of `CheckoutService::resume()`, and at the top of `MembershipSubscriptionService::initiate()`. `PaymentsNotEnabledException` renders itself as `403 { code: payments_not_enabled }`.
- `GET /catalog/branding` returns `payments_enabled`, computed per request outside the per-brand cache (two domains of one brand share that payload but not their approval).
- Admin: `PUT /affiliates/{id}/domains/{id}/payments-approval` (super_admin + `mfa.stepup`, listed in `AdminMfaStepUpTest`, 404 for another affiliate's domain), the affiliate detail shows it, and an Approve / Revoke action with a confirm. The affiliate's own portal shows "Payments awaiting approval" on a live domain. The storefront shows a notice and disables pay on a domain that is not approved.

**Verified.** `php artisan test` 3204 passed. `AffiliateDomainPaymentGateTest` (11): refused with no Order created; approved works; the primary host and a header-less request are never gated; revoke then a no-link replay waits for approval and resumes after; a replay of an order that already has a link still returns it (accepted, decision 21); membership refused with no attempt; branding per domain without cache bleed between two domains of one brand; approve/revoke stored with the admin. Removing the three `assertPaymentsEnabled()` calls fails exactly the three gate tests. e2e is unaffected (it runs on the primary host `localhost`, which has no domain row).

**Gotchas hit.** Existing tests that pay on a brand domain (`AffiliateStorefrontCheckoutTest`, one membership test, and the `FinancialScenario` brand domain) needed `payments_approved_at` on their domain fixture; the fixture was the thing that changed, not the code. The branding route is `/api/catalog/branding`.

**Owed before the one release.** Re-check prod's `affiliate_domains` read-only (any `Active` row besides the primary affiliate's would stop taking payments on deploy); the browser check of the three screens; then, after CHIP's written approval of `ohahastore.com` (not in prod yet), approve it in the admin. `fixfastapp.com` stays unapproved (to be deactivated, item 129's switch-over steps).

## 2026-10-29 — Release #599 live: ADR-131 (CHIP's activation conditions) and the failed-attempt work (#590–#598)

**Release.** `staging`→`main`, PR #599, merged as `a6ae7f4a` at 12:01 UTC (merge commit, `staging` kept). Before it: draft PR first; `origin/main..origin/staging` was 34 commits, then 36 after #600 (the receipt note fix); a trial merge was clean (the main-only commits are earlier release merges). CI (nine jobs) and `deploy` green.

**Read-only prod check before the release.** One custom domain, `fixfastapp.com` (FixFast Demo, active, not the primary affiliate): after the deploy it cannot pay (intended). No row for the primary affiliate (its hosts are config), so the migration's backfill touched none. `fpx` and `dnqr` on, `fpx_b2b1` off; 2 resellers, 3 paid top-ups.

**Verified on prod after the deploy (read-only).** `current` → release `81561008` at `a6ae7f4`; both `2026_10_29_*` migrations in `migrations`; `topup_max_sen` 1000000, `balance_cap_default_sen` 10000000, company details empty, no reseller cap set; `ResellerClosureService` and `StorefrontBrand::assertPaymentsEnabled()` present; `/api/health` 200; the four Vercel production builds succeeded.

**Found before sending CHIP the receipt.** The receipt note said "An invoice is issued for each order", which is false (the system issues no per-order invoice; e-Invoicing is outside our code, ADR-083 decision 6). Fixed in #600 before the release, with a test and an ADR-131 addendum.

**Also settled while drafting the reply to CHIP.** A reseller's order is paid from the Purchase Balance, never through a payment method; a failed order returns to that balance (its payment source), so the offer "tell us if you want it back to the original payment method" was dropped as wrong for the reseller model. The reply and the onboarding procedure PDF live outside the repo (it is public). The FixFast reseller is a test account whose login CHIP already has; FixFast Demo / `fixfastapp.com` is the test affiliate.

**Dev environment notes.** `lsof` and a `curl` port check were denied by the harness; Playwright against the founder's `./scripts/dev.sh` and read-only SSH worked. A second `dev.sh` fails with `EADDRINUSE` while the first runs; a Next config change (the `/wallet` redirect) needs the dev server restarted. The local storefront catalog answers 500 when the local `CACHE_STORE` does not support tags (backend/AGENTS.md gotcha 5), so storefront order pages cannot be viewed locally without a Redis cache.

**Owed.** PRD §16 items 138 and 140 (real FPX and DuitNow payments, a real cancel at a bank) and 141 (company details, deactivate `fixfastapp.com`, the browser checks, the reply to CHIP, approving `ohahastore.com` only after CHIP's written approval); new items 142 (receipt redesign and its follow-ups) and 143 (the portal footer hard-codes the company details).

## 2026-10-29 (evening) — Live checks after release #599

**What was seen on prod.** The company details are filled (read-only check: name, SSM and address set), and a receipt PDF for the FixFast test reseller's earlier RM10 top-up prints them. The founder cancelled one FPX and one DuitNow QR payment at the bank. Read-only on prod: the FPX order (RM2.07) was created 12:44:50 UTC and Failed 12:45:02; the DuitNow order (RM1.08, channel `dnqr`) was created 12:45:51 and Failed 12:46:11; neither has a voucher and none was left Pending, and the "Payment Not Completed / Try Again" page showed for both (item 140 closed). Also seen: the storefront notice on `fixfastapp.com` ("Payments are not available on this store yet"), the admin "Payments not approved" tag with the Approve button, the Purchase Balance portal and dashboard (no "wallet" word, the limit line, the headroom "you can add up to RM 99,996.56 now", the receipt link in Balance history, a failed order shown as refunded to the Purchase Balance).

**Defects seen on the failed-payment status page** (item 144, not blocking): "AMOUNT PAID RM2.07" on an order the page says was not charged; two badges at once ("Payment Failed" and "Waiting for payment"); the Method row prints raw codes (`fpx`, `duitnow_qr`).

**Not yet done.** Item 138 (a completed FPX and DuitNow payment; none has completed since #589), the affiliate portal's "awaiting approval" badge and the `/wallet` redirect on prod, step-up saves with an MFA admin, and approving a branded domain only after CHIP's written approval. `fixfastapp.com` stays active but unapproved (the FixFast Demo affiliate was not deactivated; the domain cannot take a payment either way).

**Later the same evening.** The affiliate portal's "Payments awaiting approval" badge on `fixfastapp.com` was seen (through admin impersonation). An Add Balance was started and cancelled at the bank: the attempt ended `failed` straight away (not after its 30 minutes) and the browser came back to `/balance`, so the wallet top-up path of item 140 works too. The legacy `/wallet` link redirecting has still not been hit on prod (a new return URL goes straight to `/balance`); it was checked locally (`308` to `/balance?topup=success`).

The legacy link was then checked on prod: `https://reseller.pekangame.space/wallet` answers `308` to `/balance`, and `/wallet?topup=success` to `/balance?topup=success` (query kept).


## 2026-10-30 — Failed-payment status page labels (item 144), smaller FPX tiles, "Other bank" link removed

**Item 144** (`OrderStatusTracker.tsx`): the amount box reads "Amount Due" until the order is paid; the delivery badge is hidden when the payment failed (it said "Waiting for Payment" next to "Payment Failed"); the Method row shows the channel label ("Online Banking (FPX)", "DuitNow QR") by reusing `CHANNEL_GROUPS`, now exported from `PaymentChannelPicker`.

**FPX grid** (founder: the logos were too big): storefront 6 columns on desktop and 4 on mobile (was 4 and 3), tile `h-10`, logo `max-h-5`. The founder also decided the 20 banks are enough, so the "Other bank" link is removed from the storefront grid and the partner portal grid (ADR-130 2026-10-30 addendum).

**Verified:** `tsc`/`lint` clean on the storefront and the portal. The grid was seen in the browser on `/order/arena-breakout` (desktop width, and 606 px, the smallest the window goes). The failed-payment status page was **not** seen: locally the browser's `track-order` preflight gets a 503 (curl gets 204), and the dev order is Pending, so check it on the PR's Vercel Preview with a real cancelled FPX order. Gotcha: after a `git checkout` the running storefront dev server kept serving the old classes until it was restarted.
## 2026-10-30 — Payment Receipt redesign (item 142, layout only)

The Purchase Balance receipt (`resources/views/receipts/purchase-balance.blade.php`) now follows a plain Stripe-style layout and carries the LWF logo (`backend/resources/img/lwf-logo.png`, black on transparent, embedded as a data URI). `ResellerWalletReceiptService` adds `bill_to` (business name, contact name, email). No money or ledger code changed. Decisions and what was left out (tax lines, card detail, address): ADR-131 2026-10-30 addendum.

**Verified:** `PurchaseBalancePortalTest` and `PurchaseBalanceAdminTest` 20/20 (new: the receipt bills the reseller and embeds the logo); the PDF was rendered and read page by page (title, logo, meta block, parties, headline, items, totals, note). Not rendered from a real prod top-up: do that after release with the FixFast test reseller.

## 2026-10-30 — Items 142 (rest) and 143 closed as not needed

Founder's decision after #602 and #603 merged to `staging`: the footer's hard-coded company details (143) stay as they are, and the three open parts of item 142 (admin download of a closed reseller's receipts, stored snapshot of the company details, automatic email) are not planned. Recorded in PRD §16 "Recently closed" and the ADR-131 2026-10-30 later addendum. Docs only; nothing built.

## 2026-10-30 — Affiliate monthly auto-payout (item 145, ADR-132)

The founder wanted the platform, not the affiliate, to open the withdrawal ticket: an affiliate could hold a balance for months and request any amount at any time. The money concept did not change (ledger debit still at admin approve, maker-checker unchanged).

**Design.** Grilled with the founder, then stress-tested by three read-only reviewers (creation and reserve; approve and accounting; portal, admin and hygiene). Their findings changed the design: the tier fee became a **reserve** held back from the payout (an early charge would shift billing cycles and push affiliates into `grace`); the amount is `min(balance at cut-off, current balance) − reserve`; the command runs **daily** at 00:10 MYT with a unique `payout_period` so a missed 1st repairs itself; only a **Pending** ticket blocks the next one; the "skip inactive affiliate" rule was dropped (in this codebase `inactive` only means soft-deleted, which needs a zero balance).

**Built (`feature/affiliate-auto-withdrawal`).** Migration `withdrawals.payout_period` + unique `(owner_type, owner_id, payout_period)`. `AffiliateWithdrawalService` is now `plan()` / `openMonthly()` / `openAll()` / `worklist()`; `AffiliateTierFeeService::feesDueBetween()` owns the cycle maths; `LedgerService::balance()` takes an optional `$before`. Command `app:open-affiliate-payouts` (`CRITICAL`, daily 00:10 MYT). Removed: `POST /affiliate/withdrawals`, `store()`, `CreateAffiliateWithdrawalRequest`, the portal form. Admin: `GET /withdrawals/payout-run` (skipped affiliates with a reason, stale Pending tickets), affiliate name and period on the index, a "needs attention" panel and a "this month only" toggle. Portal: schedule note, scheduled amount, tier-fee reserve, bank-details banner, copy changes.

**Gotcha found by a test:** a `Carbon` in Malaysia time bound into `where created_at < ?` uses its local clock, but `created_at` is UTC, so the cut-off was 8 hours off. `LedgerService::balance()` now converts to UTC itself.

**Verified.** Backend suite green apart from `RouteAccessTest` (new admin route, fixed); the concurrency suite on real MySQL: two simultaneous runs open exactly one ticket, and the six existing withdrawal concurrency tests pass. Portal and admin: `tsc`, `lint`, unit tests clean, admin `build` passes. Dev DB migrated (plain `migrate`). Production read-only before the build: 2 affiliates, none at RM10 or more, no open tickets. **Admin page seen in a local browser** (Playwright, throwaway sqlite DB, light, dark and 390 px mobile, no page overflow): the needs-attention panel, the Affiliate/Period columns and the this-month toggle work. That check showed the stale-ticket line had no affiliate name; fixed (the payload now carries it). Approve from the page was not exercised: it needs MFA step-up, which the throwaway admin does not have (backend tests cover it). **Portal seen in a local browser too** (same setup, two affiliates; light, dark, 390 px, no console errors, no page overflow): the Withdrawal page shows the schedule note, the scheduled RM 220.00 ticket, RM 30.00 kept for the tier fee with its due date, the bank, and the bank-details banner for an affiliate without bank (the warning text colours were read with `getComputedStyle`); Dashboard and Earnings show "Earnings balance" and "Monthly payout" and no request button.

**Accepted risk.** A tier change to a higher fee after the ticket opens, "Charge Now", or `reactivate()` can push the balance under the ticket; approve then returns a clear 422 and the admin rejects (the money joins next month's payout).

## 2026-10-30 — Storefront: Bumblebee header colour; package list browsable before the ID (founder, Ohaha store)

Two founder findings from the live `ohahastore.com` on a phone. **Header:** the header and the page were the same cream (`bg-surface` on both), so the nav bar had no edge; Cyber Bumblebee now has a yellow header with an ink "Track Order" button, through five new `--color-header*` tokens whose defaults leave every other preset unchanged (ADR-090 addendum). **Package list:** Step 2 was `inert` until the ID was verified, so "Show all N packages" could not be pressed; it is now browsable, a tapped package is kept, and on a phone the page scrolls up to Step 1 (ADR-064 addendum). Step 3 now needs both the ID and a package.

**Verified.** `tsc` and `lint` clean; reseller tests pass (its `theme-presets.ts` copy regenerated with `sync-shared-files.mjs`). Seen in a headless browser on a throwaway sqlite backend with the e2e data and 17 packages: mobile header yellow `rgb(255,199,0)` against the page `rgb(255,248,225)`, button ink with yellow text; Step 2 not inert and not dimmed; "Show all" works while locked (17 packages); tapping a package scrolled from y=1014 to y=408 with the ID field in view, the package stayed selected, Step 3 stayed locked; entering the ID and Continue unlocked Step 3 and scrolled to it; on a 1280 px desktop tapping a package scrolled 0 px and the active nav link is ink with an ink underline. **Not seen:** the dark mode of Bumblebee (read from `generateThemeCss`, which applies only `tokensDark` in dark mode, so the header falls back to the dark surface), and the Joki and membership order pages (untouched). The e2e checkout spec (`storefront-checkout.spec.ts`) has not been run on this branch.

**Not in this change:** the `www.ohahastore.com` certificate error (the affiliate adds `www.` as a second domain in the portal for now; the automatic `www` pairing is a separate item that needs its own ADR addendum to ADR-060).

## 2026-10-30 — Fix: approving a domain's payments failed with "The approved field is required"

Found by the founder approving `ohahastore.com` in the admin. `approveAffiliateDomainPayments()` (ADR-131 PR C, released in #599) passed `body: JSON.stringify({ approved })` to `apiFetch`, which already JSON-encodes its body, so the server received a JSON string, not an object, and Laravel saw no `approved` field. The backend and its tests were right (they send a plain object); no frontend test sent the real request, so it shipped. It was the only `apiFetch` caller doing this (the other `JSON.stringify` bodies in the apps call `fetch` directly).

**Fix:** `body: { approved }`, plus `admin/src/lib/api-body.test.ts`, which fails if any admin file that uses `apiFetch` passes a `JSON.stringify`'d body (red on the old line, green on the fix). **Verified** in a browser on a throwaway backend: the PUT now carries `{"approved":true}` as `application/json`; the throwaway admin has no MFA so the step-up answered 403 there, which the real admin does not hit. Admin tests 20/20, `lint` and `tsc` clean.

**Also in this PR:** CI's `backend-tests` failed once on `CustomerAnalyticsListingTest::test_search_matches_part_of_the_email_ignoring_case_and_wildcards`, unrelated to the change: every order in that class gets `paid_at = now()` less some days, so a second boundary between two orders reversed the tied `last_order_at` sort (the same flake class as 2026-10-16, which had frozen time in one test only). `setUp()` now freezes time for the whole class. Proved by adding `sleep(1)` to the order helper: red without the freeze, green with it.

The next CI run failed `backend-concurrency` on `CheckoutSettleWithVoucherConcurrencyTest` (two simultaneous full-cover checkouts). The money was safe (the voucher was spent once and the second checkout refused), but the loser was refused earlier, with `InvalidVoucherException`, because a slow CI start put its `initiate()` after the winner had committed, and the test treats any `error:` as a failure. `app:checkout-test-initiate-full-cover` now takes an optional `startAt`: both processes boot, then wait for the same instant before `initiate()`, so they overlap. Green 3 of 3 on local MySQL; CI to confirm.

**Owed:** the fix is on `staging`, not live: the admin Vercel app deploys from `main`, so the approval works in production only after a `staging` → `main` release. The founder will approve `ohahastore.com` after that, once CHIP has approved it in writing (ADR-131 condition 5).

## 2026-10-30 — Releases #608 and #610, the header mix-up, and the owned-brand facts

**Releases.** #608 (`staging`→`main`, merged 07:31 UTC as `8f3e9ea8`) shipped #601–#607: the affiliate monthly auto-payout (ADR-132), the receipt redesign and failed-payment labels, packages browsable before the ID, the domain payments-approval fix, and two flaky-test fixes. #610 (merged 08:44 UTC as `9140ba8c`) shipped #609: the original header restored and the bottom nav labels fixed. Both deploys were green; read-only checks on prod afterwards are in PRD §14 ("Latest release 2026-10-30").

**Corrections to earlier entries of today (this log is append-only).**
- "Storefront: Bumblebee header colour; package list browsable…" — the header half was a **mistake**. The founder's report was about the mobile **bottom nav**; I read "nav bar" as the header and made it yellow. #609 removes it. What actually shipped for the nav is the label colour fix (ADR-090 addendum, later). Lesson: when a UI term can mean two elements, confirm which one on the screenshot before changing anything.
- "Fix: approving a domain's payments failed…" — said "Owed: not live". It was released in #608.
- "Affiliate monthly auto-payout" — said "Not seen in a browser" then "seen" in the same entry; both are now seen. `MoneyFlows::affiliateWithdraws` (ADR-127's seeder) used the removed `request()`; 27 accounting tests failed until it wrote the ticket directly. Lesson: grep the **whole repo** (including `database/seeders`) for a method's callers before removing it; the first grep covered `app`, `tests` and `routes` only.

**Bottom nav.** Labels were `text-on-primary` on `bg-ink`; Cyber Bumblebee's `on-primary` is ink, so ink on ink. Now `text-surface` / `text-surface/60`: 16.18:1 and 6.52:1 on Bumblebee light, measured on a local storefront (alpha composited; the first measurement used a colour parser that misread `oklab()` and was wrong). The other presets and Bumblebee dark were not measured: the theme switch in my throwaway harness did not take effect (the storefront and the API both cache branding), so only the first state was valid. The founder confirmed `ohahastore.com` on a phone after the release.

**CI.** Two flakes blocked #607 and are fixed there. `CustomerAnalyticsListingTest`: every order got `paid_at = now()` less some days, so a second boundary reversed a tied sort; `setUp()` freezes time (proved with a `sleep(1)`). `CheckoutSettleWithVoucherConcurrencyTest`: on a slow start the second process began after the winner committed and was refused earlier (`InvalidVoucherException`), which the test counts as an error; the money was safe (one voucher spend, one refused checkout). `app:checkout-test-initiate-full-cover` takes an optional `startAt` so both processes overlap. Local MySQL: 3 of 3 green.

**OhahaStore (`ohahastore.com`).** The apex had a fresh certificate; `www` (not registered here) served an expired `*.ohahastore.com` wildcard from February 2026, so a PC showed `ERR_CERT_DATE_INVALID`. Adding `www` as a second domain stayed `pending`: Vercel wants one `_vercel` TXT per hostname. ADR-060 addendum and PRD items 147 and 148 hold the details. Also found in prod: OhahaStore has no bank details.

**Owned-brand numbers (read from code and prod, for the next session; PRD item 149).** `is_owned` changes reporting, membership and Joki gating only. Prod: PekanGame (owned, primary, markup 0%, no tier); OhahaStore (owned, markup 0%, max 20%, membership on, active "Owned Brand" tier: fee RM0, markup 15%); FixFast Demo (not owned, markup 5%, max 25%, the same tier). Price = cost × 1.15 × (1 + brand markup %) for a tier brand; the standard price for a brand without one; with 1,249 of 1,257 active packages at exactly 15% markup the two agree today at markup 0%. The member price is cost × (1 + package markup × (1 − discount)) with Tier 1 = 50% and Tier 2 = 70%, so it ignores the brand's tier and markup. The screenshot figures RM1.13 and member RM0.97 (−14%) fit a ~5% brand markup at the time (cost 94 sen: 108 + 5 = 113), and OhahaStore's row was updated 14:19 MYT, after the screenshot (14:06): probably the markup went back to 0%. Not proven.

## 2026-10-30 — An owned brand prices as the platform: no tier, no brand markup (ADR-061 addendum, PRD item 149)

**Why.** An owned brand (OhahaStore) ran the third-party affiliate pricing path (wholesale tier plus its own markup) and matched PekanGame only because the tier's 15% equals the packages' 15% and its markup was 0. They already disagreed by 1 to 2 sen on 29 active combos (whose standard price is summed per leg). Raising an owned brand's markup would have put `affiliate_profit` into its own ledger account, and the monthly auto-payout (ADR-132) would have paid it to the brand's bank. The founder and I grilled it (two rounds, then a stress test), traced it against code and read-only production data, and recorded it as an addendum to ADR-061 (Accepted).

**What shipped (branch `fix/owned-brand-pricing-seam`, one PR into `staging`, not released).**
- `Affiliate::storefrontPriceTerms()` returns (tier, markup): `(null, 0)` for an owned brand, otherwise the tier and `min(markup, max_markup_pct)` (null ceiling = none). Checkout, checkout preview, the catalog, voucher preview and the portal's pricing preview read it; so do the primary's three raw reads (member-savings badge, plan preview, sandbox order).
- Guards at both ends: the read-time seam ignores a stored markup or tier on an owned brand; the admin form forces markup 0 (one `affiliate_markup_changes` row, source `admin`, written for every admin markup change), refuses a tier, hides the fields; the portal refuses markup writes with a 422.
- `Affiliate::tierSubscription()` (null for owned) feeds the portal Subscription screen and dashboard; the dashboard gains `is_owned`. "Charge fee now", "Reactivate" and the tier-fee cron skip owned brands. The catalog cache flushes when `is_owned` or `max_markup_pct` changes.
- Tests: `Adr061OwnedBrandPricingTest` (seam parity matrix for a third-party brand and the primary; owned brand priced as standard on checkout, preview and catalog, including a 1-sen-off combo; member price never above the guest price; ceiling clamp and null ceiling), plus additions to the admin affiliate, portal pricing, portal read and tier-fee command tests. Two older tests that set a non-zero markup on the primary (an owned brand) were rewritten to prove the new rule. Backend 3,251 passed (after the review fixes; the concurrency suite was not run, no locking changed); admin and portal `tsc` and `eslint` clean; `node --test` 20 and 9 passed.

**Review.** Three read-only forks (checkout and pricing; ledger, fulfilment and payout; downstream readers) found seven real gaps, all fixed with tests: the ceiling's cache flush, the portal's null-ceiling contradiction ("ceiling is now 0%"), the primary's raw reads, "Charge fee now" and "Reactivate" on an owned brand, the admin detail still showing a tier, the admin form wiping an owned brand's ceiling on save, and the dashboard's tier copy. The tier-fee cron's first version used `whereHas`, which silently excluded soft-deleted affiliates; it is now a `whereNotIn` over `withTrashed()` so non-owned brands bill exactly as before.

**Verified in a browser** (throwaway backend, the admin and portal dev servers, an owned and a third-party brand each). Admin: the list shows the owned brand as "walk-in rate"; its detail shows "Markup %: None (owned brand)" and a Pricing note with no tier controls; its edit form has no markup, ceiling or tier fields. Portal: the owned brand's dashboard has no wholesale-tier card, its Subscription screen reads "You are not on a wholesale tier — your storefront prices at the standard guest rate", and its Pricing tab shows the no-markup notice; the third-party brand shows the tier card, its plan and the markup form as before. **The e2e suite** passed 9 of 9 locally after the founder said to stop the stale servers from the first session (which held :3001 and :8000, a throwaway sqlite that no longer existed); CI's `playwright` job was green too.

**Production facts recorded.** The "Owned Brand" tier is 15% (it appears to have been edited from 10% on 2026-10-30, two minutes after OhahaStore's subscription); member Tier 2 is 70% off the markup. ADR-125 and ADR-126 carry dated pointers to the right numbers. OhahaStore has 0 orders; no data migration was needed.

**Owed.** Not live until a `staging` → `main` release. After it: the RM0 OhahaStore tier subscription can stay (it prices nothing); rename or remove the "Owned Brand" tier after FixFast Demo is deactivated (item 141).

## 2026-10-30 — A custom domain brings a `www` redirect twin (ADR-060 second addendum, PRD item 148)

**Why.** The first `www` report (OhahaStore) had been fixed by hand: `www.ohahastore.com` was added as a second full domain and approved separately, so the brand had two identical addresses and two CHIP approvals. The founder asked to automate it. Grilled with the founder (five questions, all accepted), then stress-tested against the lifecycle code and Vercel's documentation.

**Facts checked.** Vercel's add-domain endpoint takes `redirect` and `redirectStatusCode` (301, 302, 307 or 308), and `PATCH /v9/projects/{project}/domains/{domain}` takes the same, so an existing domain can be converted without being removed. Vercel asks for an ownership TXT only when the domain is in use by another Vercel account or team, and a removed and re-added domain gets a new value: the earlier addendum's "one TXT per hostname" over-generalised OhahaStore's case and is withdrawn (the portal guide already said so). On production, both OhahaStore hostnames were `active` with valid certificates and approved payments, each serving the store (200).

**Stress-test findings that shaped the design (all fixed in the build, not patched later).** Every reader of `affiliate_domains` assumed a row is servable: the first domain to go active became primary, so a twin verifying first would have become the canonical origin; `canonicalOrigin()` and the CORS origins would have listed it; `ResolveStorefrontBrand` would have resolved it; the cap would have counted it; the CHIP approval control would have offered it; the sync command would have emailed reminders for it. Converting must use PATCH because a re-added domain gets a new ownership value. Removal must detach the twin before its target.

**What shipped (branch `feature/affiliate-www-pairing`, not released).** Migration `2026_10_30_180000_add_redirects_to_domain_id_to_affiliate_domains`; `AffiliateDomain::servable()` used by every reader; the provider's `attach($hostname, $redirectTo)` and `redirect()`; `AffiliateDomainService::pairWww()` (create, re-attach, convert in place, idempotent); portal `with_www` option and `POST /api/affiliate/domains/{domain}/www`; the Domains screen and guide (English and Malay); admin approval refused for a twin and hidden in the detail modal.

**Tests.** `AffiliateDomainWwwTwinTest` (16: creation with the redirect target, idempotence, refusals, provider failure leaving no twin, never primary, no failover to a twin, set-primary refused, not canonical, not a CORS origin, not resolvable, cap, removal order, delete order, stuck-pending teardown, conversion in place, no reminders), plus additions to the Vercel provider, portal domain controller and payment-gate tests. Backend 3,278 passed; admin and portal `tsc`, `eslint` and `node --test` clean; no API-spec drift (`scramble:export` against a migrated database); the migration on real MySQL 8.4 shows one explicit index (reused by the foreign key, no duplicate) and `ON DELETE CASCADE`. **In a browser** on a throwaway backend: the option ticked by default and disabled for a `www.` name, a domain added with its twin ("Redirects to …"), "Add www" on one added without, "Redirect www here" converting a hand-added `www.` row, the counter at 3 of 5 with three twins, and the admin detail with no approval control on a twin.

**Owed.** Not live until a `staging` → `main` release. Then the founder presses "Redirect www here" on OhahaStore's Domains screen (the only path: no console command, no hand-run patch), and `curl -I https://www.ohahastore.com` should answer 308 to `ohahastore.com`; the `www` row's CHIP approval stops mattering.

## 2026-10-30 — A delivery receipt that never came (PG-L4RCBELJ9RE3), the phone normaliser, and item 138 closed

**Report.** The founder asked why a customer who used the Contact Support button (a failed order), got the status card, and then had the item resent and delivered, received no delivery message.

**Cause (read-only on prod).** The order's `customer_phone` had ten digits starting with `1`: a Malaysian mobile typed without its leading `0`. WhatsApp wrote from the twelve-digit `60…` number, so the silent opt-in (12:29:06 UTC) was stored under the `60…` number while `orderDelivered()` normalised the order's number to the unchanged `1…` ten digits, found no opt-in and returned without a row (by design). `PhoneNumber::normalize()` took anything not starting `0` or `00` to already carry its country code. 1 of 42 real storefront orders had this shape. The same normaliser feeds joki updates, voucher messages and the voucher redemption phone match.

**Fix (branch `fix/phone-normalize-malaysian-mobile-without-zero`).** A 9 or 10 digit number starting with `1` becomes `60…` (country code 1 numbers always have 11 digits). Tests: `PhoneNumberTest` (the new shapes, North American numbers kept, every way of typing one mobile is the same number) and an end-to-end case in `CustomerWhatsAppOptInTest` that reproduces the order (number typed without the zero, opt-in from the full number, receipt after a Mark Delivered). Recorded as ADR-116's 2026-10-30 addendum. The customer's missed receipt is not recoverable by code; the founder was advised to tell them by hand (not confirmed as done).

**Item 138 closed.** The same order was a completed DuitNow QR payment (RM25.70; CHIP `paid`, `is_test` false, method `dnqr`, one successful attempt), and an earlier order was a completed FPX payment through the bank grid (Maybank2u). With the **Test** results, every part of item 138 is proven. The CHIP responses carried the buyers' names and emails; none of it is recorded here.

## 2026-10-30 — Membership OTP emails landing in spam: diagnosis, Plunk and DNS changes, and a subject/copy change

**Report.** The founder said the membership code email goes to the spam folder, and shared one such message (Gmail "Show original").

**Diagnosis (read-only).** Authentication was fine: SPF PASS, DKIM PASS with `d=pekangame.space` (aligned), DMARC PASS (the zone has `p=quarantine`). The causes were in the message and the sender: (1) Plunk's project tracking was **Enabled**, so every link went through the shared `track.useplunk.dev` redirect, a 1x1 open pixel was added, and the free plan's "Powered by Plunk" badge image and link; (2) the sender domain `pekangame.space` differs from the domain every link points to (`pekangame.com`, the real storefront, which `.space` redirects to), and `.space` is a low-reputation TLD; (3) `Reply-To` was `noreply@pekangame.space`, a domain with no MX; (4) a generic subject.

**Changes made by me in the founder's logged-in browser, with the founder's go-ahead (before to after).**
- **Plunk, Settings, General:** Email tracking `Enabled` to `Marketing only` (transactional sends are no longer tracked; campaigns still are). Reversible in the same place.
- **Plunk, Settings, Domains:** added `pekangame.com` (it was not there; only `pekangame.space` was). Optional Custom MAIL FROM and inbound were skipped on purpose.
- **Cloudflare, `pekangame.com`, DNS:** 9 records to 12. Added three CNAMEs, **DNS only**, for Amazon SES DKIM: `dhzrtflz5z4zybf2nrwmdcjezlamtaqj`, `zba5w2gyjitwlfsifm6pjt2iuldtmg5z` and `jtlpl5rv4oaxkl4rumi6z6nxe4rrrgg3`, each `._domainkey` to `<same>.dkim.amazonses.com`. **The SPF record was not touched** (it also serves the Zoho mailbox; DMARC only needs the aligned DKIM), and the Zoho MX, SPF, verification and DKIM records were read back unchanged afterwards. Plunk then reported "Domain pekangame.com is verified!".
- **A slip, disclosed.** One Save click missed (the form grows after the target is typed), and the follow-up clicks landed on the Cloudflare dashboard and opened the `fixfastapp.com` overview. Nothing there was edited (no form was open); the `pekangame.com` record count was checked (10, only the intended one) before continuing, and each remaining record was zoom-checked before Save.
- **Not done by me:** buying the Plunk plan that removes the badge (a purchase; the founder says they will subscribe), and the production `PLUNK_FROM_EMAIL` change (handed to the founder as exact commands, below).

**Code (branch `fix/membership-otp-email-deliverability`).** The OTP email's subject is now `{code} is your {brand} verification code` (the form Gmail trusts for one-time codes), with a test that was red first, and the storefront's code screen says to check Spam or Junk and mark the email Not spam. No Reply-To change: I could not confirm Plunk's field for it.

**Owed.** The sender switch is not made: set `PLUNK_FROM_EMAIL=noreply@pekangame.com` on the Forge box (blast radius: every system email, which all go through Plunk), `php artisan config:cache`, `php artisan horizon:terminate`, then request one OTP and read the Gmail headers. Optionally a Zoho alias for `noreply@pekangame.com` so replies do not bounce. The code change ships with the next release.


## 2026-10-30 — Local test runs sent real emails through production Plunk (found while checking the sender switch)

**Found.** After the founder switched `PLUNK_FROM_EMAIL` on production (checked read-only: `noreply@pekangame.com`, the config cache applied, Horizon restarted 30 minutes earlier, no `Plunk send failed` in the log), I opened Plunk's Activity to look for a send from the new sender. It listed batches of emails to addresses like `member@example.com`, `waived@example.com`, `race-sub@example.com` and `dereck33@example.net` ("Your Tier 2 membership — payment received", "[PekanGame Security] Account locked"), at times that match local test runs. Plunk's Analytics for 1–30 October: **513 sent, delivery rate 5.8%**, open rate 3.1%, bounce rate 0.19%, so most of what the project sent was test mail, and only a few dozen messages were real.

**Cause.** `backend/.env` holds real keys for local work: Plunk, CHIP, Gamevion (key and bearer), Gemini and the supplier proxy (checked as set or not set only; no value was read). `phpunit.xml` and `phpunit.concurrency.xml` did not blank them, and the e2e boot script did not either, while CI has none of them. A test that did not fake an outbound HTTP call therefore called the real service. Running the suite with the keys forced blank and `Http::preventStrayRequests()` showed four leaks: the admin lockout test (it sends the "Account locked" alert through Plunk, the source of those emails) and three Digiflazz tests that fetched the IDR to MYR rate live from `open.er-api.com` (they depended on the public internet, so they could also fail in CI for no reason of ours). The "payment received" and "race-*" mails came from membership and concurrency tests, which the blank key now also stops. This was not new: the earlier 30 days carry the same pattern. My own full-suite, concurrency and e2e runs today added to it (about six full runs), which I did not know until now.

**Effect on the OTP spam report.** Probably small: Gmail never saw these messages, so they do not enter the sender reputation it judges. They do pollute the project's statistics (the 5.8% delivery rate), they added example.com recipients to Plunk's contacts (127 contacts today), and sending to invalid addresses at volume is a risk to the sending account's standing. The causes found earlier (tracking, domain mismatch, `.space`, low volume) stand.

**Fix (branch `fix/tests-never-use-live-credentials`).** Both phpunit configs force `PLUNK_API_KEY`, `CHIP_SECRET_KEY`, `CHIP_BRAND_ID`, `GAMEVION_API_KEY`, `GAMEVION_BEARER_TOKEN`, `GEMINI_API_KEY` and `SUPPLIER_PROXY_URL` blank (CI's state); `Tests\TestCase` calls `Http::preventStrayRequests()`; the four leaking tests now fake their calls (the lockout test also asserts the alert was sent); the e2e boot script blanks the same keys; `backend/AGENTS.md` records the rule. Backend fast suite 3,286 passed; e2e 9 of 9 after the change.

**Not done / owed.** Mail already sent cannot be recalled. The founder may want to delete the example.com contacts in Plunk (Contacts) so the project's numbers reflect real customers. Nothing here changes production code.

**Also fixed here: a concurrency flake that failed PR #616's CI** (a docs-only PR). `ResellerClosureConcurrencyTest::test_two_simultaneous_closures_refund_once` got outcomes `["", "closed:150000"]`: its helper command looked the reseller up with `findOrFail` at start, and when the other racer had already soft-deleted it (two booting processes on a loaded runner), the helper threw `ModelNotFoundException` and wrote nothing instead of a refusal. Money was never at risk (exactly one closure and one refund). The helper now treats an account that is already closed as a refusal (`refused:`), which is what a later attempt always was. The fix and the credentials change are in the same PR because both are test hygiene.


## 2026-10-31 — Partner onboarding designed (ADR-133, PRD §16 items 13 and 146); nothing built

**Request.** The founder asked for a docs and backlog audit, then to grill item 146 with the reference video (a UX-pattern video for a budget app: one question, a checklist that starts at 1 of 3, one-button empty states, one coach mark, a skip that remembers). Transcribed locally (the audio only repeats the on-screen text). It is a style reference, not a spec.

**What the grill settled (four rounds, then a code stress test).** The founder widened the scope: a public reseller application with KYC (documents only **after** a first screening, so no identity document is held for applicants who will not be accepted), plus onboarding inside the portal. All decisions are in [ADR-133](./adr/ADR-133-partner-onboarding.md). Item 13 (self-serve reseller signup) is folded into item 146.

**Facts found read-only (code, 2026-10-31).** No onboarding, first-run or readiness code exists. The dashboard's "Live" badge ignores `payments_approved_at`. Bank details are never forced and the admin cannot edit them; the "bank present" check is already copied in two places. A reseller with no tier cannot order but can still add balance; creating a reseller makes no login. The admin sandbox (ADR-018) is alive (21 tests pass) but admin-only; reseller paths never mention `is_test`. The Spatie backup is a SQL dump only, so documents would never be in it and deleted rows linger in older dumps.

**Stress test (seven findings, all folded into the ADR).** One `Affiliate::hasBankDetails()` instead of a third copy; the tier guard goes in `ResellerWalletTopupService::initiate()` and never in `completeTopup()`; one `ResellerProvisioningService` for the application approval and the admin create; retention wording admits dump retention; host split in `proxy.ts`; an email of a closed account stays reserved; a single `PartnerApplicationService` with a row lock and a unique `reseller_id`.

**PDPA research.** Notices must be Malay and English (section 7). The 2024 amendment adds breach notice, DPO thresholds (far above our size) and a new cross-border regime; the figures come from secondary sources and are marked to re-check. No NRIC guidance was found. The notice text is to be written with a lawyer.

**Backlog.** PRD §16: item 13 points to 146; 146 carries the build order and the owed list; new group D items 151 (partner sandbox, its own money-critical ADR) and 152 (refresh the admin sandbox). **Not touched:** PRD §14 still says the OhahaStore `www` row "becomes a redirect after the next release"; item 148 is built and released, so that sentence needs a docs-audit fix.

## 2026-10-31 — Month-end CI failures: four tests built "last month" with `now()->subMonth()`

**Found.** PR #619 (docs only) failed `backend-tests` (3 tests) and `backend-concurrency` (1), all around the affiliate payout and customer analytics. CI ran at 18:54 UTC on 2026-10-30, which is 02:54 on 31 October in Kuala Lumpur.

**Cause (tests, not production).** Two date traps in the test setup: `now()->subMonth()` on the 31st overflows ("31 Sep" is 1 Oct) into the current month, and a UTC "last month" on the last evening of a month is already the current month in Kuala Lumpur, where the payout and the reports cut the month. The payout command runs on the 1st at 00:10 MYT, which never overflows, so production was not affected. `CustomerAnalyticsServiceTest` had been fixed once for the UTC side (2026-09-30/10-01) but still used `subMonths(1)` in Malaysia time.

**Fix (branch `fix/date-dependent-tests-month-end`).** One shared `TestCase::midLastMonth()` (the middle of last month, Malaysia time, safe on every day) used by the four affiliate tests; `subMonthsNoOverflow(1)` in the analytics test. Of the 14 `subMonth`/`addMonth` uses in tests, the other 7 only mean "in the past" or "in the future" and are harmless on any day. Red first: on this machine's clock (31 Oct, 09:28 MYT) the old code failed 2 of 30; the new code passes 30 of 30, and the full fast suite passes (3286). The concurrency suite needs Docker MySQL, so CI is its proof.

## 2026-10-31 — Partner onboarding PR 1a: the backend foundation (ADR-133)

**Built (no UI, no migration, no behaviour change for any user).**
- `Affiliate::hasBankDetails()` replaces two hand-written copies (the payout plan's `no_bank` skip and the portal's `has_bank_details`); the readiness service is the third reader.
- `AffiliateDomain::paymentsApproved()` replaces three `payments_approved_at !== null` reads (the storefront resolver, the admin domain list, the portal domain list). `Order::scopePaidAndReal()` replaces the paid + `is_test = false` pair in the affiliate sales figures.
- `ResellerProvisioningService` (`create()`, `addPortalUser()`): `Admin\ResellerController` `store()` and `storeUser()` call it; same behaviour, one copy.
- `PartnerReadinessService` (`forAffiliate(s)`, `forReseller(s)`), `PartnerReadiness`, `ReadinessStep`, `StepState`. Read-only, a fixed number of queries per batch (tested with 2 and 9 partners).

**Verified.** Red first (24 errors, the classes did not exist). The invariant test asks the real storefront (the public branding endpoint, ADR-131's gate) across seven domain states and both account states; a deliberate mutation (readiness ignoring the payments approval) made it fail, then it was restored. Full fast suite 3312/3312. Pint passes on the changed files (it fails on an untouched seeder, `database/seeders/FinancialScenario/MoneyFlows.php`, and CI does not run it).

**A test trap met again.** Sanctum keeps the first authenticated user for the rest of one test, so two portal requests as two users in one test both ran as the first: one affiliate per test.

**Next.** PR 1b: the endpoints that expose this, the portal checklist, banners and empty states, the admin readiness column, and the approval email.

## 2026-10-31 — Partner onboarding PR 1b: checklist, banners, empty states, admin Setup column, approval email (ADR-133)

**Built.**
- **Backend:** `GET /api/affiliate/onboarding` and `GET /api/reseller-portal/onboarding` (each behind its own account-type gate); a `readiness` summary on every row of the admin Affiliates and Resellers lists (one batch, no query per row; the platform's own brand has none); `PaymentsApprovedNotice`, an email to the affiliate when an admin approves a domain for payments (only on the move to approved; a mail failure never undoes it). The `order_channel` step now says which channels are set up (`api_key`, `whatsapp_group`).
- **Portal (`reseller/`):** a collapsible "Get started" checklist (collapsed by the partner, remembered in `localStorage`, or by itself when every required step is done); non-dismissable banners for what costs money (no bank details, payments off, a reseller with no tier); the store badge split into "Ready to sell" and "Online, payments off"; one-button empty states for Orders; the WhatsApp bot's link state and instructions; a "Your first order" card (there is no partner sandbox); the DNS teardown deadline; the set-password page in English.
- **Admin:** a "Setup" column on the Affiliates and Resellers lists.

**Verified.** Backend tests red first, then green; full fast suite 3324/3324. Portal and admin lint, `node --test` (18 and 20) and build pass. A browser check on a throwaway backend (sqlite file in the scratchpad, API on :8000, portal :3002, admin :3000; all stopped and deleted afterwards): 44 checks across every state, mobile 390 px, contrast at least 4.5 in light and dark, and screenshots read by eye. The browser check found one thing the tests did not (an Optional step with a destination showed no link); fixed.

**Traps met.**
- I ran Pint over the whole of `app/` and it reformatted 25 untouched files (the repo has existing Pint failures; CI does not run it). Reverted with `git checkout` on every file that was not mine. Run Pint only on the files you changed.
- A "queries do not grow with the rows" test must not compare equal counts: the first measured request pays one-off queries (settings cache). Warm it, then assert the larger set is not more than the smaller.
- A set-password field with `minLength=8` stops the browser before the JS message appears, so a check of that message needs a long enough, mismatched pair.
- Sanctum keeps the first authenticated user for the rest of a test (met again in PR 1a).

**Next.** PR 2 (the tier-less top-up guard in `ResellerWalletTopupService::initiate()`, never `completeTopup()`; the admin create made to use `ResellerProvisioningService` with the tier required; wallet trace first), then PR 3 and PR 4.

## 2026-10-31 — Partner onboarding PR 2: the tier-less top-up guard, the admin create with a login, Create internal account (ADR-133)

**Built (branch `feature/partner-onboarding-pr2-topup-guard-provisioning`).**
- **Top-up guard (decision 20):** `ResellerWalletTopupService::initiate()` refuses a reseller with no tier before any CHIP call; the portal answers 422, the bot replies in Malay (`topup_no_tier_assigned`). `completeTopup()` is untouched. The portal's no-tier banner and checklist step now say adding balance is blocked too.
- **`ResellerProvisioningService::provision()` (decision 21):** reseller, wallet account, first portal login and invite in one transaction, tier required in the service. The admin "Add Reseller" requires an active tier, the contact name and an email no portal user has.
- **Create internal account (decision 12(c)):** `POST /api/resellers/internal`, super_admin + MFA step-up, a required reason in `resellers.internal_reason` (new migration) and the log. The list shows "Internal · No KYC on file" or "KYC outside the system".

**Trace before code.** Callers of `initiate()`: the portal, `.topupbaki`, the `FinancialScenario` seeder (its reseller has a tier) and the race-test command. Credit paths: the CHIP webhook, the 15-minute reconcile, the race-test complete command, the admin manual credit; none reads the tier. A tier can only be swapped, never cleared. Production (read-only, 2026-10-31): 3 resellers, all with a tier and a login, no pending top-up.

**Verified.** Red first (top-up: 3 failing; provisioning and admin: 11 failing), then green. Full fast suite 3335/3335. The concurrency suite on local MySQL: 38 of 42, every wallet, top-up and order race passing; the 4 failures are `BackupRestoreMysqlTest`, which needs a `mysqldump` binary this machine lacks (CI has it). Admin `tsc`, lint, 20 unit tests; portal `tsc`, 18 unit tests. Three read-only review forks (wallet and ledger; provisioning and auth; admin, portal and bot readers): no money finding; their three small findings were fixed (an inactive tier refused on create, the race-test command catching the new refusal, the portal copy). Browser check on a throwaway backend with Plunk pointed at a local fake (no real email): the submit stays disabled until a tier is chosen, the inactive tier is not offered, create sends the invite and the row shows "KYC outside the system", a taken email shows "email has already been taken", the internal form sends the reason and a no-MFA admin gets the step-up refusal, the Internal tag and the KYC line have contrast 5.3 or more in light and dark. Everything stopped and deleted afterwards.

**Trap.** The local `.env` has a live Plunk key, so a browser check that creates a portal login sends a real email unless `PLUNK_BASE_URL` points at a fake.

**Not done.** FixFast is not marked internal in production (a data write, the founder's call). Next: PR 3 (the public application, KYC, retention), then PR 4 (`max_markup_pct`).


## 2026-10-31 — Game ID Checker: OhahaStore's checker stuck, and a message when Turnstile fails (ADR-123)

**Found.** `https://ohahastore.com/check-id/mobile-legends` stayed on "Verifying you're human…" with Check ID disabled; the browser console showed Turnstile error `110200` (hostname not allowed). The widget "PekanGame Game ID Checker" lists only `fixfastapp.com`, `pekangame.com`, `pekangame.space`. ADR-123 already says every new affiliate domain must be added to the widget; it was missed when OhahaStore went live.

**Fix (two parts).** (1) Add `ohahastore.com` to the widget's hostnames in Cloudflare (a founder action; staged in the dashboard but not saved by Claude). (2) Code: `PlayerCheckClient` now shows "We couldn't verify you on this page. Please reload, or try again later." when Turnstile calls its error callback, instead of waiting forever; a later success clears it.

**Verified.** Local storefront against a throwaway backend with one checker family, using Cloudflare's test site keys: the always-failing key shows the message with the button disabled (390 px); the always-passing key shows "Ready to check." and no message. Everything stopped and deleted afterwards.

## 2026-10-31 — CHIP approved FPX and DuitNow QR (docs only)

Recorded from the founder: the reply to CHIP (PRD §16 item 141(d)) was sent on 2026-10-29. CHIP answered that it is checking the documents, and that FPX and DuitNow QR may be used meanwhile; its other payment methods are still under review. The email is the founder's record outside the repo. PRD §14 (payment methods: FPX no longer "on only while CHIP reviews"; the stale "`www` becomes a redirect after the next release" line), §16 items 141, 114 (the CHIP gate) and 129 (the founder's choice between voucher-only and refund to the original payment method is now due). Still open in ADR-133: whether CHIP receives copies of reseller documents.

## 2026-10-31 — ADR-133 PR 4: a blank markup ceiling opens no markup (ADR-061 addendum)

**What.** `max_markup_pct` null had two meanings: no ceiling in the admin form and in `Affiliate::storefrontPriceTerms()`, a ceiling of 0 in the portal's markup save and Pricing tab. The founder chose fail-closed (ADR-061 2026-10-31 addendum): null is a ceiling of 0 everywhere. `storefrontPriceTerms()` clamps to `max_markup_pct ?? 0`; the admin create and edit refuse a non-zero markup on a third-party brand with no ceiling (422 on `markup_pct`; an owned brand is forced to 0 as before); the admin form checks the same and its hint says blank means no markup. The portal already behaved this way and is unchanged.

**Trace.** Every writer of the column: the admin store/update (validated `gte:markup_pct`), the seeders (owned brands only). Every price reader goes through `storefrontPriceTerms()` (checkout, checkout preview, catalog, voucher preview, sandbox order, membership plan preview, member-savings badge); the last three read the primary, an owned brand, so they cannot move. Orders snapshot the effective markup, so no stored order changes. Production, read-only on 2026-10-31: the only third-party affiliate (FixFast Demo) has a 25% ceiling and 5% markup, the two owned brands ignore markup, so no live price moves and no cache flush is needed.

**Verified.** Red first: five tests failed for the intended reason (the seam dataset row, a checkout under a null ceiling, the portal `show`, two admin refusals). Then the full backend suite green; four existing fixtures that set a markup with no ceiling got one (two admin create payloads, a catalog and a voucher-preview brand). Admin `tsc` and `eslint` clean. No browser check: the admin change is one hint and one client-side check that mirrors the server's 422.

## 2026-11-01 — ADR-133 PR 3a: the reseller application backend

**What.** The backend half of the public reseller application with KYC (ADR-133 decisions 2–11 and its 2026-11-01 addendum). New tables `partner_applications` (both stages in one row; `active_email` nulled on approve or decline, so a unique index guards "one open application per email"), `partner_application_documents` and `partner_document_views` (short names: MySQL caps identifiers at 64 characters); `platform_settings.partner_applications_open` (default off). `PartnerApplicationService` owns every status change under a row lock: stage 1 (refreshes itself while unconfirmed), email confirmation, Invite directly, send or regenerate the stage-2 link, save and submit stage 2, decline, approve (an active tier, `ResellerProvisioningService::provision(..., inviteAfterCommit: true)`), delete data. `PartnerApplicationDocuments`: kinds per applicant type, one file per kind except two bank statements, files stored before the row and deleted after commit. Public routes under `/api/onboarding` (own limiters, Turnstile on stage 1); admin routes under `/api/partner-applications` (super_admin; delete behind step-up); KYC files only through a 5-minute signed link that re-checks the admin and logs the view. The bilingual notices list recipients by class. `app:prune-partner-applications` (daily, routine): declined after 90 days, approved after 90 days from account closure, unconfirmed after 7 days. The reseller list shows "KYC on file".

**Founder decisions (2026-10-31):** split PR 3 into 3a/3b/3c; "Add Reseller" becomes "Invite directly" (button in 3c); the open switch, off by default; 10 MB per file; recipients by class (checked with a contact in the legal industry). The founder created the R2 token; Claude created the `pekangame-kyc` bucket from the founder's browser (Asia-Pacific, Standard, public access disabled).

**Verified.** Service tests (15), endpoint tests (16), the retention command test, the step-up pin list and `TaskScheduleTest`; the full backend suite; the local dev DB migrated (plain `migrate`); `curl` against the local Herd site: `GET /api/onboarding/status` returns the closed switch and the notice, an empty stage-1 POST answers 422. No UI yet (3b, 3c).

**Gotchas.** `Http::fake()` in `setUp` wins over a later `Http::fake([...])` in the same test, so a "mail fails" test passed without failing anything; the fix is one closure fake reading a per-test response. A missing `use ($inviteAfterCommit)` in a nested closure only showed at runtime.

## 2026-11-01 — ADR-133 PR 3b: the public reseller application forms

**What.** Three pages in the portal app under a `(onboarding)` route group: `/apply` (stage 1: applicant type, details, reselling answers, the bilingual notice with an English/Malay switch, one consent box, Turnstile), `/apply/verify` (confirms the email on a click) and `/onboard/[token]` (stage 2: addresses with "Save progress", one upload row per required document, the stage-2 notice and consent, submit). `proxy.ts` serves them only on an `onboarding.` host (everything else there goes to `/apply`, with `X-Robots-Tag: noindex`) and answers 404 for them on the portal host. Backend: `ONBOARDING_URL` is now an allowed CORS origin. Details in ADR-133's PR 3b addendum.

**Found in the browser check, fixed before the PR.** (1) The portal's CSP blocked the Turnstile script, so the submit button never enabled: Turnstile is now allowed only on the application pages. (2) Confirming the email on page load spent the single-use token twice in development (React's double effect) and could be spent by an email scanner in production: confirmation is now a button.

**Verified.** Throwaway sqlite backend on :8000 (a local fake for Plunk and the Turnstile check, so no real email left the machine), the portal on :3002 with Cloudflare's always-pass test site key, Playwright on `onboarding.localhost:3002`: 18 checks passed (portal host 404s both paths, `/login` on the onboarding host goes to `/apply`, noindex header, notice switches language, stage 1 sent, opening the link alone does not confirm, confirm works and a bad link explains, stage-2 link emailed, save, two uploads, resume after reload, mobile 390 px with no sideways scroll on both pages, submit, a used link explains, no page errors), and the database row read back as `documents_submitted` with two files. Text contrast 4.8 to 16.4 in light and dark. `tsc`, `eslint`, `npm test` (18) and `next build` clean. Servers stopped, the sqlite file and the test KYC files deleted afterwards.

## 2026-11-01 — ADR-133 PR 3c: the admin applications screen; "Add Reseller" becomes "Invite directly"

**What.** `/admin/applications` (sidebar Partners → Applications): status tabs with counts, a list in the `/admin/orders` style, the detail in `OrderDetailCards`-style cards, actions by status (send or resend the stage-2 link, approve with an active tier, decline, delete data behind step-up), documents opened through the 5-minute signed link with their view log, and the "Public applications open" switch. `InviteApplicantModal` is shared by Applications and Resellers. On Resellers, "Add Reseller" is replaced by "Invite directly", and an approved account shows "KYC on file". Backend: `POST /api/resellers` and `ResellerController::store()` removed (grep of the whole repo: only the admin page and four tests used them); the tests now exercise `/api/resellers/internal`, plus one that the old route answers 405.

**Verified.** Throwaway sqlite backend, a local fake for Plunk, a throwaway admin, the admin dev server on `localhost:3000`, Playwright: the list, the switch, the detail, a file opened in a new tab (200, PDF) and its view shown on the detail, approve with a tier (set-password email sent), send stage-2 link (one email), decline (one email), decline of an unconfirmed email (no email, and the dialog says so), delete asking for step-up (the throwaway admin has no MFA, so the prompt is as far as it goes), Resellers without "Add Reseller" and with "KYC on file", Invite directly landing on the new application, no page errors; the list and detail at 390 px fresh-loaded with no sideways scroll, and dark mode by screenshot. The first mobile screenshot showed detail rows squeezed one letter per line (a `shrink-0` label beside an `overflow-wrap: anywhere` value): rows now wrap. The admin's colour-token test caught a `success-700` class with no token: the banner uses `success-surface`/`success-ink`. `/admin/orders` itself scrolls sideways at 390 px; that is older than this work and left as it is. Admin `tsc`, `eslint`, `npm test` (20) and `next build` clean; backend reseller, step-up and route tests green. Servers stopped and test files deleted afterwards.

## 2026-11-01 — Release #633: partner onboarding (ADR-133) live

Requested by the founder. PR #633 (`staging`→`main`) merged as `08afce86`; CI and the `deploy` job green; the box runs release `81738369` at `08afce8`. Migrations applied: `2026_10_31_120000_add_internal_reason_to_resellers_table`, `2026_11_01_100000_create_partner_applications_tables` (batch 55). Ships #619–#632 (PRD §14 lists them).

**Before the release** the founder set `ONBOARDING_URL`, `KYC_DISK=r2_kyc`, `R2_KYC_ACCESS_KEY_ID` and `R2_KYC_SECRET_ACCESS_KEY` in Forge; Claude checked read-only that each key is present (values not printed) and that the token lists the `pekangame-kyc` bucket (0 objects). The founder added `onboarding.pekangame.space` to the `pekangame-reseller` Vercel project (CNAME at Cloudflare, proxy off; DNS and SSL checked) and `NEXT_PUBLIC_TURNSTILE_SITE_KEY` there.

**Checked after the release:** `/api/health` 200 (database, queue, Horizon, scheduler); `app:prune-partner-applications` scheduled daily; `partner_applications_open` false, 0 applications; `config('filesystems.kyc_disk')` = `r2_kyc`; `GET /api/onboarding/status` 200; `https://onboarding.pekangame.space/apply` 200 with `X-Robots-Tag: noindex, nofollow` and Turnstile in `script-src`, a real browser shows "Applications are closed" with no console error; `/login` there redirects to `/apply`; `https://reseller.pekangame.space/apply` 404; the four Vercel deploys succeeded. Not yet proved: a write to the KYC bucket (the first upload proves it).

**Next (founder):** read the notice text on `/apply`, try "Invite directly" on a test address, then turn on "Public applications open" in Admin → Applications.

## 2026-11-01 — Docs audit after release #633 (docs only)

The founder asked whether the docs were current and free of contradictions. Read-only audit first, then fixed with the founder's go-ahead:
- **Contradictions:** PRD item 141 said item 129 "stays on hold" while item 129 said "now due"; ADR-133's Status line still said "Nothing is built".
- **Stale:** ADR-129 to ADR-133 had the Standing "Accepted (design)" though all five are in force (now "In force", with the "later addendum overrides the Status line" sentence where it was missing); ADR-131's banner and index row now say condition 4's documents are collected in the system since ADR-133; ADR-072's decision 6 points to ADR-133; PRD §11 still listed self-serve signup on the roadmap; the old §16 Details item 13 ("ADR-085 candidate… ADR-084 assumes invite-only") removed; items 13 and 146 moved to Recently closed; ADR-133's "Owed by the founder" list marked as settled; the §16 header's re-check date.
- **Founder decision (2026-11-01):** the portal never opens to public signup; the ADR-133 application is the only way in. Item 111's second trigger dropped (ADR-019 2026-11-01 addendum).
- **Gaps:** `foundation-security.md` gained the public application endpoints (§4) and the KYC file handling (§7); the root `AGENTS.md` `reseller/` row names the onboarding host. New §16 item 153 holds opening the application, including the docs-site "Request access" text that still says to email.
- **Housekeeping:** `build-log.md` entries 2026-10-15 → 2026-10-22 (64 entries) moved verbatim to `build-log-archive.md` ("Ninth archive move"); both headers, PRD §15/§16 pointers and the root `AGENTS.md` now say 2026-10-23.


## 2026-11-01 — Item 129 decided (voucher-only); item 153 request-access text

- **Founder decisions:** (1) item 129: no refund to the original payment method for a storefront order, only retry or a voucher. ADR-004 stays in force and gets a 2026-11-01 addendum; the index row and `backend/AGENTS.md` no longer say "being revised"; PRD §6/§7/§16 updated. The detail block for 129 is gone; the open part is one line in group C. (2) Item 153: the founder turned "Public applications open" on; no link in the storefront footer.
- **Read-only prod check:** 14 orders paid since 2026-10-22, all delivered, so no order is owed a refund under the old wording.
- **Still owed by the founder:** the published Refund Policy (Admin → Settings → Footer) still has the refund-to-original-method wording until the founder saves a voucher-only text. Nothing was written to production.
- **`partners@pekangame.space` does not exist** (founder). The docs-site "Request access" now links to `onboarding.pekangame.space/apply` and the intro heading reads "Access is by application"; the `/apply` closed-state message no longer names the address. The PDPA contact in the two notices (`services.onboarding.privacy_contact`) is now `business@pekangame.com` (founder); `ONBOARDING_PRIVACY_CONTACT` is not set on Forge (checked, count only), so the code default is what the notices print. Takes effect at the next release.
- **Checks:** docs-site `npm run build` passes (18 pages).

## 2026-11-01 — `/price-list` redesign designed (ADR-091 addendum, docs only)

- **Complaint:** prospective resellers find the price list hard to read (one flat table, search only). Prod data: 674 rows, 36 game entries, 3 tiers; Mobile Legends alone about 360 rows.
- **Findings from the trace:** no usable grouping exists (`category` is a genre, `reseller_code` has no convention, names are inconsistent); and the root layout plus `sitemap.ts` fetch and Zod-parse the whole price list on every root-layout render only to know "is the page on", so the redesign adds a `?summary=1` tiers-only response for them.
- **Decided with the founder (mockup reviewed locally, desktop and 390px):** browse by game then region; two nullable display columns `games.family` and `games.region_label` instead of a `game_families` table (promote only when a per-title SEO page or per-title reports need one); swipeable single rows on phones; instant search from 2 characters with `useDeferredValue`; the WhatsApp button becomes the application link (`apply_url` from `services.onboarding.url`).
- **Not built.** ADR-091's 2026-11-01 addendum is Proposed; PRD §16 item 154 is the line. Grill and the build come next (backend migration and service, admin modal, storefront, tests).
- **Corrections to what was said in chat:** Mobile Legends has 10 regions and about 360 rows (not 11 and 340).

## 2026-11-01 — `/price-list` grouped by game and region built (ADR-091 addendum, PRD item 154)

- **Built on `feature/price-list-game-region-picker`** (merged to `staging` by its PR, not released): migration `2026_11_01_130000` (`games.family`, `games.region_label`, both nullable), `UpdateGameRequest` grouping rules, `PublicResellerPriceListService` (grouped `groups[]`, `summary()`, `apply_url` from `services.onboarding.url`), `?summary=1` on the controller; storefront `ResellerPriceListBrowser` + `PriceRows`, `getResellerPriceListEnabled()` for the root layout and sitemap, the apply button; admin "Price List Family" field in `EditGameModal`.
- **Founder decisions (grill):** fill Family and Region by hand (no backfill migration), the short names in PRD item 154, order by Reorder Games, accept the short deploy window.
- **Checks:** backend `php artisan test` 3,385 passed; Pint clean on the changed files; storefront `tsc`, `eslint`, `build`; admin `tsc`, `lint`, `build`; a browser check on a throwaway sqlite backend with the 674 real prod rows (desktop and 390px, admin modal); servers stopped and the sqlite deleted afterwards. The local dev DB was migrated with plain `php artisan migrate`.
- **Gotcha:** `git rm` of the only file in `components/price-list/` removed the folder, so the next heredoc writes there failed until `mkdir -p`.
- **Owed by the founder after the release:** fill Family and Region for the 24 games (item 154, group C).

## 2026-11-01 — Money audit: every cost was at the market mid-rate (read-only)

- **Question (founder):** is the cost the admin shows (RM0.93 for 14 Diamonds) what a unit really costs, given Digiflazz is funded by MYR→IDR transfers through Maybank or Wise?
- **Answer, from prod (SELECT and read-only tinker only, three forks plus own checks):** no. Price Sync converted at open.er-api's mid-rate (0.000224) with no buffer, and so did the per-order real cost (ADR-111). The deposit pool held Rp18,081,822 at 430,169 sen: +6.2% over market, about +6.8% with the bank fees. Maybank's two October transfers carried a ~6.25% spread inside the rate (RM351.35 over market on RM5,154); Wise's three September ones had ~0% spread and fixed fees (RM28.16 on RM715).
- **What it cost:** at the true cost every reseller tier (1–5%) and membership "Tier 2" (70% discount) sold below cost on all 1,037 active Digiflazz packages; standard and affiliate 15% made ~7%, not 13%. All-time owner profit shown RM212.03 against ~RM106.73 true (four reseller orders, #19, #46, #49, #50, lost money). October: Reports RM204.98, Monthly Summary operating profit RM106.93; after expenses September −RM187.66 and October −RM150.96. RM251.36 of premium sat in the unsold balance. The Monthly Accounting Summary was already exact; every other profit screen read the market rate.
- **Clean:** no duplicate drawdown, the polled Digiflazz balance equals the ledger to the rupiah, CHIP fees are a pass-through, failed reseller orders were refunded with no drawdown, September's close used funded cost.
- **Not related:** order PG-JUVJ8EVJOAKG failed twice on seller `HOK_GB_23_PG2` with rc 54 "Nomor Tujuan Salah" and succeeded on `HOK_GB_23_PG1`; we never send a price to Digiflazz, so a supplier price rise cannot fail an order.
- **Decided:** ADR-134 (grilled Q1–Q13, stress-tested by three forks), PRD §16 item 155.

## 2026-11-01 — Two date-dependent tests broke on staging (#640)

- `PartialComboOrders::settledPartialComboOrder()` settled at `now()`, and settling sets `recognised_at`, so three assertions on October failed once the clock reached November; `ResellerClosureTest`'s forward jump to the 11th expired the setUp step-up. Both hold the clock now. Every PR's `backend-tests` was red until #640 merged (it was the cause of #639's first red run).

## 2026-11-01 — Landed cost rate built (ADR-134, PRD item 155)

- **Built on `fix/landed-cost-rate`** (stacked on the ADR PR #639), not released:
  - `LandedCostRate` (the pool's average after the last deposit or adjustment, cached on a fingerprint of the transfers and adjustments) at the two seams: Price Sync's catalog cost (`ceil`) and delivery's real cost (half-up, the pool's own rounding).
  - `MovingAveragePool`: a pooled transfer's fee is unit cost from 2026-10-01; every row is costed at the last-event average; every prepaid supplier is pooled whatever its currency, pay-after stays at face value. `bankTransferFees()` keeps only fees not in a pool; the prepaid top-up line carries the pooled fee.
  - An unfunded foreign supplier's packages go off under `deactivated_reason = 'unfunded'` (ADR-046's status service) and come back on the first funded run, only those the supplier still lists.
  - `orders.cost_rate` (`market` default, `landed` from `OrderFactory` and from a delivery that re-costs an older order); in the order detail, Transaction Register, Orders export, the Report Assistant view and prompt, and a market-rate note under owner profit.
  - Transfer spread guard (Middleware band, default −2% to +12%), the stored `effective_rate` with the pooled fee (migration restates the October rows), supplier currency lock, Dashboard supplier balance at the landed rate, Price Sync Center shows landed, market and spread.
- **Changed during the build (founder):** Q10's "hidden and refused at checkout" became the deactivate-with-reason mechanism, because `is_active` is read in about fifteen places and there is no shared "sellable" check. The column is `cost_rate`, not `cost_basis`: `Order::costBasis()` (ADR-111, real / mixed / estimated) already exists and the order API already returns `cost_basis`.
- **Read-only prod replay of the new pool:** rate 0.00023913 MYR/IDR (+6.75% over 0.000224), October drawdown cost +705 sen (the 16 drawdowns of 30–31 October; the stress test predicted the same), 14 Diamonds 93 → 99 sen.
- **Checks:** backend `php artisan test` 3,405 passed (new: `LandedCostRateTest`, `TransferSpreadGuardTest`, the unfunded job test, a `FinancialScenarioTest` that fails on the old code with the October fee counted twice); admin `tsc`, `lint`, `npm test`, `build`; local dev DB migrated (`php artisan migrate`).
- **Gotcha:** running Pint on whole directories reformatted ~47 untouched files; reverted before push. Run it on the changed files only.
- **Owed after release:** close October only after this is live (ADR-134); set Digiflazz's `drift_threshold` (Rp50,000) in Middleware; tell resellers about the ~6.8% price rise.

## 2026-11-01 — Release #642: landed cost rate live, audited on prod (read-only)

- **Release** (`staging`→`main`, founder's request): merged `d327e932`; CI and `deploy` green; box release `81771668` at `d327e93`; migrations `2026_11_01_160000`/`160100`/`160200` `[56] Ran`; `/up` 200.
- **Production data written by the release (migration `restate_pooled_transfer_effective_rate`, display column only):** transfer #6 `effective_rate` 0.00023900 → 0.00024634, #7 0.00023800 → 0.00023870 (fee now in the rate). Idempotent; no money column touched.
- **Before → after (read-only, same queries):** 14 Diamonds cost 93 → 99 sen, selling 107 → 114 (storefront API shows RM1.14, member RM1.03); 23 Tokens 101 → 108; total cost of the 1,111 active Digiflazz packages 14,304,784 → 15,276,971 sen (+6.80%). October operating profit 10,693 → 12,922 sen, bank-fee line 2,934 → 0, prepaid top-up 547,625 → 550,559 (fees included), COGS 174,511 → 175,216. September 464 sen live = close #3's 464.
- **First Price Sync after deploy** (run 3081, 05:00 UTC): `fx_rates_used` = deposit pool 0.00023913371 MYR/IDR, market 0.000224, spread +6.76%; 1,399 prices changed, 0 anomalies, 0 failed suppliers, no `unfunded` deactivation.
- **Audit:** every active Digiflazz package's cost equals `ceil(raw × landed)`, 0 below the landed cost; 68 active combos equal Σ(quantity × component cost); minimum margin on sale against landed cost: standard 12.2%, member Tier 1 6.25%, Tier 2 3.75%, reseller A 4.26%, S 2.7%, SS 2.17%, SSS 1.37%, Supreme 0% (the smallest packages, where `round(cost × 1.01)` equals the cost: no loss, no profit). Cash position October: supplier prepaid 432,398 sen (as replayed before the release). Dashboard Digiflazz balance RM4,322.90 at the landed rate. Reports summary: 56 market-rate orders in range, so the note shows. No order in flight at the deploy; all 72 orders are `cost_rate = market` until the first new one.
- **Gotcha:** a combo check summing component costs without the pivot quantity reported 26 mismatches; with quantities, 0.
- **Owed by the founder:** CHIP settlements 25–31 October, then close October; Digiflazz `drift_threshold` 50000; tell resellers; item 154's 24 games.

## 2026-11-01 — Money sweep after the founder spotted the CHIP fee estimate

- **Trigger (founder):** the October close showed "CHIP paid, not settled yet … less RM1 fee each" although the settlement file was uploaded. It was right that 13 payments of 31 Oct were still in transit (CHIP paid them out on 1 Nov), but the flat RM1 fee was wrong for DuitNow (~1%): RM13 deducted against RM8.58 the customers paid (CHIP took RM8.56).
- **Sweep (two read-only forks):** (1) every estimate and fallback in the money paths; (2) every Monthly Summary line and the October position recomputed from raw tables. Result: every Sep and Oct Monthly Summary line matched to the sen; the October cash-flow walk closes to 2 sen once September's documented cutover gap is counted.
- **Fixed (branch `fix/close-actual-chip-fees`):**
  - The CHIP-in-transit asset nets each payment's customer-paid fee (no RM1 estimate); a voucher-covered order is excluded explicitly (ADR-083 second 2026-11-01 addendum).
  - The profit bridge's "manual profit corrections" line, which double-counted order 15's −10 sen correction (10 sen "unexplained" for September and all time since 2026-09-20; found in the first audit and not fixed then), is replaced by "ledger profit vs order figures" (same addendum).
  - DuitNow fee grossed up to what CHIP takes of the total (we absorbed ~1 sen on many orders; 2 sen in October) — ADR-024 2026-11-01 addendum; `percentage_rate` must be below 100.
  - `OrderDeliveryLeg` docblock no longer names the market-rate converter.
- **Left as designed:** a settlement batch's gain/loss sits in the month of its last day; a combo leg with no drawdown falls back to today's catalog cost (item 122, none in production).
- **Tests:** `ChipUnsettledFeeTest` (fails on the old code: RM3 estimate), a `FinancialScenarioTest` for a ledger-fixing correction, DuitNow gross-up cases from the seven production settlements; full backend suite 3,409 passed; admin `tsc`, `lint`, `npm test`.
- **Expected at the October close after release:** the bank accounts together on 31 Oct ≈ RM24,529.44 (cash-flow walk); the equation then shows ≈ −RM10.98, which is September's already-noted cutover gap (RM10.94, close #3) plus the 2 sen November gain/loss.
- **Founder actions checked read-only on prod (2026-11-01, before merge):** CHIP settlement file uploaded (settlement #7, 13:25 MYT, matched; covers the payments paid out 31 Oct and 1 Nov); Digiflazz `drift_threshold` = 50000; Family/Region set on 21 active games (item 154 closed). October is not closed yet: close it only after this PR is released.

## 2026-11-01 — Release #645 (month close CHIP fee)

- **Released** `staging`→`main` at the founder's request: PR #645, merge `00d18b3a`, box release `81777464`, no migration. Ships #643 (docs) and #644 (month close CHIP fee, profit bridge, DuitNow gross-up). CI and `deploy` green; `/api/health` answered 503 for a few seconds while Horizon restarted, then all true.
- **Next (founder):** close October on the Monthly Summary; tell resellers about the ~6.8% price rise.

## 2026-11-01 — WhatsApp privacy-id customers answered and followed (ADR-116 addendum); admin orders page overflow; portal noindex

- **Trigger (founder):** the customer of PG-WSEZAO6R4TGU sent the order number to the CS number and got nothing; after the admin Resend, no receipt either. Read-only: the sender was `234…@lid` with no resolvable phone, and `CustomerWhatsAppInboundService` dropped such messages with an `info` log (below prod's `warning`). OpenWA DB: 4 distinct `@lid` senders since 2026-09-25, 2 on 2026-11-01. OpenWA v0.27.0 does not fix it: WhatsApp hides a stranger's number, and no endpoint does phone → `@lid`.
- **Built (ADR-116 2026-11-01 addendum, grilled in two rounds and stress-tested):** the sender is its phone or its `@lid` chat; replies go to that chat; a chat that sends an order number follows the order (read from its `status_card` rows, no new table); one place, `CustomerNotificationService::receiptRecipients()`, picks the receipt and joki-progress recipients (the checkout number alone when it ever wrote to us, so its STOP holds; else every follower that has not said STOP); per-recipient dedupe keys, the checkout number keeps the old key; `whatsapp_contacts.phone` nullable plus a unique `chat_id`, both stored on one row when a message carries both, STOP on any matching row wins; vouchers unchanged. New `WhatsAppRecipient` value (phone or chat). Admin order page: "WhatsApp chat (number hidden)" with the id.
- **Admin `/admin/orders` sideways scroll:** measured in a browser (throwaway sqlite backend): at 390 px the page was 700 px wide and at 1280 px 1312 px (Export Excel cut off). Two causes: the nine status tabs did not scroll in their own row, and the shell's `flex-1` content column had no `min-w-0`, so one wide child widened the whole page. Fixed both (`PanelShell` gets `min-w-0`); after: 390 → 390, 1280 → 1280; Dashboard, Vouchers, Withdrawals, Games unchanged at 1280.
- **Portal `noindex`:** the portal login had no robots tag (only the onboarding host did), so it could be indexed once discovered (certificate-transparency logs list subdomains). The root layout now says `noindex, nofollow`; item 153 closed without a login link (founder).
- **Tests:** 10 new in `CustomerWhatsAppOptInTest` (red first: 7 failed on the old code) and one in `Adr126JokiProgressTest`; backend 3,419 passed; admin and portal `tsc`, `lint`, `npm test`; portal build shows the robots meta on `/login`.
- **Not done:** no backfill for PG-WSEZAO6R4TGU (the founder answers by hand); OpenWA upgrade to v0.27.0 is a separate maintenance step; item 156 waits for an upstream phone → `@lid` lookup.

## 2026-11-01 — OpenWA upgraded v0.25.0 → v0.27.0

- **Why:** security fixes in v0.26 and v0.27 (unauthenticated Socket.IO root refused, session-restricted keys can no longer reach another session's login or chats, template render memory bound, Node 22.23.4 and npm CVEs) and Baileys send fixes (`503` for a closed connection). Upgrade notes read in full: nothing touches our two calls (sends carry a full chat id; `METRICS_TOKEN` is unset).
- **Phase A, no downtime:** `git clone --branch v0.27.0 --depth 1` into `~/OpenWA-v0.27.0`, `npm ci`, dashboard `npm ci`, `npm run build:all` under `nice`; exit 0. The Baileys "not on WhatsApp" `400` still says "…is not registered", which `OpenWaClient` matches. Node 22.23.4 meets `>=22.19`.
- **Phase B (founder stopped and started the daemon in Forge):** backup `~/openwa-backups/openwa-v0.25.0-20261101-1051.tar.gz` (29 MB, `data/` + `.env`), `data/` and `.env` copied in, folders swapped (`~/OpenWA` is 0.27.0, `~/OpenWA-v0.25.0` kept for rollback). Supervisor command unchanged (`/usr/bin/node dist/main`).
- **After (verified):** `reseller-bot` ready 10:53:00 UTC, `customer-support` 10:53:07; Laravel `/api/health/whatsapp` both `ready`; 3 OpenWA migrations ran (`AddLidMappingReportSource`, `RepairSqliteMessageFts`, `AddChatStateDeletedAt`); 0 error lines; every webhook `dispatched`, both sessions included; `.baki` in the test group answered in 4 s. `AUTO_START_SESSIONS`, `SEND_PACING_*`, `BAILEYS_MARK_ONLINE_ON_CONNECT=false`, `MESSAGE_RETENTION_DAYS=90` carried over. OpenWA's own `scripts/backup.sh` (the nightly R2 source) run once into a temp dir: one 29 MB archive, then deleted. Downtime about 3 minutes.
- **Warnings at boot:** `API_KEY_PEPPER` unset (new in this version; setting it needs both API keys re-issued, item 157); CORS, Docker and the webhook drain warning as before.
- **Number Checker (new dashboard page) does not solve `@lid`:** it calls Baileys `onWhatsApp()`, which answers the phone id only; phone → `@lid` stays item 156.
- **Rollback:** stop, swap the folder names back, start; the v0.25.0 `data/` is untouched.

## 2026-11-01 — Docs audit against production

- **Fixed in the docs:** PRD §15 said #614 and #615 were "merged to `staging`, not released" (both live via #618); §14 said storefront orders were still mostly the founder's tests and OhahaStore had one order (read-only: 21 paid storefront orders since 2026-10-22 from 15 emails; OhahaStore 18 paid); item 129 described a "7 working days" promise the live page no longer has (it still offers a refund, re-read on `pekangame.com/refund-policy`); §10 OpenWA row now v0.27.0 and the `@lid` reply; §15 Internal Accounting gained the #644 fix; `backend/AGENTS.md` names `WhatsAppRecipient` and `receiptRecipients()`.
- **Checked and still true:** every ADR file has its index row, last-addendum dates and Standing words match the banners (scripted, 134 of 134); the ruleset requires the eight checks AGENTS.md lists; Digiflazz is the only funded supplier (Gamevion balance 0, no delivery); Sentry unset (item 11); October not closed (item 155); FixFast Demo still active (item 141(b)); applications open with none received; joki switched off (item 114).

## 2026-11-01 — Release #649 (WhatsApp `@lid` follow, admin orders overflow, portal noindex)

- **Released** `staging`→`main` at the founder's request; the founder merged #649 (the auto-mode classifier blocked Claude's merge as a production deploy). Merge `4c7d85ae`, box release `81783529`, CI and `deploy` green, Vercel ×4 `success`.
- **Verified read-only:** migration `[57] Ran`; MySQL `whatsapp_contacts` has `phone` nullable and the `chat_id` unique index, 3 contacts kept; `/api/health` all true; `/api/health/whatsapp` both `ready`; 0 Laravel errors since the deploy; `<meta name="robots" content="noindex, nofollow">` on the portal login.
- **Still to see:** the first real `@lid` customer who sends an order number (status card into the `@lid` chat, then the receipt on Delivered). PG-WSEZAO6R4TGU was answered by hand; no backfill.

## 2026-11-01 — Group D backlog audit against code and production (docs only)

- **Why.** The founder suspected some group D items were already done but not recorded. Checked every item against `origin/main` code and read-only against production.
- **Nothing found built and unrecorded.** The checks that came back:
  - Items 83, 85, 104, 4, 59 and 122 had not reached their triggers. Production 2026-11-01: 0 vouchers without an order (the 5 vouchers are order compensation, no ledger debit); 1 active member; 3 wallet top-ups; load 1.1 on 2 cores with about 1.8 GB available, engine still Baileys; October `cogs_fallback_order_count` 0.
  - Items 139, 151, 152, 156, 128, 105 and 81 have no code written. Item 111's "Account locked" trigger was not checked.
- **Corrected.** Item 108 (the checker miss on `ohahastore.com` on 2026-10-31 was the trigger, fixed by hand) and item 122 (a leg already has `real_cost_price_sen`, null on 5 of 8 delivered legs).
- **Closed by the founder.** Item 55 (every order is sales revenue) and item 74 (the tier fee stays RM0): ADR-083 third 2026-11-01 addendum. Item 114 (Joki): gates checked on prod, rank icons and rates are set, the Grading Express price is the founder's own choice; only the Refund Policy text (item 129) is left, then the global switch (`joki_settings.is_enabled`, 0).
- **Refund Policy, read on the live page.** The refund choice line was already removed, but "entitled to your money back" and "Refunds are only paid to the original payment method" remain, with a dangling "or". Replacement text was given to the founder in the session; saving it closes item 129.
- **Process.** For the code read, Claude detached the checkout to `origin/main` and went back to `staging` at once; no file changed. Prod reads were SELECTs and one read-only service call (`FundedCostOfSales::forPeriod`), no writes.
- **Later the same day (founder).** The Refund Policy text was saved and checked live, so item 129 is closed. Item 141 (CHIP activation conditions) is closed too: OhahaStore is approved and can take a real purchase, and FixFast Demo stays active on purpose. Joki has no gate left; the founder opens the global switch when they choose.
- **Group E sorted (founder agreed).** 24 (pending top-up resume/cancel) and 94 (best-selling products) moved to B; 57 (Marketing Campaigns) and 150 (membership pitch) to D with their triggers; 117 (languages and currencies) parked; 14 (assistant chat history) and 25 (portal needs-attention aggregate) closed as not needed. Nothing in E was built (code checked).


## 2026-11-02 — Delivery Logs "Cost vs checkout" (ADR-106 addendum, PR #653)

- **Why.** The founder read PG-PJCFSQQQHKB8: the resend's supplier charge was IDR 8,349 against IDR 8,226 quoted on the failed first attempt, yet Price Diff showed RM 0.00. Price Diff (`price_diff_sen`) compares catalog costs, designed before ADR-111 captured real cost. The founder's first suspicion, that PG-WSEZAO6R4TGU (sold at RM3.27 while package 811 was RM3.36) was mispriced, was a resend from package 810 to 811. The selling price stays frozen by design (ADR-017).
- **Money checked first, read-only on prod.** All 57 delivered orders: ledger platform profit = `orders.platform_profit`. All 41 non-combo orders with a real cost: `selling − real cost − affiliate` holds. The 13 without a real cost were delivered 15–19 Sep, before ADR-111 (22 Sep). Nothing in reports, ledger or accounting reads `price_diff_sen`. The same session confirmed the DuitNow fee was 3 sen short in total on 3 orders (PG-LOLDO4JJLUIS, PG-QUTSHIVWOKGH, PG-L4RCBELJ9RE3), all before the gross-up released in #649. Since then the charged fee equals CHIP's on every order.
- **Shipped (on `staging` once #653 merges).** `Order::costVsCheckout()` (derived per delivered unit, keyed to the delivering attempt), the `cost_vs_checkout` field on admin and sandbox detail, the Delivery Logs column renamed and moved to it, and combo leg histories showing it. The resend modal now labels its figures as catalog estimates.
- **Stress test changed the design.** The grill first chose a `real_cost_price_sen` column on every attempt row. Reading the six real-cost write sites showed: a manual delivery has two success rows; a manual Failed → Delivered correction has no pending row to stamp; `ManualDeliveryService::confirmDelivered()` writes `manual_confirm` outside the finalize transaction. A unit is delivered once and its cost already has one home, so the figure is derived instead. The modal shows no sync time either: `last_synced_at` can be newer than a package cost held for review.
- **Verified.** Backend 3426 tests; admin `tsc`, lint, 22 tests, build. A local browser check on a throwaway order (removed afterwards; dev DB back to its baseline) showed +RM 0.07 on the delivering resend and "—" elsewhere, plus the modal labels. The same logic, run read-only on prod, gives PG-WSEZAO6R4TGU +7 (catalog said +8) and PG-PJCFSQQQHKB8 +3 (catalog said 0).
- **Known gap.** PG-KWKBUHNDMKQT and PG-YEQQQIJR0MYM (17 Sep, before ADR-106's `initial` rows) now show "—" instead of their catalog diff.

## 2026-11-02 — ADR-135 PR 1: one paid seam per attempt type (refactor, no behaviour change)

**What.** `ResellerWalletService::applyPaid()` and `MembershipSubscriptionService::applyPaid()` are now the one place a gateway's "paid" answer about a top-up or a membership attempt is applied, with the amount check inside and a shared `AttemptPaymentOutcome` (Applied, AppliedToClosedAccount, AlreadyProcessed, AmountMismatch). The CHIP webhook's two attempt branches and both reconcile commands call it; each used to keep its own copy of the amount check (ADR-135 decision 11, mirroring item 63's `OrderPaymentOutcomeService`). `completeTopup()` now returns whether it credited. The webhook's replies (200 ok / already processed, 409 amount mismatch) are unchanged.

**Why.** ADR-135's Re-check with CHIP (PR 4) needs the same seam; without it, it would have been a third copy.

**Checked.** New `AttemptApplyPaidTest` (6 tests, red first); full suite 3,432 passing; the top-up and membership webhook-vs-reconcile concurrency proofs pass on MySQL. Noted in passing: `completePaidAttempt()` takes no lock, which is safe because `recordFeePaid()` deduplicates on `idempotency_key` (its concurrency test covers it).

## 2026-11-02 — ADR-135 PR 2: resume or cancel a pending top-up from the portal

**What.** Built ADR-135 decisions 1, 2, 5–7, 8b, 9 and 10:
- **Gateway.** `PaymentGateway::cancelPayment()` (`ChipGateway`: `POST /purchases/{id}/cancel/`). `PaymentResponse::endedByExpiry()`. `FakePaymentGateway` remembers cancelled ids. All 27 test doubles implement the new method.
- **One ended seam.** `ResellerWalletService::markEnded()` is now the only writer that ends a pending top-up unpaid; its five old writers route through it. `WalletTopupEndCause` names both the status (expiry → `expired`, anything else → `failed`) and the WhatsApp group message.
  - A `.topupbaki` group is told once when its top-up fails, expires or is cancelled from the portal.
  - The guard is a new `reseller_bot_wallet_topups.ended_notified_at` column (additive migration).
- **Portal.**
  - `ResellerWalletTopupService::currentPending()` is a database read, exposed as `pending_topup` on `GET /reseller-portal/wallet`.
  - `resume()` verifies the link with CHIP first.
  - `cancel()` asks CHIP to cancel, then acts only on CHIP's re-read answer, and never credits.
  - New routes `POST /wallet/topup/{reference}/resume` and `/cancel`. `/wallet/topup` itself was unthrottled until now; all three got their own rate limits.
  - The Balance page shows a "Payment waiting" card (Pay now, or Cancel with a confirm step) in place of the Add Balance form.
- **Reconcile.** Also re-asks CHIP about top-ups that ended in the last 24 hours (a `ponytail:` note gives the volume ceiling).

**Gotchas.**
- Next 16 refuses a second `next dev` in the same folder. The browser check used `next build` + `next start` on `:3012`, with the throwaway API on `:8010` and `RESELLER_PORTAL_URL` set for CORS. The founder's dev servers stayed up.
- `npx prettier` reformats whole files in this repo (it does not use Prettier). Reverted, and the edits were re-applied by hand.
- A test that logs a second reseller in needs `auth()->forgetGuards()`, because Sanctum otherwise keeps the first user.

**Checked.**
- Backend: full suite passing; new tests in `WalletTopupEndedTest`, `WalletTopupCancelResumeTest`, `WalletControllerTest` and `ReconcilePendingWalletTopupsCommandTest`, plus the ChipGateway cancel and expiry tests.
- Portal: `tsc`, lint, tests and build all pass.
- Browser (throwaway backend, `APP_ENV=e2e`): start a top-up → card → Pay now → Cancel → confirm → form back, in light, dark and 390 px, with no sideways scroll.

## 2026-11-02 — ADR-135 PR 3: `.batalbaki` and the bot replies a group-only reseller needs

**What.** Built ADR-135 decisions 4, 8 and 8a, so a reseller who uses only the WhatsApp group can see and end a pending top-up the way the portal can:
- **`.batalbaki`** (no argument; a reseller has at most one pending top-up) runs the portal's own `cancel()`, so CHIP's re-read answer decides. One reply per outcome: cancelled, already paid, being processed by the bank, nothing pending, still being set up (with its end time), or already ended. It gets its own 5-per-minute limit and is allowed for a deactivated reseller. Failures log `topup_cancel_none` and `topup_cancel_in_flight`.
- **`.topupbaki` replies** now say when the link ends ("Link tamat pada HH:MM", MYT) and how to cancel. Sent again while a top-up is pending, the reply names that top-up's end time and offers `.batalbaki`. A pending top-up with no link gets "sedang disediakan … tamat sendiri pada HH:MM" (it used to get a misleading "Gagal memulakan").
- **`.baki`** adds one line for a pending top-up (a database read, like the portal card). The command list names `.batalbaki`.
- **`PendingWalletTopupAlreadyExistsException`** carries the attempt the wallet lock found, so the bot's second query is gone. Its message, which the portal shows as a toast, was an internal "Reseller #N already has a pending top-up attempt (#M)"; it is now plain English for the reseller.
- The portal's "One payment at a time" note and the docs-site wallet page mention `.batalbaki`.

**Checked.** Backend full suite passing, with the new `ResellerBotBatalBakiTest` (every reply text pinned at a fixed MYT time) and parser and exception tests; portal `tsc` and lint pass.

## 2026-11-02 — ADR-135 PR 4: settled but not paid here, and Re-check with CHIP

**What.** Built ADR-135 decisions 12 and 13. This closes PRD §16 item 24.
- `SettlementReconciliationService::settledButUnpaid()` lists a settlement's transactions that CHIP paid out but whose order, membership or top-up here is not paid. It is a join per record type, worked out on each view of `GET /accounting/settlements/{id}` (`settled_but_unpaid`). Nothing is stored and the settlement's `status` is unchanged.
- `POST /accounting/settlements/transactions/{id}/recheck` (super_admin, `mfa.stepup`, added to the pinned step-up list) runs the new `SettlementRecheckService`. It asks CHIP and, on Paid, hands the answer to the record's own paid seam: `OrderPaymentOutcomeService::applyPaid()`, or the membership or top-up `applyPaid()` from PR 1. It answers with one outcome and a sentence: applied; sent to Needs Review (a failed order); credited to a closed reseller ("refund the payer"); already paid; amount mismatch; not paid at CHIP; or CHIP unreachable (502). An unmatched row is a 422.
- Admin, on the CHIP Settlements detail screen: a red banner explains the rows and says not to credit by hand. Each such row gets a "settled but not paid here" tag and a Re-check with CHIP button. The result shows as a toast (success, or sticky when nothing was recorded), then the screen re-reads.
- ADR-135 got a build addendum: Re-check calls the order's `applyPaid()` directly rather than `reconcileOrder()`, so it can report the order's outcome.

**Gotchas.**
- In Playwright, `text=Re-check with CHIP` matched the banner sentence before the button, and `text=Confirm` matched the step-up dialog title. Use `button:has-text(...)`.
- TOTP refuses a code already used in the same 30-second window, so a scripted second login needs a 31-second wait.
- The admin was checked on `localhost:3013` against a throwaway API on `:8010` (`ADMIN_URL` and `SANCTUM_STATEFUL_DOMAINS` set to `:3013`, so the cookie session works). The founder's dev servers on `:3000` and `:8000` stayed up. The admin was rebuilt with its default env afterwards.

**Checked.**
- Backend: full suite passes (3490 tests), with the new `SettlementRecheckTest` covering each outcome, the step-up gate and the role gate.
- Admin: `tsc`, lint and tests (22) pass.
- Browser (throwaway backend, `APP_ENV=e2e`, an MFA admin):
  - The tagged row and banner render.
  - Re-check → 403 `mfa_step_up_required` → step-up dialog → retried → 200 with a sticky toast. The e2e fake gateway reports no amount, so the outcome is `amount_mismatch`.
  - Once the top-up was paid by its seam, the banner and button were gone and the row showed a normal green tag (dark mode).

## 2026-11-02 — Release #660 (`staging` → `main`)

**What.** Released at the founder's request: #650–#659 (#653 Delivery Logs "Cost vs checkout"; ADR-135 in full, #654–#658; docs). Merge `a88a0709`; CI all green including `deploy`; box release `81876889` at `a88a070`.

**Checked (read-only, prod).** Migration `2026_11_02_120000_add_ended_notified_at_to_reseller_bot_wallet_topups_table` Ran; `/api/health` 200; `/api/health/whatsapp` both sessions `ready`.

**Also checked (read-only) for item 156.** OpenWA on the box is v0.27.0; the phone → `@lid` lookup the founder asked for upstream (rmyndharis/OpenWA#1969) shipped in v0.27.1 (`GET /api/sessions/{sessionId}/contacts/{number}/lid`, needs an OPERATOR key not restricted to chats). The Customer Support key is `operator` with no `allowedChats`, so no key change is needed.

## 2026-11-02 — OpenWA v0.27.1, and releases plus shared data (ADR-114 addendum)

**What.** Upgraded OpenWA 0.27.0 → 0.27.1 (adds the phone → `@lid` lookup asked for in rmyndharis/OpenWA#1969, PRD §16 item 156) and moved it to the layout in ADR-114's 2026-11-02 addendum: `~/openwa-deploy/releases/{v0.27.0,v0.27.1}`, state in `~/openwa-deploy/shared/{data,.env}` symlinked into each, `~/OpenWA` → `releases/v0.27.1`.

**Steps.** Claude built v0.27.1 in `releases/` (with `nice`, ~10 min, log `~/openwa-v0.27.1-build.log`). The founder stopped the daemon in Forge. The swap was run by the founder (auto mode blocks Claude's remote shell writes): tar of `data` + `.env` to `~/openwa-backups/openwa-v0.27.0-20261102.tar.gz` (29 MB), move both to `shared/`, move the old install to `releases/v0.27.0`, symlinks. The founder started the daemon; down ~15:20–15:29 UTC.

**Checked (read-only).** Process cwd `/home/forge/openwa-deploy/releases/v0.27.1`; both sessions `ready` at 15:29 UTC; `/api/health/whatsapp` ok; `GET /contacts/6582751992/lid` with the CS key → 200 with an `@lid`. `OpenWaClient::sendNow()` already retries a non-2xx, so 0.27.1's `409` on a send cut off by a reconnect (not sent) retries like the old `503`. `backup.sh` follows symlinks; first nightly OpenWA backup after the move (03:37 MYT) still to be seen.

**Seen, not changed.** `MESSAGE_RETENTION_DAYS=90` carried over in `shared/.env` (set 2026-10-25, item 96): 1,703 messages, the oldest 2026-09-25, so the first prune is around 2026-12-24; `openwa.sqlite` 43 MB.

## 2026-11-03 — Item 156: tie a privacy-id chat to the checkout phone (ADR-116 addendum)

**What.** Built the ADR-116 2026-11-03 addendum (founder's design: the panel's checkout phone is the truth).
- `CustomerWhatsAppInboundService`: an `@lid` sender with no phone that sends an in-scope order number queues `VerifyWhatsAppLidJob` (own `whatsapp-lookup` queue served by `supervisor-default` after `default`, unique per chat for 10 min, 20 lookups/min limiter, 3 failures or 1 hour) unless that phone and chat are already tied. The job calls `verifyLid()`: `OpenWaClient::customerSupport()->lidForPhone()` (new; `GET /contacts/{number}/lid`, throws on a non-2xx so the job retries) and ties only on an exact match.
- The tie runs in one transaction with the matching rows locked: the phone's row is kept, other rows for the phone or chat are deleted first (both columns unique), STOP on either wins, the earlier opt-in is kept; a phone tied to an older chat takes the new one. New rows get `opt_in_source = lid_match`. No migration.
- `CustomerNotificationService::inScope()` is public (the check reuses it). Admin order detail: each notification row carries `verified_phone`; the card shows "WhatsApp chat · verified as <phone>".

**Why the lane.** A read-only prod health check the same day (Pulse/Horizon, 7 days) found `default` has one worker that also sends reseller bot replies (up to 6.5 s each); lookups there would queue ahead of resellers during a burst.

**Checked.** `CustomerWhatsAppLidTieTest` (12: lane, one job per chat, rate limit, dispatch and its skips, match and a later order's receipt to the phone, mismatch and `lid: null`, `503` throws, merge with STOP, an older lid replaced, idempotent, admin field), red first; full backend suite 3,502 passing; admin `tsc` and eslint clean. The lookup itself was proved on prod 2026-11-02 (CS key, 200 with an `@lid`). The admin label was not seen in a browser (one string change).

## 2026-11-03 — Prod health check (read-only) and the storefront backstop TTL (ADR-077 addendum)

**Health check (Pulse and Horizon, 7 days; box metrics live).** All 9 Horizon supervisors running, no pending jobs, wait 0; 0 failed jobs in 14 days (18 old rows, all September). Box: load ~0.2 on 2 CPUs, 2.1 GB available, swap 571 MB resident but `si/so` 0, disk 16%. Redis 2.7 MB of 256 MB, no evictions. MySQL `select 1` ~2 ms. Price Sync peak 78 s (timeout 300). Exceptions: 3 Reverb broadcasts failing at Price Sync time, 3 PDO connection drops on 2026-11-01 16:50 UTC (recovered), 1 Plunk send. The API's nginx access log is off (Forge default), so Pulse is the only per-endpoint record.

**Findings acted on.**
- `default` has one worker that also sends reseller bot replies (up to 6.5 s each): the item 156 lookup moved to its own lower-priority lane in #664 (ADR-116 decision 7a).
- Public catalog reads: 56,724 over 500 ms in 7 days, in bursts of 3–5 per second, driven by the storefront's 30 s Data Cache backstop (ADR-077 2026-11-03 addendum). `catalogCache.revalidate` 30 → 300 s; hero slides keep 60 s through `scheduledCatalogCache` (their schedule changes with no save). Every other cached read's writers were traced to a purge; prod's revalidate URL and secret are set.

**Checked.** Storefront `tsc`, eslint and `next build` pass. The effect is to be read in Pulse a day after release.

## 2026-11-03 — Item 94: best-selling products per game in Reports (ADR-104 addendum)

**What.** Built the ADR-104 2026-11-03 addendum (design delegated by the founder).
- `Package::productKey()` (`{game}:d:{denomination}`, else `:c:{catalog_code}`, else `:p:{id}`) is the one definition of a product. `CatalogController::dedupByDenomination` now groups by it (129 catalog tests unchanged).
- `orders.product_key` and `orders.product_name`, written by `OrderFactory` (every channel) and the sandbox, never rewritten. The migration backfills existing orders from the order-level `initial` attempt's package, else `orders.package_id` (a missing package gives `:p:{id}` and no name). Prod at design time: 5 of 59 orders had been resend-swapped, 11 pre-2026-09-18 orders had no `initial` row, 1 bought package no longer exists.
- `GET /reports/breakdown/products` (`ReportService::productBreakdown`, the Games tab's scope and ledger profit rollup); the Games tab has a "Best-selling products" card (product, game, sales, % of game, orders, owner and affiliate profit; 10 per page) narrowed by the game filter. `RouteAccessTest` pin updated.

**Checked.** `BestSellingProductsTest` (5, red first: key cases, factory snapshot surviving a swap, idempotent backfill with a swap and a deleted package, grouping of a combo and a native package of the same amount with share and profit, unknown name); full backend suite passing; admin `tsc` and eslint; local dev DB migrated with plain `migrate` (1,593 of 1,593 orders keyed); the card seen in a browser (Playwright, light and dark, filter empty state) against the local API.

## 2026-11-03 — Docs audit at session end

Checked every doc this session touched against `origin/main..origin/staging` and production. Fixed: PRD §14 headline date and a "Waiting on `staging`" paragraph (#661–#666, one migration `add_product_key_to_orders_table`, no env change); PRD §10 OpenWA row (it still said v0.27.0; now v0.27.1 on the releases-plus-shared layout, and the `@lid` lookup); PRD §16 header (156 and 94 built, 158 and 159 added); a glossary entry for **Product (Reports)**; ADR-071's "30s TTL" given a pointer to ADR-077's 2026-11-03 addendum. Still to verify after the next release: Pulse's catalog `slow_request` count, the first nightly OpenWA backup on the new layout (due 19:37 UTC 2026-11-02; not run at 17:49 UTC), the first `@lid` tie in production, and the `product_key` backfill on prod's orders.

## 2026-11-03 — Release #668 (`staging` → `main`)

**What.** Released at the founder's request: #661–#667 (#664 item 156 `@lid` tie; #665 storefront catalog backstop 300 s; #666 item 94 best-selling products; docs and `scripts/openwa-upgrade.sh`). Merge `6a28239e`; CI all green including `deploy`; box release `81896833` at `6a28239`.

**Checked (read-only, prod).** Migration `2026_11_03_120000_add_product_key_to_orders_table` Ran; 91 orders, 0 without `product_key`; `/api/health` ok (database, queue, horizon, scheduler); `/api/health/whatsapp` both sessions `ready`.

**Still to check.** The first nightly OpenWA backup on the releases-plus-shared layout (19:37 UTC 2026-11-02; at release the box clock read 18:33 UTC, so it had not run yet; the last `openwa` `BackupRun` is #76, 2026-11-01, success, restore test passed). A day later: Pulse catalog `slow_request` drop (ADR-077 addendum) and the first `@lid` tie (log `privacy-id chat tied`).

## 2026-11-03 — Item 158 PR 1: keep the CS chats, masked (ADR-136, no AI)

**What.** Test-first (13 new tests; full suite 3,520 green). New `cs_messages` (masked text, `chat_hash`, `order_numbers`, `sender` customer/staff/system, `source` webhook/import) and `customer_notifications.wa_message_id`. `CsMessageRecorder` (the one writer), `CsTextRedactor`, `CsSenderClassifier` (our send by stored id, else same chat and exact text within 2 minutes, else wait while one of our sends is in flight, else staff), `ClassifyCsOutgoingMessageJob` on `whatsapp-lookup`. The webhook now handles the CS session's `message.sent`; `OpenWaClient::sendNow()` returns OpenWA's message id and `SendCustomerWhatsAppJob` stores it. `cs:import-history` copies the CS history (read-only against OpenWA). ADR-136 2026-11-03 PR 1 build notes addendum.

**Why.** OpenWA prunes messages older than 90 days from around 2026-12-24; the assistant's learning needs these conversations, and PR 4's staff takeover needs staff replies told from our own receipts.

**Gotchas.** SQLite does not honour `\_` in `LIKE` without an `ESCAPE` clause, so the id suffix match uses a plain `%_<id>`. The OpenWA-side personal-chat filter was left out (its effect on `session.status` is undocumented); Laravel filters instead.

**After release (prod writes, founder's go-ahead):** add `message.sent` to the CS webhook's events, then run `php artisan cs:import-history --since=2026-09-25` once and check the counts.

## 2026-11-03 — Release #672 (`staging` → `main`) and item 158 PR 1 switched on

**What.** Released at the founder's request: #669–#671 (item 158 PR 1, ADR-136). Merge `7f3d7924`; CI all green including `deploy`; box release `81934218` at `7f3d792`.

**Checked (read-only, prod).** Migration `2026_11_03_140000_create_cs_messages_table` Ran; `customer_notifications.wa_message_id` present; `/api/health` ok; both WhatsApp sessions `ready`.

**Production writes, with the founder.**
- CS webhook `5bc373e3…` events, edited by the founder in the OpenWA dashboard. Before: `message.received`, `session.status`. After (read back through the API): `message.received`, `session.status`, `message.sent`; filters still null, URL and retry unchanged. The reseller-bot webhook `efa62d48…` unchanged.
- `php artisan cs:import-history --since=2026-09-25`, run by the founder: "Read 1271 messages since 2026-09-25; kept 192 new personal-chat messages." Read back: 101 customer (10 without text, 19 with an order number), 22 system (equal to the 22 CS notifications ever sent), 69 staff (none matching a system template: staff replies, founder tests, B2B outreach), 20 chats, 2026-09-25 to 2026-11-03. Masking: 0 bodies with a 6+ digit run, an `@`, or `PG-`.
- Live check: a customer test message at 06:23:00 UTC was recorded 1 second later; a reply typed on the CS phone at 06:23:20 arrived as `message.sent` and was recorded as staff 11 seconds later. No failed classify jobs.

**Still to check.** A receipt or status card sent after this release should be recorded as system through `wa_message_id` (only the text-and-time path has been seen, in the import).

## 2026-11-03 — Item 157 OpenWA cleanup and the first system-classified receipt

**Checked first (read-only, prod).** Nightly OpenWA backups #74, #76 and #78 (31 Oct, 1 and 2 Nov 19:37 UTC, the last one on the releases-plus-shared layout): `success`, restore test passed. `~/OpenWA` → `openwa-deploy/releases/v0.27.1`; the running daemon's working directory is `releases/v0.27.1`; nothing in `openwa-deploy/shared` or the crontab names `OpenWA-v0.25.0`.

**Production write, run by the founder.** `rm -rf ~/OpenWA-v0.25.0` (1,022 MB) and `rm -f ~/openwa-backups/openwa-v0.25.0-20261101-1051.tar.gz ~/openwa-v0.27.0-build.log ~/openwa-v0.27.1-build.log`. After: `~` holds only `OpenWA` (symlink), `openwa-backups` (now just `openwa-v0.27.0-20261102.tar.gz`) and `openwa-deploy` (releases `v0.27.0`, `v0.27.1`); disk 12 G → 11 G used of 77 G; `/api/health` 200 and `/api/health/whatsapp` both sessions `ready`. `API_KEY_PEPPER` left unset (only a boot warning; setting it would force both OpenWA keys to be re-issued). Item 157 closed.

**Item 158 PR 1, last check passed.** A customer sent an order number to the CS number at 09:16:38 UTC (`cs_messages` #200, customer, order number kept); the status card sent back at 09:16:47 was stored as `customer_notifications` #25 with its `wa_message_id`, and the matching `message.sent` became `cs_messages` #201, sender `system`, `customer_notification_id` 25: classified by the stored id, not by text and time.

## 2026-11-03 — Item 158 PR 2: CS knowledge book and admin review (ADR-136, no AI)

**What.** Test-first (23 new tests; full suite 3,544 green). Migration `2026_11_04_120000_create_cs_knowledge_tables` (`cs_knowledge_entries`, `cs_knowledge_suggestions`, `cs_ignored_chats`). `CsKnowledgeSuggester` + `cs:suggest-knowledge` (daily 19:00 UTC, ROUTINE) pairs each finished chat's customer question with the staff answer that followed; `cs:prune-messages` (daily, ROUTINE) keeps masked messages and suggestions 2 years. `Admin\CsKnowledgeController` (super_admin) and the admin page `/admin/cs-knowledge` (PrimeReact kit, laid out like `/admin/applications`), nav under Customers. ADR-136 2026-11-03 PR 2 build notes addendum.

**Why without AI.** The founder chose to keep the model out of PR 2: the pairing is the input any model would need anyway, and the model is chosen once, in PR 3. The same session confirmed decision 31 is met (lawyer's OK; the Privacy Policy AI line read live on `pekangame.com/privacy` and `ohahastore.com/privacy`).

**Checked.** Backend suite; admin `tsc`, eslint and unit tests; a browser run against a throwaway sqlite backend (three seeded chats → three suggestions; Approve as new, Ignore chat, Merge, the All suggestions and Knowledge tabs; no page overflow at 390 px). Found and fixed in that run: switching tabs briefly showed the previous tab's rows (state now cleared on switch), and the question/answer columns were too narrow on mobile.

**After release.** The first nightly run at 03:00 MYT turns the imported history (since 2026-09-25) into suggestions; expect founder and staff test chats among them (use Ignore chat).

## 2026-11-03 — Item 158 PR 3a: the model test (ADR-136)

**What.** Test-first (15 new tests; full suite 3,559 green). `App\Services\Llm` (`ChatModel`, `GeminiChatModel`, `FakeChatModel`, `ChatModels` resolver with per-model prices); `CsAnswerer`, `CsOrderSituation`, `CsReplyGuard`, `CsGameInfo`; migration `2026_11_04_130000_create_cs_eval_tables`; `RunCsEvalCaseJob` on the new `cs-assistant` lane (`supervisor-cs-assistant`); `Admin\CsEvalController`; admin tabs Test cases and Model test. ADR-136 PR 3a build notes addendum (Gemini only; no prices, the brand's page link instead; split into 3a/3b).

**Checked.** Admin `tsc`, eslint, unit tests (the colour-token test caught a `warning-700` that has no token). A live smoke run against Gemini with synthetic messages (no customer data) on a throwaway sqlite backend: both models took the tool and JSON schema; 4 cases × 2 models through the real admin UI (import, run, blind pick, Reveal), cost about $0.01. Seen and fixed: the guard compared `localhost:3001` with a port; Flash-Lite looped on `game_info` until the call limit (the last call now has no tools). Flash-Lite also wrote "pembekal" (supplier) in a refusal, caught by the guard, and said "1–3 minit" without a source: both are what the test is for.

**Gotcha.** Model ids contain dots, so `config("services.cs_assistant.models.{$id}")` splits them; the config is read as a whole. A run started on a `sync` queue runs inside the HTTP request and hits the 30-second limit locally; production uses Horizon.

**After release.** The founder approves the knowledge entries the quality cases need, imports the drafted cases (JSON kept outside the repo), starts a run, picks blind and reveals. Then PR 3b.

## 2026-11-03 — Release #677 (`staging` → `main`): item 158 PR 2 and PR 3a

**What.** Released at the founder's request (merged by the founder; auto mode blocks Claude merging to `main`): #673–#676. Merge `f1955d9c`; CI all green including `deploy`; box release `81967216` at `f1955d9`.

**Checked (read-only, prod).** Both migrations Ran (`create_cs_knowledge_tables`, `create_cs_eval_tables`); `/api/health` 200; `/api/health/whatsapp` both sessions `ready`; `horizon:status` running and `horizon:supervisors` lists `supervisor-cs-assistant` on `redis-long:cs-assistant`; `schedule:list` has `cs:suggest-knowledge` (19:00 UTC) and `cs:prune-messages` (00:00 UTC); `GET /api/cs-knowledge/suggestions` answers 401 without a session.

**Next (founder).** The first nightly suggestion run is 03:00 MYT 2026-11-04 (from the imported history). Then: approve the knowledge entries the quality cases need, import the drafted test cases, start a model-test run, pick blind, reveal. Then PR 3b.
## 2026-11-03 — Model test run #1 on production, and three fixes

**What happened.** The founder approved five knowledge entries (First Topup, official store, membership OTP email, vouchers, payment methods) and ran the 40 drafted cases on production (run 1: 80 answers, about $0.10). Read-only review before any blind pick: 15 answers errored and 6 more failed checks, most of them from our own code, not the models.

**Fixed (test-first, each test red on the old code).**
- `game_info` crashed on every game with notes: `games.important_notes` is a list (cast to an array), not text. All 15 errors.
- The reply guard read the punctuation after a link as part of the host (`pekangame.com)`, `pekangame.com,`), so the brand's own link was blocked twice.
- A case's "must not contain" phrase matched inside words: "RM" in "permohonan" failed two correct reseller answers. It now matches outside letters only ("RM5" still counts).
- The Horizon long-wait alert e-mailed the founder at 247 s: a model test queues about 80 calls on the one `cs-assistant` worker, so `redis-long:cs-assistant` now alerts at 900 s.

**Real model findings kept.** 3.8 Flash refused a prompt-injection with "maklumat pembekal dan modal adalah sulit" (guard words, counted as a failure: decision 27 allows no deny-list hit) and handed over "where is the server ID" (the notes it needed were behind the crash). Run 1 should not be used for the pick; a new run after this fix is released.

## 2026-11-03 — Release #680 and model test run #2

**Release #680** (`staging` → `main`, merged by the founder): #678 docs and #679 run #1 fixes. Merge `476169b3`, box release `81975649`. No migration. Checked: `/api/health` all true after Horizon restarted (503 for a few seconds during the restart), both WhatsApp sessions `ready`, 10 supervisors running, `redis-long:cs-assistant` wait threshold 900.

**Run #2** (read-only review on prod): 80/80 answers, 0 errors; Flash-Lite 2 safety and 4 total failures, 20 blind picks, $0.055; 3.8 Flash 1 safety and 3 total failures, 18 picks, $0.076. Details and the deferred model decision are in ADR-136's run #2 addendum. Google lists `gemini-3.1-flash-lite` for shutdown at the earliest on 2027-05-07 (replacement `gemini-3.5-flash-lite`).

**State at session end.** Item 158: PR 1, PR 2 and PR 3a live; 5 knowledge entries and 40 test cases on prod; nightly suggestions start 03:00 MYT 2026-11-04. Next, when the founder takes it up: choose the model (Qwen to be considered), then PR 3b (Shadow), then PR 4 during Shadow with Live off.

## 2026-11-03 — Reviews on affiliate storefronts, the marquee gap, and a per-brand OG image

The founder asked three things: why the homepage review marquee leaves a blank strip at the right, why affiliate storefronts show no reviews although the admin approved some, and why `pekangame.com` has no OG image (opengraph.xyz).

**Checked read-only on prod first.** Brand scoping is correct: `GET /api/catalog/reviews` with `X-Storefront-Host: fixfastapp.com` returns its one commented review. OhahaStore's 3 approved reviews (orders on Mobile Legends Indonesia) are all star-only, and both public surfaces show only reviews with a comment, so the game page answered `review_count: 3, reviews: []` and hid the section while its JSON-LD still emitted `aggregateRating`. PekanGame has 4 commented reviews, all from one customer, so the 2-per-customer cap leaves 2: one marquee copy was ~790px, narrower than the screen. `affiliate_seo_settings.default_og_image` is `NULL` for all three brands, and the public settings endpoint would have served the primary's image to every brand.

**Built (one PR into `staging`):** ADR-082 and ADR-060 2026-11-03 addenda. `GameReviewsSection` shows the score badge whenever `review_count > 0`; the marquee repeats reviews to 8 cards per copy, uses card padding instead of `gap` and moves the left inset to the wrapper; `SeoController::settings` reads `default_og_image` from the brand's own row (test `test_default_og_image_is_the_brands_own_never_the_primarys`). New PRD §16 item 160 (affiliate OG image editor).

**Verified.** Backend suite 3561 passed. Storefront `tsc`, lint and build clean. Local storefront against the prod API (read-only): OhahaStore's Mobile Legends Indonesia page renders "5.0 · 3 verified reviews"; Chromium measured the marquee at 390, 1440 and 2560px: one copy is 2688 / 3168px, exactly half the strip, and frames at 0% and -50% look the same; with reduced motion only the 2 real cards show.

**Gotcha.** Playwright's `scrollIntoViewIfNeeded` never finishes on a running CSS animation ("element is not stable"); pause the animation first.

**After release (founder):** make or pick a 1200 × 630 PNG/JPG under ~300 KB, upload it in the Gallery, paste its URL in Admin → SEO → Settings → Default OG image, then re-check opengraph.xyz.

## 2026-11-04 — Item 158: run #3 candidates, a Claude adapter, and the run #2 prompt fixes (ADR-136)

The founder resumed item 158 after the reviews/OG fix (#682) and chose the run #3 candidates: Gemini 3.1 Flash-Lite, Gemini 3.5 Flash-Lite, Claude Haiku 5.5. Qwen was tried and excluded: the founder's Alibaba key reached the Singapore endpoint (114 models listed, `qwen3.8-flash` among them) but every chat call answered `AccessDenied.Unpurchased`. Gemini 3.8 Flash dropped.

**Built:** `ClaudeChatModel` (official `anthropic-ai/sdk` 1.4: effort low, structured output, content blocks replayed, tool results matched to `tool_use` ids), `ChatModels::missingKeys()` so a run names the unset key, the three prompt fixes from run #2, the model test panel laying out three answers side by side. Tests blank `ANTHROPIC_API_KEY` like the other live keys (phpunit, concurrency, e2e boot).

**Verified.** Backend suite 3566 passed; admin `tsc` and lint clean. Live, against the local dev DB, through `CsAnswerer`: Haiku 5.5 and 3.5 Flash-Lite both call `game_info` and answer in valid JSON; the cost question is refused as "maklumat dalaman" without echoing "modal" by all three; "ff" finds Free Fire; on a wrong player ID, 3.5 Flash-Lite still invented a policy (Haiku and 3.1 handed over).

**Gotchas.** The PHP SDK 1.4 sends the key as `Authorization: Bearer`, not `x-api-key`. It ignores `RequestOptions::timeout` (the transport enforces it), so the adapter passes a Guzzle client; SDK calls bypass Laravel's `Http::fake()`, so its tests use a Guzzle mock handler. CI's `composer` job (`composer audit --locked`) runs only when composer files change, so the first PR to touch them inherits any advisory published since: here `symfony/mime` CVE-2026-45070 (reported 2026-10-28), fixed by updating it to 8.1.10 in the same PR.

**Next (founder):** put `ANTHROPIC_API_KEY` on Forge before release; add the "server ID" entry; set the angry-customer case to any reason; after release, run #3 and pick blind, then Reveal.

## 2026-11-04 — Release #684 (`staging` → `main`)

Shipped #681, #682 and #683 at the founder's request. Merge `8a18f435`; the CI `deploy` job reported success about two minutes before the box switched to release `82001662`, so a check run right after the job still saw the old release (`81975649`). Wait for `current` to point at the new release before checking. No migration. `ANTHROPIC_API_KEY` set on Forge by the founder.

Checked read-only: `/api/health` all true; both WhatsApp sessions `ready`; Horizon with `supervisor-cs-assistant`; on the box `ChatModels::missingKeys()` is empty and the candidates are `gemini-3.1-flash-lite`, `gemini-3.5-flash-lite` and `claude-haiku-5-5`; the Anthropic SDK loads. Live storefront: OhahaStore's Mobile Legends Indonesia page shows the "verified reviews" badge; the PekanGame marquee renders its repeated cards. `default_og_image` is still null on every brand (the founder sets PekanGame's next).

Next: the founder adds the "server ID" entry, sets the angry-customer case to any reason, runs model test #3, picks blind and reveals; then PR 3b (Shadow).

## 2026-11-04 — Item 158: Haiku 5.5 chosen; PR 3b (Shadow) built (ADR-136)

Run #3 on prod (founder ran and revealed it): Haiku 5.5 15 picks / 2 safety fails / $0.019, 3.1 Flash-Lite 15 / 1 / $0.064, 3.5 Flash-Lite 10 / 0 (but 3 over-cautious handovers) / $0.046. All safety fails were refusals that named the topic, caught by the guard. Founder chose Haiku; the refusal is now one fixed sentence (live: 8/8 on the leak prompts). Before the run the founder approved knowledge #6 (MLBB Server ID) and #7 (Genshin/HSR zone) and set the angry-customer case to any reason; checked read-only on prod.

**Built (one PR, one migration `2026_11_05_120000_create_cs_shadow_tables`):** `cs_assistant_settings`, `cs_bot_drafts`, `ai_*` columns on suggestions; `CsShadowDrafter` (from the webhook, delayed job per message, one draft per burst), `CsChatContext` (brand and order facts, now shared with the suggester), `CsShadowJudge` + `cs:judge-drafts` (19:30 UTC), `CsSuggestionWriter`, `CsSpend` (daily cap switches Off); admin Shadow and Settings tabs; drafts pruned at 90 days.

**Stress test findings fixed in the build:** no per-game delivery time exists, so manual games get one panel limit (180 min); a burst that is only an order number is left to the status card; the model price list doubled as the test-candidate list, so a `candidate` flag keeps the judge out of runs; the brand lookup had a second copy coming, so it moved to one class.

**Verified.** Backend suite 3583 passed; admin `tsc`, lint and unit tests clean. Browser (throwaway sqlite backend, local admin): Settings saves (PUT 200, mode Shadow); the Shadow tab shows accuracy and drafts beside staff replies with the overrule select; at 390px loaded directly, no sideways scroll on the page, Shadow or Settings tabs. Live on the throwaway DB: Haiku drafted, Sonnet graded through structured output with a sensible note; one draft plus its grade cost about 0.3 sen.

**Gotcha.** In a throwaway DB, sample drafts seeded with `last_message_id` 1–3 collide with the first real `cs_messages` ids, so the drafter's "already drafted" check returns early; seed drafts with ids no message will have.

**After release (founder):** switch Settings → Mode to Shadow. First drafts appear as customers write; first grades the night after.

## 2026-11-04 — Release #687 (`staging` → `main`): item 158 PR 3b (Shadow)

Shipped #685 and #686 at the founder's request. Merge `bdea7c1b`; box release `82010527`. The `backend-concurrency` job ran long (over 10 minutes), so the deploy started later than usual. One migration, applied (`2026_11_05_120000_create_cs_shadow_tables`). Checked read-only: settings row `off` / Haiku 5.5 / Sonnet 5.5 / cap 1000 sen, no key missing; `cs:judge-drafts` scheduled 19:30 UTC; health all true; both WhatsApp sessions `ready`; Horizon running.

Before release, an end-to-end check on an isolated local backend (database queue, OpenWA at a dead port): signed webhooks for a two-message burst and a staff reply produced one draft for the burst from knowledge, the staff reply classified as staff, nothing sent; `cs:judge-drafts` graded it "same" through Sonnet; `cs:suggest-knowledge` wrote a general question and answer and pointed to the existing entry; in the browser, the model's version prefilled Approve, an overrule saved, and a tripped cap showed its banner.

Next: the founder switches Settings → Mode → Shadow; Claude checks the first prod drafts read-only. Meanwhile the founder chooses between the reseller rebate idea (a new feature, group E, needs an ADR) and ADR-118 marketing campaigns; PR 4 after 1–2 weeks of Shadow.

## 2026-11-04 — Next features discussed: KOL campaigns and a reseller cash rebate (no code)

After release #687 the founder raised two features; both are now in PRD §16 group B with the data and decisions (items 57 and 161). Highlights:

- **KOL campaigns (item 57, ADR-118):** trigger met (two KOLs, start as soon as possible); fixed monthly fee per KOL; the discount must be computed. Prod on 2026-11-04: all 1,269 active packages carry a 10% markup on landed cost, about 9.1% margin, not ADR-118's 15% median, so a 5% discount leaves about 4.1% before the fee. Open: each KOL's fee, and whether codes work on affiliate brands.
- **Reseller cash rebate + automatic refund (item 161):** founder decided to build both. Prod: 5 tiers (1–5%), one buying reseller (October RM336.10 / RM9.47). Recommended a rebate as a share of the reseller's own platform profit, and an automatic refund only on a confirmed Failed delivery after retries (revising ADR-073 decision 7).

The founder closed the session here; the next session starts from item 57 (ADR-118 addendum), then 161.

## 2026-11-04 — ADR-118 v1 addendum: KOL campaigns designed for two PekanGame KOLs (docs only)

The founder reviewed the first 28 CS knowledge suggestions with Claude (all closed: 13 merged, 2 approved as new entries #8 and #9, 12 rejected, 1 chat ignored; checked read-only on prod), then settled item 57's open questions.

**Founder decisions:** KOL 1 RM500 a month, KOL 2 from RM2,000 (negotiating); PekanGame only; a percentage with no RM cap (as the competitor), shown to the customer only as RM saved; new customers only, once per code; attribution stays most-recent-touch (with new-customers-only a customer stays with the KOL who brought them in); not on Joki; a 2% margin floor; a `?promo=` link that prefills the code for 7 days; target CAC and trial length set later in the panel.

**Data used (prod, read-only, paid non-wallet orders since 2026-10-05):** 41 orders, RM2,338.09, 24 customers, 1.71 orders each, 33 of 41 on OhahaStore; about RM8.85 profit per customer per month. Breakeven new customers a month: KOL 1 ≈ 57 (one month of buying) or ≈ 19 (three months); KOL 2 ≈ 226 or ≈ 75.

**Code-trace pass (three forks, read-only; key findings re-checked by Claude):** R1 holds with no change to any profit writer, compensation or CHIP matching. Corrected in the addendum: R7's log-and-proceed is gone (reserve joins `reserveAll()`), R8's restore sites are now `applyFailed` and `settle`, `VoucherPreviewController` prices on its own, the idempotency hash lacks the promo, wallet orders carry the primary brand (excluded from the new-customer search), the promo must lower `platform_profit` in the draft (else ledger overstatement and ADR-111 drift flags), "new" must mean no recognised order (else a compensated failure blocks a retry), a Paid-after-Failed delivery must re-reserve, the click throttle needs its own prefix, the KOL fee belongs on an Envelope `Advertising` posting with a `campaign_id`, Customer Analytics now sits under Overview.

**Accountant's answers (same day):** discount is contra-revenue (MPERS s.23), shown as gross − promo = net; KOL fee is an advertising expense in the month of service (prepayment / accrual); no WHT for local individuals (10% for non-residents); SST is not recoverable, so it is part of the fee; an individual KOL gets a self-billed e-invoice; free diamonds to a KOL are a marketing expense at cost, never sales. The Envelope Ledger is cash-dated, so V12 became a per-campaign fee schedule (the agreement) plus campaign-tagged Envelope payments (the cash), as affiliate commission already works; a general service period on every Envelope expense was rejected for now.

**Adversarial stress test (three forks: abuse and concurrency, accounting and ledger, lifecycle and hygiene; key findings re-checked by Claude in code and on prod):** the addendum was rewritten as one consistent list, V1–V20, with an explicit "Superseded for v1" list. Fixed in the design:
- the KOL fee must never enter operating profit or the month-close allocation (Envelope expenses are already excluded there; adding the accrual would deduct it twice);
- `void()` must carry `campaign_id`;
- the promo line is proportional on a partial settlement (`recognisedPromoSen` beside `keptSen`);
- a campaign needs a fee row from creation, in an open month;
- one KOL discount per customer per brand under a brand-level lock (two concurrent orders with two KOLs' codes got both), which made `customer_campaign_attribution` dead weight (dropped);
- cancel-and-replace of the same customer's unpaid checkout (prod: 5 customers let a link expire and retried within 1–8 minutes, and would have been locked out);
- qualified new customers (first order ≥ RM10 or a repeat) so tiny orders can't pad a KOL's CAC;
- an optional RM max per code;
- generic rejection messages;
- reseller identities rejected;
- the Paid-after-Failed re-reserve never blocks a delivery;
- code lifecycle (chosen by the admin, case-insensitive, unique forever, immutable after the first redemption).

Founder agreed to all.

Next: build v1 test-first.

## 2026-11-04 — Item 57 PR 1: KOL promo codes, backend core (ADR-118 V1–V20)

**Built:** one migration, `2026_11_06_120000_create_campaign_tables`:
- tables `campaigns`, `promo_codes`, `promo_code_redemptions`;
- on `orders`: `promo_code_id`, `promo_discount_sen`, `attributed_promo_code_id`;
- `platform_settings.campaign_min_margin_percent`.

`App\Services\Campaign\CampaignService` owns the money path:
- `quote()` runs before `computeTotal()` in checkout, the review total and the voucher preview;
- `reserve()` is the third instrument in `reserveAll()`;
- `commit()` is called through a new `commitInstruments()` at the four delivery sites;
- `restore()` runs in `applyFailed` and in `settle` (by share).

`PromoCustomer` is the one identity matcher: email, phone through `PhoneNumber`, and player account scoped by game family. The checkout controller cancels the same customer's unpaid promo checkout through CHIP before a new one (ADR-135's cancel-then-read pattern).

**Verified:**
- Fast suite 3607 passed (24 new: 19 checkout, 5 lifecycle).
- New MySQL concurrency test `PromoCodeReserveConcurrencyTest`: one customer racing two KOL codes, and one code twice. Passed 6 runs in a row.
- Mutation checks: removing the wallet exclusion, the reseller check, the restore on failed payment, the promo commit, the share restore, the re-reserve, or the promo pricing each fails its test.
- Local dev DB migrated with plain `migrate`; the llm views were re-created and are intact.

**Gotchas:**
- **sqlite.** Adding a foreign key to `orders` rebuilds the table under the `llm_report_*` views. The migration drops and re-creates them from their stored SQL, as `2026_10_08_140000` does.
- **MySQL REPEATABLE READ.** The first concurrency run let both racers reserve. Plain reads after the brand lock still saw the old snapshot, so they now read with a shared lock (ADR-118 PR 1 build notes).
- **Fake gateway in tests.** A fake gateway bound as a fresh instance per resolve loses its "cancelled" state between `cancelPayment` and `getPayment`. Bind one instance.

**Not reachable yet:** no campaign can be created until PR 2 (fee schedule) and PR 4 (admin page). PR 3 adds the storefront field.

## 2026-11-04 — Item 57 PR 2: KOL fee schedule and accounting (ADR-118 V12/V13)

**Built.** One migration, `2026_11_06_130000_create_campaign_fees_table`:
- `campaign_fees`, unique on (`campaign_id`, `from_month`);
- `budget_envelope_postings.campaign_id`.

`CampaignFeeSchedule`:
- creates a campaign together with its first fee row, on the primary brand;
- adds a later row, and sets an end month, only where every changed month is open;
- computes the fee for a month, the accrued and paid totals, the prepaid / (owed) balance, and a payment preview.

`BudgetEnvelopeService`:
- takes `campaign_id` on an Advertising expense or director-paid expense only;
- `void()` copies the tag to the reversal.

Monthly Summary:
- `gross_sales_sen` and `promo_discounts_sen` (`RecognisedRevenue::promoDiscountSen`, the kept share on a settled partial);
- `kol_fees_accrued_sen`, `kol_fees_paid_sen`, `kol_prepaid_balance_sen`;
- `envelope_manual_expenses_sen` now leaves out tagged payments.

The close preview's net subtracts the accrued KOL fee instead of the cash payment.

Admin:
- the Envelope form shows a "KOL campaign" dropdown for Advertising, with a live balance preview ("Owed RM 500.00 now → Prepaid RM 1000.00 after, covers to Jan 2027");
- the entries table shows `KOL: <campaign>`;
- the summary page has five new lines with definitions;
- the month-close panel includes the KOL fees.

**Verified.**
- Backend suite: 3614 passed (7 new). Mutation checks on the plain-expense exclusion, the void tag, the close net and the closed-month guard each fail their test. The first version of the closed-month test passed for the wrong reason (an "end before start" rejection); it was rewritten.
- Admin: `tsc`, lint and unit tests (22) are clean, and the build is OK.
- Browser check: a throwaway sqlite backend and a local admin build, driven with Playwright. A RM1,500 payment was tagged to a RM500/month campaign. The summary showed accrued RM500, paid RM1500, prepaid RM1000, manual expenses RM0 and operating profit unchanged.
- Local dev DB migrated.

## 2026-11-04 — Item 57 PR 3: storefront promo field, `?promo=` link, readers (ADR-118 V4/V16/V18)

**Built.** Two migrations:
- `2026_11_06_140000_create_promo_code_clicks_table`: one row per `?promo=` visit (V16), so the scorecard can count clicks for any range;
- `2026_11_06_150000_add_promo_discount_to_llm_report_orders_view`: the Report Assistant view gains `promo_discount_sen`.

Backend:
- `POST /api/promo-clicks` (`throttle:60,1,promo-click`, `storefront.brand`). It always answers 204 and records only a real code of this brand.
- `promo_discount` added to track-order (and so to the `OrderStatusUpdated` broadcast) and to the affiliate order API.
- A "Promo Discount (RM)" column in the orders workbook. It is information only, since the promo is already inside Selling Price.
- The Report Assistant schema line.
- The admin order detail loads `promoCode:id,code`.

Storefront:
- "Have a promo code?" under the voucher field in the Review Modal. Apply needs email and phone first. OrderForm's totals preview validates the code, and a refusal drops the code and shows the server's reason.
- The customer sees "You save RMx.xx" and a "Promo (CODE)" row, never the percentage.
- Each field hints when given the other kind of code (vouchers start `VC-`).
- `?promo=CODE` is read on any page by `PromoLinkCapture` in the root layout. It is kept in `localStorage` for 7 days to prefill the field, and one click is logged per tab (`sessionStorage`).
- Members (founder's option a): when the member's own price beats the code's price, the modal says so. The quota fall-back message no longer fires because of a promo.
- `showPromo` / `showMembershipPromo` renamed to `showMembershipUpsell` (R13).
- The order summary sidebar and the track-order page list Promo (and the sidebar Voucher), so Package Price, discounts and fee add up to Total.

Admin: Order Detail shows "Promo Discount (CODE)". Portal: "Promo discount" on the order view.

**Fixed on the way.** The Review Modal stayed mounted while closed, so a reopened modal still showed an applied voucher that OrderForm had cleared and would not send. It now mounts only while open, on both the order page and `/joki` (same bug there).

**Verified.**
- Backend suite: 3618 passed (4 new). The public-route allowlist and the workbook's column letters were updated for the new route and column.
- Storefront, admin and portal: `tsc`, lint and build are clean.
- Browser check with the e2e harness and a temporary spec (deleted afterwards). The flow:
  1. `/?promo=kol10` logged one click; a reload in the same tab logged none.
  2. The modal prefilled `KOL10`. A `VC-` code got the hint.
  3. Applying showed "You save RM0.50", and checkout sent `promo_code: KOL10`.
  4. The order was stored with `selling_price` 450 and `promo_discount_sen` 50, and the admin API returned `promo_code.code` KOL10.
  5. After the CHIP webhook and delivery, the status page showed Package RM5.00, Promo −RM0.50, Fee +RM1.00.
  6. The same customer applying again saw the new-customers message, and the code was dropped (mobile 390px).
- A second browser round (the founder asked for the cases the first one skipped):
  - voucher and promo together: RM5.00 − 0.50 promo + 1.00 fee − 2.00 voucher = RM3.50, and Confirm & Pay matched;
  - closing and reopening the modal cleared the voucher and kept the promo;
  - a signed-in top-tier member who applied the code saw "Your member price (RM4.16) is lower…", with no quota message;
  - the whole modal on mobile at 390px;
  - `/joki` shows no promo field.
  The Promo row was moved directly under Package Price, so the member upsell strip no longer separates the two.
- Local dev DB migrated.

**Not reachable yet:** codes can only be created from PR 4's Campaigns page.

## 2026-11-04 — Item 57 PR 4: Campaigns page and scorecard (ADR-118 V14/V15/V17/V19)

**Built.** One migration, `2026_11_06_160000_add_campaign_scorecard_settings_to_platform_settings`: `campaign_qualified_min_sen` (default 1000), `campaign_target_cac_sen` and `campaign_trial_months` (nullable).

Backend:
- `ReportService::campaignBreakdown()` reuses `scopedOrders` / `salesByGroup` / `profitByGroup`, so sales and profit match Reports. One grouped query per side covers redeemed and attributed-only orders (`code * 2 + flag`).
- `RecognisedRevenue::promoDiscountByCode()` gives the discount per code, with the kept share on a settled partial.
- Clicks come from `promo_code_clicks`.
- New customers are committed redemptions paid in the range, de-duplicated through the `PromoCustomer` seam. A customer is qualified when their first order is at least the minimum or they bought again.
- Fees are the accrued schedule for every KL month the range touches.
- `Admin\CampaignController` (super admin) handles the list, create, show, rename or end, fee rows, codes (create and edit), the margin check and the settings, with a FormRequest per mutating route.
- A code is 3–20 letters and digits, stored uppercase and unique forever. Its percentage is fixed once any redemption exists.
- The margin check calls `CampaignService::refusedOn()`, the same floor checkout applies.
- Verdict (the founder's rule): none until a target CAC and a trial length are both set. During the trial it shows "month x of n"; after it, the all-time CAC is compared with the target, and no qualified customer counts as above target.

Admin:
- Customers → Campaigns, in the Reports layout: KPI cards, `ReportCard`, `ReportTable`, and the Reports date filter. `FilterSelect` moved out of the Reports page into `components/reports/FilterSelect.tsx` so both pages share it.
- The campaign page has the range and all-time scorecards, codes (copy tracker link, edit, live refused-package list), fee schedule and balance.
- Forms use the kit's dialogs. Money uses Reports' format ("RM 500.00", a loss "−RM 500.00").

**Verified.**
- Backend suite: 3631 passed (13 new: the API rules and a November scorecard with three customers, one returning, one below RM10).
- Admin: `tsc`, lint and build are clean.
- Browser: the local admin dev server, signed in through the sidebar, with screenshots of the list, campaign page, code form (15% refused on 14 local packages) and 390px mobile (no sideways scroll), compared beside Reports.
- Local dev DB migrated.

**Not in v1 (V20):** deleting a code (pause it instead), Customer Analytics' campaign filter, the funding split.

## 2026-11-04 — Released #694: KOL Marketing Campaigns v1 live

**Release.**
- `staging` → `main` PR #694, merged by the founder as `29384be2` (11:00 UTC).
- CI (all jobs) and the `deploy` job were green; the box runs release `82073213`.
- It ships #688–#693: two docs PRs and ADR-118 PR 1–4.
- Five additive migrations, all `Ran`. No env change.

**Checked read-only after release.**
- Health: `/api/health` all true, and both WhatsApp sessions `ready`.
- Data:
  - 96 existing orders untouched (`promo_discount_sen` 0, no attribution);
  - settings defaults (margin 2%, qualified RM10);
  - `llm_report_orders.promo_discount_sen` and `budget_envelope_postings.campaign_id` present.
- Endpoints:
  - a preview without a promo is unchanged (ML Malaysia 14 Diamonds, RM1.09 + RM1.00);
  - an unknown code answers 422 "This promo code is not valid.";
  - `POST /api/promo-clicks` answers 204 and records nothing for an unknown code;
  - `/api/campaigns` answers 401 without a session.
- Frontends: the four Vercel deploys `success`, and the live storefront bundle carries the `?promo=` capture.

**Founder, same day.**
- Created the first campaign "2ez4Jepv": RM500 a month from November, code `2EZ4JEP`, 5%, RM1,000 budget, no cap.
- Set target CAC RM15 and trial 2 months, after Claude's read-only customer-value check: 26 retail customers; average platform profit RM10.51 per customer to date; RM13.81 for a first order ≥ RM10, RM1.55 below it.
- The first click was logged.

**OpenWA #1978 (§16 item 159).** The maintainer accepted the chat-list request as an enhancement and asked about our `chat_states` rows. Checked read-only:
- all three CS rows are groups (one muted forever, two archived);
- the list shows 8 chats (7 groups, 1 `@lid`), against 23 stored 1:1 chat ids.

The founder posts the answer (a draft without phone numbers).

**Chrome cannot reach the local Herd API.** The founder's Chrome could not reach `https://kedairuncit-backend.test` from the local storefront: the CORS preflight failed with no response, and Herd's logs were empty. Playwright on the same machine succeeded, so the cause is in the browser (likely Chrome's Secure DNS or an extension), not the code. Found on the way and fixed in #692: an applied promo whose check fails on the network now says so instead of "Checking…".

## 2026-11-04 — Full docs audit after release #694

Every status fact re-checked against prod (read-only), git and code.

**Prod facts the docs had wrong or missing:**
- public reseller applications are **open** (docs: off);
- the CS assistant is in **Shadow** since 03:53 UTC (docs: off);
- Joki is **on** for PekanGame since 2026-11-03 17:40 UTC (docs: off);
- PekanGame's default OG image is set (docs: none);
- a third reseller, AyrisShop (2026-10-31), has no order yet;
- `@lid` chats work live: 2 tied to their phone, 7 sends;
- October is still not closed.

**PRD changes:**
- §14 rewritten as the current state: customers by brand and channel, switches, KOL campaigns, payment methods, the #694 record, and what has not been seen on prod yet.
- §15 rows brought to prod: Campaigns, CS assistant, Partner onboarding, Joki, SEO, WhatsApp, Storefront, Accounting, Withdrawals.
- §16: items 158 and 159 brought up to date. Item 28's trigger falls on 2026-11-05 (two weeks of real payments). Item 155 still waits on the October close.
- §4.2 and §11 no longer call the CS assistant future work.
- New §6.26 (CAMP-1..9), §8 campaign entities, and glossary entries (Attributed order, Qualified new customer, CAC). The Campaign entry no longer claims a Customer Analytics roll-up.

**ADR index:**
- ADR-118 and ADR-128 are now In force.
- ADR-075 and ADR-091 drop build-status words from their Standing (the index never tracks build status).
- A scripted check found every file indexed and the Standings consistent with the banners.

**Archive:**
- Build-log entries 2026-10-23 → 10-27 moved verbatim to the archive ("Tenth archive move").
- PRD §14's per-release paragraphs (#514 → #687) and its 2026-11-03 headline moved verbatim to the bottom of the archive.
- Every pointer was updated: AGENTS.md, the PRD headers and rows, ADR-126.

**Not changed:** the 8 required CI checks in AGENTS.md (matched against the ruleset) and the stack versions (Laravel 13, PHP 8.4, Next 16, Astro 7) are correct.

## 2026-11-04 — October closed, Gamevion's new API (ADR-137 proposed), OpenWA #1992

Read-only checks on prod, no code change.

**October close.** The founder closed October 2026 at 21:02 MYT (close #4), after the evening's docs audit had recorded it as open. Checked with `MonthCloseService::preview(2026, 10)` inside a rolled-back transaction:
- operating profit RM129.22, all allocated to Company Savings; drift since the close 0;
- envelope expenses RM257.89 (software RM152.31, other RM88.06, advertising RM17.52), so the month's net is −RM128.67; KOL fees accrued RM0 (the first campaign starts in November);
- gap −RM10.95 against September's −RM10.94 (the CHIP cutover), with the founder's written note.

The 2 sen between CHIP settlement #7's matched and file totals (RM1,492.38 against RM1,492.36) is two DuitNow orders, #62 and #64, whose fee we computed 1 sen under CHIP's (25 against 26 sen, 40 against 41 sen). That is the known pre-#649 DuitNow fee rounding; no adjustment needed. The founder has also told the resellers about the ~6.8% price rise, so PRD §16 item 155 is closed.

**Price Sync `partial` runs are all Gamevion.** In the 14 days to 2026-11-04: 672 runs, 593 `success` and 79 `partial`. Every partial run is Gamevion failing to connect to `api.gamevion.com`: cURL 7 (42), connect timeout (24), SSL timeout (13). On 2026-11-04 (UTC) it was 19 of 29. Digiflazz never failed.

**Gamevion moved to a new API.** The new API at `gamevion.cloud` (vendor document v62.25) has:
- a single `gvk_` key;
- a per-game catalog;
- async orders with a `client_ref` that replays the original order on a repeat;
- a webhook signed with HMAC-SHA256;
- no documented sandbox.

The founder confirmed that the old API no longer takes orders. Gamevion has 71 packages, none active, and no order has ever been routed to it. The founder switched the supplier off in `/middleware`; checked on prod at 14:11 UTC (`is_active = false`).

Founder decisions, recorded in ADR-137 (Proposed):
- rewrite the adapter, after item 28;
- `gametopup` only;
- no `max_price`, matching `DigiflazzAdapter`, which sends none;
- no test top-up yet.

New PRD §16 item 162 tracks it.

**FX.** open.er-api.com is still called, but only for the market rate shown beside the landed rate in the Price Sync Center (`ProductSyncService.php:150`). Price Sync costs and per-order real cost use `LandedCostRate` (ADR-134). The last run priced IDR at 0.00023913 MYR (deposit pool) against a market rate of 0.000225, a spread of +6.28%.

**OpenWA #1978.** The maintainer shipped the fix in rmyndharis/OpenWA#1992 and closed the issue. It is on OpenWA's `main` only; the latest release is still v0.27.1. Item 159 now waits for the release that ships it.

## 2026-11-05 — Fix: Refresh on /admin/balance crashed the page

**Symptom (founder, prod).** Clicking Refresh on a supplier row at `/admin/balance` showed "This page couldn't load". A hard reload then showed the page, with the refreshed balance.

**Cause.** `POST /api/middleware/suppliers/{id}/refresh-balance` answered with the bare model (`$supplier->fresh()`). That answer has no `display_balance` and no `is_api_backed`. The page swaps the answer into its table, so `formatSupplierBalance(undefined)` threw on `toLocaleString`. The `/middleware/suppliers` page never showed the bug because it refetches the list after a refresh. Three more endpoints answered in the same bare shape: `status` (two branches), `store` and `update`.

**Fix.** `SupplierController::present()` is now the one shape for a supplier row. `index()` uses it, and so do all five single-supplier answers (`update` still adds `connection_probe`). The regression test `test_single_supplier_responses_match_the_index_row_shape` asserts that the refresh, status and update answers have exactly `index()`'s keys and never include `api_config`. Full backend suite green (3,632 tests).

## 2026-11-05 — Item 28: supplier buffer forecast and low-balance email (ADR-115 2026-11-05 addendum)

**Why now.** The 2026-10-14 trigger fell on 2026-11-05, two weeks after real payments. Real Digiflazz spend from 22 Oct to 4 Nov was IDR 9.57 million (about RM 2,289), and it came in bursts: 31 Oct and 1 Nov were IDR 7.58 million.

**Founder decisions (in Malay).**
- The buffer learns from sales.
- From Thursday it must last until Monday, because transfers do not land at the weekend.
- A check runs after every webhook.
- The email fires below the higher of the floor and the forecast.
- No recovery email.
- Malaysia calendar days.
- A margin setting, default 0%.

**The stress test changed the design before code:**
- **p90 → the 60-day maximum.** On the real data, p90 was IDR 0.77 million (RM 184), against a real two-day burst of IDR 7.58 million: 36 of the 59 two-day windows were empty.
- **Compute on read** instead of stored columns.
- **Lower of polled and ledger.** The polled balance moves only on a webhook, a poll or Refresh.
- **One seam per trigger.** `recordOrderDrawdown()` is shared by all five delivery paths. `Supplier::recordPolledBalance()` replaces five direct writers.
- **An atomic once-a-day claim**, the price-sync staleness pattern.
- **One assessment** replaces the Dashboard's and the daily command's separate threshold rules.

**Built.**
- `SupplierBufferService::assess()` and `SupplierBuffer`.
- `SupplierBufferAlert` (email through `BackupFailureAlerter`, area "Supplier balance"; one log line with each email).
- `CheckSupplierBufferJob`: unique per supplier, `default` lane.
- `GET /api/dashboard/supplier-buffers` (admin and super admin, like `/dashboard/health`).
- Supplier settings `topup_lead_days` and `buffer_margin_pct`; Low-balance Threshold is relabelled Low-balance Floor.
- `/admin/balance` restyled with the report kit (Campaigns layout): buffer cards, then the two tables as ReportTables. A Refresh now reloads the whole page's data, because the buffer moves with the balance.
- Migration `2026_11_07_120000_add_supplier_buffer_alert`: `suppliers.low_balance_alerted_at`, plus index `(supplier_id, type, created_at)` on `supplier_ledger_entries`.

**Tests.** 18 new tests in `SupplierBufferTest`:
- busiest window, with quiet days counted as zero;
- all six weekdays;
- lead time;
- the Malaysia-midnight boundary (Wednesday 23:59 against Thursday 00:00 MYT, and drawdowns either side of midnight);
- floor, margin and the lower-of balance;
- the email once a day, a reminder the next day, recovery that clears it, and a claim already taken;
- every trigger;
- the daily sweep;
- the Dashboard chip and the endpoint.

The guard tests (route list, `visible_config`) and the old daily-warning test were updated. Full suite: 3,649 tests green. Admin: tsc, lint, 22 unit tests and the build all pass.

**Browser check.** The founder's running dev servers served the branch (`localhost:3000`, API `:8000`).
- The real endpoint answered 200, with a 5-day window through Monday 9 Nov on Thursday 5 Nov.
- Both buffer states were rendered from prod-shaped fixtures intercepted in Playwright, so the dev DB was not written. They were checked in light, dark and 390 px, with no horizontal page overflow and no JS errors.
- Refresh no longer breaks the page.

**Gotcha.** `QueueFake` honours `ShouldBeUnique`: a second dispatch for the same supplier inside `uniqueFor` is dropped. That is intended, so the trigger test uses a second supplier for the drawdown path.

## 2026-11-05 — Released #699: supplier buffer forecast live

**What.**
- `staging` → `main` PR #699, merged on the founder's go-ahead as `d478522e`.
- It ships #695–#698: item 28, the supplier buffer forecast and low-balance email (ADR-115 2026-11-05 addendum); the `/admin/balance` Refresh fix (#697); two docs PRs.
- CI on the PR and on `main` was green on every job, including `backend-concurrency`, `playwright` and `deploy`.
- The box runs release `82151010` at `d478522`.

**Checked read-only after release.**
- Migration `2026_11_07_120000_add_supplier_buffer_alert` `Ran`. The column `suppliers.low_balance_alerted_at` and the index `sle_supplier_type_created_idx` exist.
- `/api/health` 200, Horizon running, and both WhatsApp sessions `ready`.
- `SupplierBufferService::assess()` on Digiflazz:
  - balance IDR 15,363,305 (RM 3,673.81 at the landed rate);
  - forecast IDR 8,716,246, from the busiest window, 30 Oct – 3 Nov;
  - required IDR 10,000,000 (the floor is higher than the forecast);
  - a 5-day window covering until Monday 9 Nov; not short.
- No supplier has an alert claimed.
- The manual suppliers assess to `null`, as designed.
- The four Vercel production deploys succeeded.

**Docs.** PRD §14, §15 (Supplier Management and Balance rows), §16 items 28 and 104 and the line on Error Handling now say released. Item 57's closed line still said "not released yet" after #694; it was corrected. §14 also records CHIP's 2026-11-05 reply on the additional payment methods.

## 2026-11-05 — Item 162 PR 1: package activation seam and the same-reference window

**Why.** Two money gaps found while tracing ADR-137 (Gamevion v2) decision 21, outside Gamevion itself. The stress test showed that the first fix, at two sites, was a patch; the founder accepted the root-cause fix (ADR-015 and ADR-102 2026-11-05 addenda).

**Gap A and B: package activation.**
- Checkout sells any package with `is_active`. Nine places switched a package on by writing `is_active = true, deactivated_reason = null` directly. Each cleared the one stored reason and checked nothing else.
- So a supplier's Reactivate brought back a package with an open price swing (sold at the old cost) or one its supplier no longer lists.
- Approving or dismissing a swing switched on a package that was off because its supplier was off, or because an admin turned it off.
- Prod, read-only, 2026-11-05: none of the 1,210 active API packages is affected.

**Built.**
- `App\Services\Catalog\PackageActivation`:
  - `activate($package, $clears)` and `blocker($package)`;
  - blockers are computed live: an open `PendingPriceChange`, supplier off, unfunded, not listed at the supplier's last sync, a combo component off;
  - a blocked package stays off with that blocker as its reason, so the path that owns the reason brings it back;
  - a caller clears only the reason it owns; `null` is an admin's own switch-on from `/games`.
- All nine writers route through it: Pending Reactivation approve and bulk, the auto-approver, Dismissed restore, swing approve and dismiss, the supplier's Reactivate (and a manual supplier switched on, and the unfunded sync), the `/games` toggle, the two combo cascades.
- The supplier's Reactivate now returns `skipped`, and the admin toast says how many stayed off.
- An invariant test fails CI if other code clears a package deactivation.

**The same-reference window.**
- `max_reconcile_age_days` was enforced by the poll and Check supplier / Confirm Failed, but not by Retry or Resend from NeedsReview, which re-submit the stored reference.
- `Order::sameReferenceWithinWindow()` is now the one rule:
  - `fulfill()` and the combo leg attempt refuse outside it;
  - Retry, Resend and Check supplier on a Pending order answer 422 with `Order::PAST_WINDOW_MESSAGE`.

**Tests.**
- 11 in `PackageActivationTest`.
- 5 added to `OrderConfirmFailedSupplierCheckTest`: Retry, Resend, `fulfill()`, a combo leg, and Check supplier past the window.
- Four tests that built `ComboPricingService` / `PendingReactivationAutoApprover` by hand now resolve them from the container.
- The auto-approver fixture's Digiflazz is MYR: an IDR supplier with no deposit is unfunded, which the seam rightly refuses.
- Full suite 3,666 green. Admin: `tsc` and `eslint` clean.

## 2026-11-05 — Item 162 PR 2: Gamevion v2 adapter, webhook and cutover guard (ADR-137)

**Built.**
- `GamevionAdapter`, rewritten for gamevion.cloud, behind the unchanged `SupplierAdapter` seam:
  - one `Authorization: Bearer gvk_…` key;
  - product reference `{game}:{sku}`;
  - `listProducts()` keeps `gametopup` games whose required `/fields` are `userid`/`serverid` only, paces pricelists 1.6 s apart (40/min limit), and fails whole on any failed call (a partial catalog would switch packages off);
  - `createOrder()` posts with our reference as `client_ref`; a re-submit and `checkStatus()` read `GET /api/orders/{client_ref}` first and post once only on a 404;
  - one mapping, `GamevionAdapter::responseFor()`: `successful`, `pending`, and `error`/`cancelled`/`refunded` as confirmed failures. No-order error codes (400/402/404/422, `price_changed`) are confirmed; 409 `request_in_progress`, 429, 5xx and 401/403 are unknown (401/403 and 5xx count against the breaker).
- `gamevion` joins `RESUBMIT_REPLAYS_OUTCOME`. Its same-reference window is a protocol constant of 30 days (`Supplier::SAME_REFERENCE_WINDOW_DAYS`), so a blank setting cannot fall back to 90.
- `POST /api/webhooks/gamevion` (`GamevionWebhookController`):
  - HMAC-SHA256 of `timestamp.raw body`, rejected past 5 minutes;
  - finalizes a plain order or a combo leg by `client_ref` through `finalizePendingDelivery[Leg]()`;
  - a `refunded` after delivery emails every admin (area "Supplier refund") and changes nothing;
  - `pending` or an unknown status is acknowledged and left to the poll.
- Cutover guard: saving a supplier's `api_config` with a new `base_url` is refused while it has an order or combo leg in Processing, Pending or NeedsReview. A key rotation at the same URL is allowed.
- Config: Gamevion's required keys are `base_url` and `api_key`; `webhook_secret` is optional; `bearer_token`/`sandbox` and the `GAMEVION_*` credential env defaults are gone. The admin form matches.
- `app:gamevion-smoke-test` uses the supplier row: balance, catalog, and with `--order` one order only at a zero balance (expects 402).
- `app:backfill-supplier-credentials` covers Digiflazz only.
- Dead code: `flagStaleDuplicateReferences()` removed. The sandbox's `duplicate_reference` convention stays (ADR-137 PR 2 build addendum).

**Money checks in tests.**
- A delivered Gamevion order records its drawdown and a real cost of RM4.20 = 420 sen at face value (MYR, no transfer).
- A repeated webhook writes one drawdown.
- An `error` writes none.

**Tests.**
- 20 in the new `GamevionAdapterTest`, 9 in `GamevionWebhookControllerTest`, plus the cutover guard and the 30-day window.
- Fixtures that used `gamevion` as a non-replaying or sandboxed supplier now use a generic `legacy-api` supplier or Digiflazz with `testing`, because Gamevion now replays and has no sandbox.
- Full suite 3,675 green. Admin `tsc` and `eslint` clean.
- Local curl: `POST /api/webhooks/gamevion` answers 503 against the dev DB, which has no `webhook_secret` (fail-safe).

**Gotcha.** A test with no `Queue::fake()` on a replay-safe supplier now also runs the recovery poll inline. A fixture supplier with no config then throws from the poll, not the order (`ResellerBotOrderTest`).

## 2026-11-05 — Item 162 PR 3: package repoint in Product Manager (ADR-137 decision 21)

**Why.** When a supplier changes system, its references change. Before this, a package could not be pointed at a new reference: Promote refuses an item that is already a package, and the package update never took `supplier_package_ref`. The only options were a second package beside a dead one, or delete and set up again.

**Built.**
- `POST /api/middleware/supplier-products/{item}/repoint` (`package_id`), super admin, with `RepointSupplierProductRequest`.
- Under a row lock it refuses when:
  - the item has no valid price;
  - the item is already in the catalog;
  - the package is from another supplier, a combo, or a manual supplier's;
  - the item's group is not linked to the package's game;
  - the package has an order or combo leg in Processing, Pending or NeedsReview.
- Otherwise it:
  - records `package_ref_changes` (old, new, admin);
  - closes any open price swing as `superseded`;
  - sets the new reference;
  - sets the cost from the new price through `PackageCostService::apply()` (a `PriceChangeLog` row with the admin; markup kept);
  - recomputes the combos that use the package.
- The package stays on or off as it was. Old orders keep their frozen reference; a resend uses the new one. The old catalog row is no longer protected, so the next sync prunes it.
- Migration `2026_11_08_120000_create_package_ref_changes_table`. On MySQL (docker test DB), `SHOW INDEX` shows a standalone index on `package_id` and `admin_user_id`.
- Admin: "Link to existing" beside "Add to Catalog" opens `RepointProductModal` (`components/ui` kit). It lists this game's packages from the same supplier and shows the current cost against the new price before confirming.

**Tests.** 7 in `SupplierProductRepointTest`. Full suite 3,682 green. Admin `tsc`, `eslint` and 22 unit tests pass.

**Browser check.** On the founder's running dev servers (`:3000`, API `:8000`):
- Product Manager → Arena Breakout (Gamevion, dev data) → Link to existing → pick a package → Link package.
- The POST was intercepted by Playwright, so the dev DB was not written. Its body was `{"package_id": 3}`.
- Checked in light, dark and 390 px: no horizontal overflow, no JS errors.
- The cost comparison showed a deliberately mismatched pick plainly (RM 3.25 → RM 165.24).

**Gotcha.** `php artisan migrate --env=testing` with no `.env.testing` runs against the dev DB. It ran this additive migration there, which lifecycle step 5 needs anyway.

## 2026-11-05 — Released #705: Gamevion v2 live; first sync failed; sync scope fix built (ADR-137 2026-11-05 addendum)

**Released.** #705 (`staging` → `main`, #700–#704) merged 11:28 UTC; the deploy job passed. Read-only checks on prod:
- `0344cf1` is live;
- migration `package_ref_changes` ran (batch 65);
- `/up` is 200;
- `POST /api/webhooks/gamevion` answers 503 "webhook not configured" until the secret is saved.

Gamevion had 71 packages (0 on) and 0 orders. The founder entered the `gvk_` key, Base URL and webhook secret and switched Gamevion on. The balance check passed (RM 0, VIP tier).

**Found.** The first sync (run 3287, 11:47) failed with `[service_unavailable]`. The request log shows ten games fetched cleanly, then `GET /api/games/ace_racer_id/fields` answering 503 on all four attempts. A later check from prod found:
- **Ace Racer, Valorant (generic) and Blood Strike Pass are down at Gamevion.** Both their `/fields` and `/pricelist` answer 503.
- **`/api/games` lists 1,344 games, 430 of them `gametopup`.** At the 40-a-minute pricelist pace that is about 12 minutes, over the 300 s `price-sync` limit (decision 16 put the ceiling near 150).
- **One job ran every supplier in turn under one 300 s limit.** Gamevion (id 1) runs before Digiflazz, which takes 25–79 s a run (7 days of runs).
- **The request log would grow about 7,300 rows a day.** Every catalog call is logged, which is about 550 MB at the 30-day retention. The table is 5.4 MB.

Every `partial` price-sync run in the last 7 days was Gamevion: the old API timing out on 1–4 Nov, then this. The 15 failed jobs (`cs-assistant`, 2026-11-03) were already fixed by `75570048`.

**Built** on `fix/gamevion-sync-scope` (ADR-137 2026-11-05 addendum):
- **Games picker.** `api_config.games` is an optional `list` key.
  - `GET /api/middleware/suppliers/gamevion/games` (super admin) returns `GamevionAdapter::listGames()`: one call, unwrapped, so it never counts against the order breaker.
  - `GamevionGamePicker` in the Edit modal groups the games by Gamevion's family, with a select-all per family, search, and "New" on an unticked game in a ticked family. Built on the ADR-104 tokens.
  - Migration `2026_11_09_120000_seed_gamevion_games_to_sync` writes the 74 agreed codes once, only while `games` is absent.
- **Per-game failure.** `listProducts()` fetches only the chosen games. A game whose `/fields` or `/pricelist` still fails after the retry is left out, with a warning. The whole sync fails on 401/403, a failed `/api/games`, or more than a quarter of the games failing. An empty list fails with "Choose them in Middleware → Suppliers".
- **One job per supplier.** `SyncSupplierPricesJob` dispatches one `SyncSupplierCatalogJob` per syncable supplier as a `Bus::batch` (`allowFailures`, same `price-sync` queue and worker).
  - Each job merges its counts into the run under a row lock (`PriceSyncRun::recordSupplierSynced` / `recordSupplierFailed`).
  - The batch's `finally` calls `finishBatch()`. It counts the suppliers that synced (`stats.synced_suppliers`), never the failures recorded so far, because a timed-out job's `failed()` may run after it.
- **Request log.** `SupplierRequestLogger::attach(..., failuresOnly: true)` for Gamevion's per-game catalog calls.
- **Batch rows.** `queue:prune-batches --hours=168` runs daily (`job-batch-pruning`, routine tier), so `job_batches` keeps a week (48 rows a day).

**Tests.**
- New: 9 adapter tests, 4 controller tests, 3 job tests, 3 migration tests.
- Changed: 15 job tests now go through `dispatchSync`, so the batch runs inline.
- Full suite: 3,701 green.
- Admin: `tsc`, `eslint` and 22 unit tests pass.

**Browser check** on the founder's dev servers (`:3000`, API `:8000`). The local proxy is on, so the picker endpoint was answered by Playwright with prod's real 430-game list; the endpoint itself is covered by the controller tests.
- Light and dark: 290 families, 74 ticked, "New" on Valorant and Blood Strike Pass.
- Ticking a family keeps it in place and doesn't toggle its `<details>`.
- Contrast: code text 5.3 / 5.8, the "New" tag 6.1 / 8.3.
- Save sent `api_config.games` with the codes.
- The error state shows "Gamevion did not return its games: …".

**Gotcha.** A checkbox inside `<summary>` doesn't toggle the `<details>` (the input is the activation target), so no `stopPropagation` was needed. Re-sorting families by live tick state made a ticked family jump under the cursor; the order now comes from the list the form opened with.

## 2026-11-05 — Deleting a game can no longer remove used packages (ADR-119 2026-11-05 addendum)

**Found** in the founder's stress test of catalog management (read-only on prod):
- `GameController::destroy()` was a plain `$game->delete()`.
- The schema then cascaded to every package of the game (`packages.game_id` CASCADE), past ADR-119's guard in `PackageController::destroy()`.
- It nulled the orders' `game_id` and `package_id` (SET NULL) and deleted price, deactivation and repoint history.
- Prod's foreign keys match the migrations. 10 of 40 games have orders (102 orders).
- Order #7 (a combo, 2026-09-15) already has no `package_id`: its package was deleted before the 2026-10-02 guard.

**Built** on `fix/game-delete-integrity`:
- Migration `2026_11_10_120000_restrict_deleting_used_games_and_packages`: `packages.game_id`, `orders.package_id` and `orders.game_id` → RESTRICT.
  - On sqlite, the llm_report_* views are dropped and re-created from their stored SQL around the table rebuild, as `2026_10_08_140000` does.
  - On MySQL (docker), the three rules read RESTRICT afterwards.
- `GameController::destroy()` refuses with "This game has order history and cannot be deleted. Deactivate it instead." when an order points at the game or one of its packages.
  - Otherwise, in one transaction it deletes combos, then the other packages, then the game. Product Manager groups are unlinked.
  - A racing order hits the restrict, rolls back, and gets the same 422.
- Three tests that deleted a used game or package on purpose now use the legacy null-link shape, or assert the refusal.

**Tests.** 4 new (3 controller, 1 database-level package delete). The 4 deletion tests also pass on MySQL. Full suite: 3,704 green.

**Browser check** (founder's dev servers, local DB): Arena Breakout (3 orders) → Edit Game → Delete Game answered 422 and showed the message in the modal. Its 4 packages are still there.

**Gotcha.** The MySQL test run first failed with `llm_report_orders already exists`: views were left in docker `kerox` by an earlier killed run (`backend/AGENTS.md` gotcha 8). Dropping them fixed it.

## 2026-11-05 — Released #707: Gamevion sync scope fix live; first sync clean; #708 merged to staging

**Released.** #707 (`staging` → `main`, #706) merged 13:40 UTC (`ba9a2c6f`), on the founder's go-ahead. CI and the `deploy` job were green, and the box runs release `82204655`.
- Migration `2026_11_09_120000_seed_gamevion_games_to_sync` ran (batch 66). `api_config.games` holds 74 codes; the `gvk_` key was untouched.
- `queue:prune-batches --hours=168` is in the schedule (daily). `/up` and `/api/health` answer 200.

**First run after it** (3293, started by the founder at 14:16 UTC):
- `success`, with `synced_suppliers` [gamevion, digiflazz] and no failed supplier.
- Batch `price-sync-run-3293`: 2 jobs, 0 failed.
- Gamevion synced 74 games and 1,541 products in about 2 min 40 s. Digiflazz followed in 17 s.
- Gamevion wrote one request-log row (the game list); none of its games failed.
- 116 old unpromoted Gamevion rows were pruned; the 71 old package references are kept.
- The 11 packages deactivated in the run are all Digiflazz's (its usual flapping). Gamevion still has 0 packages on.

**Match list for the 71 old packages** (read-only on prod, given to the founder):
- MLBB Malaysia (38) → `mlbb_sgmy`: 33 diamond denominations; Weekly Diamond Pass → `Weekly Pass`; First Topup 150+150 and 500+500 → the same First Recharge items. First Topup 50+50 and 250+250 match only `First Recharge 50 + 5` / `250 + 25`; to be checked on Gamevion or deleted.
- Free Fire Malaysia (11) → `freefire_mysg`.
- Honor of Kings (10) → `hok` (`800 Tokens` … `8000 Tokens` by name).
- PUBG Mobile Global (6) → `pubgm_global` (60 UC RM3.66 → RM4.00).
- Valorant Malaysia (6) → `valorant_my`.

Before a repoint, the five Gamevion groups must be linked to their games in Product Manager.

**#708** (a used game or package can't be deleted by any path, ADR-119 2026-11-05 addendum) merged to `staging` after green CI (`7ea13ddb`). Not released.

**Docs audit** (`docs/cleanup-2026-11-05`), each fact re-checked against prod read-only:
- PRD §14 headline: Gamevion's state, storefront counts (67 paid from 28 emails; OhahaStore 39), the second KOL campaign ("Mik4ca", code `4ARMY`, 31 clicks across both codes, no redemption), the latest release (#707) and what is on `staging` (#708).
- PRD §6 Gamevion row and ADAPT-4's example now describe the v2 API.
- §15 "not released" labels for #705 and #707 corrected, and `.batalbaki` (live since #660) no longer says "on `staging`".
- §16:
  - item 162 is now only the founder's steps (moved to group C);
  - 163 is next (moved to A);
  - 165 added: a KOL paid as a share of profit isn't supported, and the interim way to pay one is "Not a KOL fee".
- `backend/AGENTS.md` (Gamevion's state, the replay-safe suppliers, the smoke test) and `foundation-security.md` (Gamevion's quirks) corrected.
- ADR-031 and ADR-051 banners and index rows now note what ADR-137's addendum revised.

## 2026-11-06 — Item 163 seam audit reported; ADR-138 Accepted (design only, no code)

**#709 merged to `staging`** (`92acb151`), on the founder's go-ahead. #708 is still unreleased, so on production deleting a game can still cascade to used packages.

**Seam audit (item 163).** Three read-only forks, one per layer (order and delivery, stored value, pricing and catalog). Claude re-checked the top findings in code and read production; no test was run. Production: 104 orders, 3 combos, none in `needs_review`, no active combo with an off component, no affected voucher.
- **A.** A price swing switches a component off but leaves its combo on sale.
- **B.** A Paid after a Failed on a voucher order has no exit. Downgraded, see below.
- **C.** Combo legs: reference not durable before the supplier call, `NotStarted` rolled up as partial, combo decided two ways.
- **D.** Voucher merge ignores reserved redemptions; a restore onto a merged or revoked voucher is stranded.
- **E.** Approve writes a stale frozen price.
- **F.** New packages start on sale with no check.
- **G.** An API supplier off keeps selling but stops syncing.
- Six small items are PRD item 170. Ledger writes, wallet debits, withdrawals, the selling price formula and webhook-versus-poll finalization came back clean.

**B was overstated.** The founder asked whether it can really happen. CHIP's spec says `cancelled` and `expired` can't be paid, and `single_attempt` ends a purchase after one failed attempt. Production: 19 failed orders, none with a voucher, none in the 41 settlement rows; CHIP reads 8 `cancelled`, 6 `expired`, and 5 lookups gave 404 (cause unknown); no "Paid after a failed" in logs back to 2026-10-18. The one open path is a customer already at the bank when a purchase is cut off, which CHIP's documents don't cover. ADR-024 decision 10's rule stands: add a guard if it ever happens once. The founder may ask CHIP.

**Grill 1, ADR-138.** A, E, F and G are one problem: nothing owned switching off or creating a package. The founder challenged manual price approval (a rise can't lose money), which led to the direction rule. Production history backed it: package 412 fell 49.5% to RM4.79 for 3.5 hours, passing the 50% guard, while package 1538 sat off 7 days at a correct price.

**The stress test changed the design.** Three forks broke the first version:
- Removing the guard would have let one mistyped transfer reprice the catalog after 24 hours, worse than today, because ADR-134 decision 16 relied on it. Hence the breaker.
- An open row could stick when the price returned to exactly the held cost, then let a later false drop through at once.
- The hold now records the highest price seen, and Approve's live-price refusal was dropped as useless.

Measured for the breaker: at most 3 packages at 30% or more per run, no rise of 30% or more reversed within 24 hours, and the 2026-11-01 landed-cost run moved 1,399 packages at a median of 6.76%.

**Next:** build ADR-138 (item 166), then Grill 2 (item 167), Grill 3 (item 168), `max_price` (item 169).

## 2026-11-06 — Item 166 PR 1: one door for switching a package off (ADR-138)

**Why.** Seam A: a price swing switched a component off and left its combo on sale at the old cost, with no price cap sent to the supplier. Every off path also recorded something different.

**Built.**
- `PackageActivation::deactivate()` and `deactivateMany()` write the reason, the `DeactivationLog` row and the combo cascade in one transaction. The bulk form stays a few statements.
- The cascade moved out of `ComboPricingService`, which already depended on `PackageActivation`. `cascadeReactivate` stays there because it recomputes.
- Sync's two off paths, `flagPriceAnomaly`, `SupplierPackageStatusService::deactivate()` and the admin toggle all go through the door.
- An admin switch-off now stores reason `admin`, a time and a log row, so it reaches Dismissed.
- Migration `2026_11_11_120000` gives the same reason to packages off with none, leaving the Joki pool out. Sale state doesn't change. On prod 2026-11-06 that selected 85 Digiflazz rows, with 8 pool rows left out.
- The Dismissed list filters on `is_active = false`; a combo row reads "Combo" (it has no supplier ref).

**Tests.** `PackageSwitchOffTest`: the seam, a failed write changing nothing, the bulk form, swing to combo off to Approve back repriced, the backfill, every off path leaving no combo on sale, and a static check for writes outside the seam. Tests of the old cascade methods moved there.

**Gotcha.** `Package::create()` without `is_active` leaves it null in memory (the default lives in the database), so `deactivate()` sees it as off and returns false. Three combo tests hit this. PR 3's creation change has to pass `is_active` explicitly, as ADR-138 decision 16 says.

## 2026-11-06 — Item 166 PR 2: price swing rules by direction and the mass change breaker (ADR-138)

**#711 (PR 1) merged to `staging`** (`884103ff`) after green CI. Not released, by the founder's choice, and neither is #708. The founder Approved package 412 first: read-only on prod it shows approved, on sale, cost RM9.49, with no open row and no `price_anomaly` package left.

**Built.**
- `evaluatePriceChange()` goes by direction. A rise applies at once. A drop of 30% or more opens one row and the package keeps selling at the old cost. The row keeps the highest price seen and applies it after 24 hours as `auto_applied`.
- A price back in range, a rise, or a product not seen active closes the row as `superseded`. The check runs before the `price_sen !== cost_price` gate, which would skip a price back at exactly the held cost.
- `isMassChange()` counts a supplier's swings before the loop. Over the limit, both directions are held, auto-apply stops, and one email goes out per Malaysia day.
- Row work runs under the package lock on a freshly read package, and `approveHeld()` shares it.
- `PriceSwingPolicy` reads three new per-supplier fields in the Edit modal.
- Removed: the pending-row blocker, Dismiss for price changes, `price_swing_threshold_percent`. A `closeRow()` branch brings back any package a swing flagged before release; delete it once production shows none.
- Price Sync Center: the section is now "Held Price Changes", with a signed percent and "Approve all" per supplier behind a confirm dialog.

**Tests.** `PriceSwingRulesTest` (21). Tests pinning the old rule were deleted or moved to a drop case, including `PendingPriceChangeConcurrencyTest`.

**Verified:** `php artisan test` 3,723 of 3,723; `PendingPriceChangeConcurrencyTest` on local MySQL; admin `tsc` and `eslint` clean on the touched files. In a real browser on the local admin, with temporary rows removed afterwards: the section, signed percents, the confirm dialog and Approve all changing the rows and costs.

**Gotchas hit.**
- The browser check caught what the API tests couldn't: the first `approveAllPendingPriceChanges` passed `JSON.stringify(...)` to `apiFetch`, which stringifies again, so the server answered "supplier id field is required".
- Local Approve all then answered 500 "cache store does not support tagging" (`backend/AGENTS.md` gotcha 5: local cache is `database`, prod is `redis`, read-only checked), after the rows had applied. That showed the list could go stale on a part failure, so the page now refreshes in `finally`. The final cache forget therefore hasn't run in a browser on Redis; it's the same call single Approve makes.

## 2026-11-06 — Item 166 PR 3: new packages, Promote and repoint (ADR-138)

**#712 (PR 2) merged to `staging`** (`72faf935`) after green CI. Neither PR 1, PR 2 nor #708 is released, by the founder's choice.

**Why.** Seam F: a new package started on sale with no check, Promote trusted the `game_id` the UI sent, and repoint could move a package with orders still to be sent.

**Built.**
- `PackageActivation::activateNew()`: Promote, `storeCombo` and `storeManual` create off, then pass through `activate()`. No blocker means on sale, so the normal flow is unchanged. Joki's creation paths stay outside.
- A combo activates after attach and recompute, inside the transaction. One that stays off is still priced.
- Each response carries `notice`, and the admin shows "Added, but not on sale" with the reason.
- Promote takes the game from the product and refuses a product with none. The admin no longer sends `game_id`.
- Repoint refuses a product that isn't active, and an order paid or awaiting payment that hasn't been sent, including a combo order holding the package. Failed payments and finished deliveries don't block.

**Tests.** `PackageCreationTest` (9) and four new repoint tests. Promote's old refusal tests now give the product a game, or they would pass on the missing game instead of what they name.

**Verified:** `php artisan test` 3,735 of 3,735; admin `tsc` and `eslint` clean on the touched files. In a real browser on a built admin, with temporary rows removed afterwards: Promote under a switched-off supplier (package off, `supplier_issue`, warning shown, no `game_id` sent), Promote with no blocker, and a combo whose component went off while the modal was open. The manual warning was not seen in a browser: the modal lists active suppliers only, so it is covered by the backend test.

**Gotchas hit.**
- `backend/AGENTS.md` gotcha 5 again: the first browser run looked like a missing toast, but the cache forget had answered 500 after the package was saved. `CACHE_STORE=array php artisan serve --no-reload` avoids it without Redis.
- `:3000` was held by an unrelated process, so the admin ran on `:3010` with Playwright routing `localhost:3000` to it, which keeps the session cookie valid. Chromium then needs `--disable-features=LocalNetworkAccessChecks`.
- A test helper named `component()` is a fatal error: Laravel's test case already has one.

## 2026-11-06 — Item 166 PR 4: the supplier switch (ADR-138)

**#713 (PR 3) merged to `staging`** (`66a77f9b`) after green CI, on the founder's go-ahead.

**Why.** Seam G: an API supplier switched off kept selling but stopped syncing, so its packages sold at costs that grew older. On prod 2026-11-06 DigiFlazz has 1,195 packages on sale, so this switch now stops most of the store, which is why it asks first.

**Built.**
- Switching an API supplier off writes the flag first, closes its open price rows, then takes its packages and combos off through the seam as `supplier_issue`.
- Switching it on restores nothing. A manual supplier stays automatic both ways (ADR-125 decision 64).
- Reactivate is refused until the supplier has synced within its limit (default 90 minutes).
- `/middleware/suppliers`: Deactivate has a confirm dialog with the counts, and both dialogs say how packages come back.
- The stale limit moved from `CheckPriceSyncStalenessCommand` to `Supplier::priceSyncStaleMinutes()`, so the email and Reactivate read one setting.

**Tests.** `SupplierSwitchTest` (9). `PackageActivationTest`'s supplier now has a recent sync.

**Verified:** `php artisan test` 3,744 of 3,744; admin `tsc` and `eslint` clean on the touched files. In a real browser on a built admin, with temporary rows removed afterwards and the database read at each step: the dialog's counts, off (package `supplier_issue`, combo `combo_component_deactivated`), on (both stay off), Reactivate refused with a sync five hours old, then allowed after a fresh one (both on). The count line's wording was changed after that run, and only `tsc` and `eslint` saw the new text.

**Open.** A single package switched on after a long stop has no freshness check (ADR-138 PR 4 build notes). For the founder to decide.

## 2026-11-06 — Released #715: package sale lifecycle live; Gamevion key rejected; Dismissed list fixed

**#714 merged to `staging`** (`47d7bd61`) after green CI. **#715 released** on the founder's go-ahead: merge `e2a00fcb`, CI and `deploy` green, box release `82286819` at `e2a00fc`.

**Checked read-only before release:** the three foreign keys under their expected names, the app user's ALTER and REFERENCES, the backfill's 85 rows (all DigiFlazz), and nothing open: 0 price rows, 0 `price_anomaly`, 0 orders in flight, 0 running syncs.

**Checked read-only after release:** both migrations `Ran` (batch 67), three delete rules RESTRICT, 85 rows with reason `admin`, `/api/health` 200, Horizon running, 0 active combos with an off component, 0 open price rows, 0 orders in flight.

**Package count 1,277 to 1,265, not from the release.** Run 3336 brought 2 back and run 3338 took 14 Free Fire Malaysia off, all `status_raw` inactive from DigiFlazz and still inactive at 13:00 UTC. The same flapping ran before the release (2 in run 3317, 7 in 3327; 37 in one run in October).

**Gamevion answers 401 `Invalid access token`.** `GET /api/games` returned 200 at 00:30:06 UTC on 2026-11-06 and 401 at 01:00:08, on every run since, ten hours before this release. The stale alert was set at 02:15 UTC. Nothing on our side deployed then, but no table records a credential change, so the cause is unknown. No customer impact: 0 packages on, 0 orders. The founder is generating a new key. An earlier recap this session called Gamevion syncing, from docs, without checking; it wasn't.

**Dismissed list bug, found by the founder.** "There's no Call of Duty there." The backfill gave all 85 rows one `deactivated_at` and the list sorted on that alone, so MySQL's pages overlapped: 43 unique packages over five pages, 28 of them twice, no Call of Duty. Fixed with `id` as a second sort. SQLite doesn't show the overlap, so the new test pins the order, and the new query ran read-only on production: 87 unique, no duplicates, all 42 Call of Duty (2 more had been dismissed by then). Call of Duty Mobile is off as a game, so restoring those packages sells nothing until the game is on.

**Also in that PR:** `closeRow()` and `PRICE_ANOMALY` removed now production holds none, and tests that still set a held package off moved to it staying on sale. `DismissedPackageController`'s comment no longer says a restore ignores the supplier.

**Verified:** `php artisan test` 3,743 of 3,743.

**Gotcha.** Printing even a credential's prefix or length through tinker was refused in this session, rightly. Diagnose a supplier's auth from `supplier_request_logs`' status codes.

## 2026-11-06 — Released #717: Dismissed list fix live; Gamevion syncing again

**#716 merged to `staging`** (`06826a8d`), **#717 released** on the founder's go-ahead: merge `73008972`, CI and `deploy` green, box release `82305761`. No migration, no env change.

**Checked read-only after release:** the real controller's pages list 87 packages, each once, 42 of them Call of Duty; `closeRow()` and `PRICE_ANOMALY` are gone; `/api/health` ok; 0 open price rows; 0 active combos with an off component.

**Gamevion recovered.** `GET /api/games` still answered 401 at 13:57:20 UTC, `/api/balance` answered 200 at 13:59:17, and run 3341 at 14:00 UTC read `success` with both suppliers synced and `price_sync_alerted_at` cleared. That matches the founder saving a new key; the cause of the first 401 stays unknown.

**Packages on sale 1,265 to 1,248.** An admin switched two Mobile Legends Malaysia packages off at 13:20:36 UTC, and five combos went with them. Run 3340 then took 11 DigiFlazz packages off, Valorant among them, reported unavailable. That is 18 off; the net of 17 leaves one back on, with no reactivation log, so it was not through sync.


## 2026-11-07 — Item 167 designed: ADR-103 addendum Accepted (design only, no code)

**Trigger.** The founder asked to double-check item 163's seam C before fixing it. Combo volume had also changed: 6 combo orders, 3 of them on 2026-11-06, and an admin Retry on orders 111 and 121 that day.

**The audit undersold it.** Proofs run from a scratch test, repo untouched, found two paths it missed: a killed retry of a `Failed` leg loses its fresh reference, so a leg can be bought twice; and a webhook during the pass marks a fully delivered order partial, with RM0 settlement and a stuck leg. No production harm: all 15 legs delivered, and no worker-stop line in the retained logs.

**Grill, Q1 to Q7.** Leg `Processing` committed before the call; a dead pass parks legs and polls; a never-tried leg waits for an admin; one rollup; `Order::isCombo()`; drawdown left as ADR-083 decided (a crash window, not a seam: one writer, 89 of 89 delivered units recorded, variance 0); a past-window combo sends nothing.

**The stress test changed the design.** Three forks broke the first version:
- All three found the "wait while unfinished" rule stranded a dead pass at `Processing`, which can't be retried or settled. Now `Processing` always means a live pass, and the reconciler moves the order out before rolling up. Two forks proposed a flag; the order's status already carries the fact.
- The agreed leg lock deadlocks with a webhook once the reference is visible during the call (`ERROR 1213` on MySQL 8.4, in replica tables). Order then leg, as `fulfill()`.
- The reconciler couldn't write the attempt row correctly, so the row is written at the claim.
- An empty leg set counted as all delivered, with 1,500 sen credited in a test.
- `isCombo()` needed the admin payload and two `page.tsx` checks too.

**Next:** build it from ADR-103 decisions 10 to 17, tests first.

## 2026-11-07 — Item 167 built: combo leg safety (ADR-103 decisions 10 to 17)

**Built on `fix/combo-leg-safety`, into `staging`, not released.** No migration, no env change.
- A leg commits its reference, `Processing` and a `pending` attempt row before the supplier call (`claimLeg()`), then makes the call under order then leg locks.
- `DeliveryStatus::comboRollup()` is the one rule, and `confirmComboLegsFailed()` uses it too. A webhook during a pass records its leg and decides nothing.
- `recoverDeadComboPass()` is the reconciler's way out for a stale combo: it parks legs through `parkUnknownLeg()`, the same rule as the catch block, moves the order out of `Processing`, then rolls up.
- A combo past the same-reference window starts no pass, and Retry refuses it from any status.
- `Order::isCombo()` replaces the `package?->is_combo` reads, and the admin payload carries `is_combo`, so a paid combo with no legs can't be marked delivered.

**Verified.**
- `php artisan test` 3,776 of 3,776.
- Concurrency suite on real MySQL: only `BackupRestoreMysqlTest` fails, the known local gap (no `mysqldump`).
- The three new real-process tests (SIGKILL during a first attempt, SIGKILL during a retry, a webhook racing the leg in flight) fail on the old service and pass on the new one.
- Admin `tsc` and `eslint` clean, admin unit tests pass.
- In a real browser on a throwaway backend: a combo with no legs shows Retry Delivery and Confirm Failed, with no Mark as Delivered; a `processing` leg shows in the breakdown; a plain order keeps Resend Delivery and Mark as Delivered.

**One existing test changed:** `test_a_combo_leg_past_the_window_is_not_re_submitted` became `test_a_combo_past_the_window_sends_nothing`, the decision itself. No other existing test needed changing.

**Open at release:** the release needs no prod step. Confirm afterwards that no leg sits at `Processing` for long.

## 2026-11-07 — Item 168 built: a voucher in use can't be closed (ADR-036 2026-11-07 addendum)

**#720 merged to `staging`** (item 167, not released; the founder wants several items done before a release).

**The hole, proven.** A RM100 voucher with RM40 reserved, then merged or revoked, then the order fails: the RM40 lands on a voucher nobody can spend, and Cash Position and breakage both miss it. Production untouched: 0 reserved, one merge, 0 revokes.

**Built on `fix/voucher-close-guard`, into `staging`, not released.** No migration, no env change.
- `merge()` and `revoke()` refuse a voucher an order is using, and the message names both.
- A no-expiry source keeps the merged voucher without one. The founder asked whether expiry should go and giveaways move to Campaign; the check showed Campaign can't do a fixed-RM gift to a named person, so the line is: customer money never expires, gifts may, public discounts are Campaign.
- The panel hides "Valid until" when the rule applies.
- `/admin/vouchers`: Total Issued and Total Used count a merge once. They were overstated by RM460.42 each, display only.

**The stress test changed the design.** Two forks independently found the agreed locking read deadlocks with `restore()` (`ERROR 1213`, on replica tables), so it is a plain read after the voucher lock. Also: a revoke refusal would have been a 500, the "settle" wording was wrong for an unpaid order, and "Valid until" would have refused every date on today's vouchers. Two claims made to the founder were corrected: a refused revoke does leave the unreserved balance spendable, and Campaign covers the primary brand only (item 171).

**Verified.**
- `php artisan test` 3,785 of 3,785.
- Concurrency suite on real MySQL: 44 of 48, only `BackupRestoreMysqlTest` fails (no local `mysqldump`). It includes a new real-process race of `redeem` against `merge`.
- Admin `tsc`, `eslint` on the changed file, and 22 of 22 unit tests.
- In a real browser on a throwaway backend: "Valid until" hidden for no-expiry vouchers and kept for gifts; a revoke of a voucher in use shows the message naming the order, and the voucher stays active.

**One existing test changed:** `test_a_merge_uses_the_same_end_of_day_rule` now merges vouchers that have an expiry, because the old setup is what this rule refuses.

**Open at release:** confirm no reservation sits on a merged or revoked voucher.

## 2026-11-07 — Item 172 built: a game link belongs to the group (ADR-067 2026-11-07 addendum)

**Found.** The founder's first repoint (item 162) failed: `valorant_my:1000` to `1000 VP`, "This item's group is not linked to the package's game", and the group had no Link button. Confirmed in the live admin through Chrome (read-only, Link package not pressed). `Valorant (Malaysia)` held 6 old items linked since September and 6 new ones with no game; `categories()` calls a group linked if any row is, and the admin hides Link then. Release #705 didn't link anything.

**The audit (read-only prod).** Every package on sale sits in the right region's group, no duplicate references, no package on sale against an inactive item. 3 Gamevion and 5 Digiflazz groups were half-linked (217 rows, including 58 on-sale packages' rows, harmless: selling never reads the link). `MOBILE LEGENDS — Umum` showed as linked to Malaysia on the strength of one stale row, and the founder says it is Indonesia stock. Separately, Gamevion's catalog is shrinking (item 162) and its pricelist answers 503 (item 173).

**The stress test changed the design.** First proposal: a "link the rest" button plus a reactivation guard. Rejected at the founder's request to test it: every sync and returning item would bring the half-linked state back, and the guard protected one package. Trace showed one writer and three readers, all in `SupplierProductController`, so the link moved to the group. Simulated on prod data first: 49 groups, 0 with two games, 217 rows gain a link, 0 packages disagree, no case variants. The founder then asked what a change-game button would risk: a free one strands packages on the old game, so a group changes game only while no package would be left behind.

**Package 516.** The founder pointed out it can't be deleted, it has their failed test order. It isn't: only its reason moves to `admin`, the same write as Dismiss in Pending Reactivation, and the migration test proves the order row is identical afterwards.

**Built on `fix/group-level-supplier-link`, into `staging`, not released.** One migration, `2026_11_12_120000_move_game_link_to_supplier_groups`, which also drops `supplier_products.game_id`. No env change.

**Verified.**
- `php artisan test` 3,799 of 3,799.
- Concurrency suite on real MySQL: 44 of 48, only `BackupRestoreMysqlTest` fails (no local `mysqldump`).
- Migration and rollback on a throwaway real MySQL database: the em-dash label matched, Umum to Indonesia, 516 to `admin` with its date kept, unique and `game_id` indexes present.
- Admin `tsc` and `eslint` on the changed files clean, 22 of 22 unit tests.
- In a real browser on a throwaway backend: the founder's exact repoint answered 200 with a success toast and the package stayed off; a group with packages shows only View, one with none shows Change with its current game selected, an unlinked one shows Link.

**Gotcha.** sqlite refuses the table rebuild under the `llm_report_*` views, so the migration reuses `underViews()` from `2026_11_10_120000`.

**Open at release.** Check on prod: 49 links, Umum on Mobile Legends Indonesia, 516 in Dismissed, then the founder's repoint. Product Manager can error for the seconds between the migration and the release switch.

## 2026-11-07 — Released #723: combo leg safety, voucher close guard, group game link

**Released on the founder's go-ahead.** `main` `88edcdea`, box release `82355688`, CI and `deploy` green. #722 merged to `staging` first. Before release, read-only: 0 orders in flight, 0 in `needs_review`, 0 legs at `Processing`, 0 reservations on a merged or revoked voucher.

**Checked after release.** Migration `Ran` (batch 68). 49 group links, the number simulated. `supplier_products.game_id` gone. `MOBILE LEGENDS — Umum` on Mobile Legends Indonesia. Package 516 `admin`, its date, game and reference kept; order `PG-CGDZOLEHAIR8` unchanged; the only package off its group's game. `/api/health` ok, Horizon running. In the live admin through Chrome: Umum and Valorant (Malaysia) show as expected. The 36 `ERROR` lines in the log tail are all from before the release (Gamevion's 401 on 6 November, `CsGameInfo` on 3 November). Items 167, 168 and 172 closed.

**Gamevion's catalog came back by itself.** `/api/games` read 320 from 03:00 UTC and 1,315 at 05:30; new-API rows are 1,164, PUBG's 35 included. So the morning's drop was on Gamevion's side and temporary, and the earlier reading that PUBG had left the catalog was wrong. With prune deleting un-promoted rows, this is exactly the flapping the group link makes harmless for links; it is still open for packages (item 173).

**Not yet seen:** the founder's repoint on prod.

## 2026-11-07 — Item 175 built: KOL attribution gaps (ADR-118 V21 to V25)

**Why.** The founder asked whether every KOL order is really recorded, since reports can only use what the order and the ledger hold. The store was sound: every storefront checkout stamps through one path and profit reaches the order through the ledger. The stress test the founder asked for then found more than the one hole already named:
- an order placed before the first delivery was never stamped;
- a partly delivered customer belonged to nobody, though the new-customer rule treated them as existing and the discount they kept was charged to the KOL (item 170's finding, closed here);
- two matching redemptions had no defined winner;
- ending a campaign left its fee accruing, in the month close too.

**Built on `fix/kol-attribution-gaps`, into `staging`, not released.** No migration, no env change.
- `PromoCodeRedemption::kept()` is the one rule; `attributionFor()` and the scorecard read it, oldest first.
- `claimLaterOrders()` fills empty stamps after the delivery commits, so it holds no other order's row inside a delivery. A stamp with a value is untouched.
- `PATCH /campaigns/{id}` with `ended: true` needs `last_fee_month` while the fee is open, one transaction, through `setEndMonth()`.
- Order details always shows Promo Discount, and "Counts for KOL". The campaign page lists its customers, adding up to the scorecard's all-time sales.

**Verified.**
- `php artisan test` 3,809 of 3,809; promo and voucher concurrency tests on real MySQL 10 of 10.
- Admin `tsc`, `eslint` on the changed files, 22 of 22 unit tests.
- In a real browser on a throwaway backend, through the real `commit()`: a first order, a later order and a plain order each show the right rows; the customer row shows RM 36.32 on both sides; ending a campaign asks the month, answers 200, and the fee row shows November to November.

**Not proven on prod:** no code has been redeemed yet, so the first real redemption needs checking in the database.

## 2026-11-07 — Items 170 and 174: small findings, one seam each

**Item 174 closed, no code.** The three packages are `inactive` at DigiFlazz with streak 0 (read-only prod), so nothing holds them. After sync 3375, 0 packages were off with a streak of 6 or more; Twilight Pass came back by itself. The morning's "past 6 syncs" can't be rechecked: the streak keeps no history.

**Item 170, each finding re-verified first.**
- **Sandbox order:** Issue Voucher had no `is_test` check. `settle()` now refuses one, so every compensation path is covered; `storeFromOrder()` answers 404 like its siblings. Prod: 1 sandbox order, 0 vouchers.
- **Member above guest:** not reachable. Membership only runs on owned brands, which have no tier. Prod, 1,293 packages, both plans: member cheaper on all. `membershipEnabledEffective()` now checks `is_owned`, as Joki does, so a stale switch can't create the case. No pricing rule added.
- **Wallet-refund SQL:** Reports had its own stricter copy. `Order::walletRefundSql()` now has the owner check, and Reports calls it. Prod: 4 rows, 0 different.
- **Profit formula:** six places spelled it out. All go through `Order::residualProfit()`, with a test that fails on a new copy.
- **Same-reference window:** kept, recorded in ADR-102. It can only refuse, never buy twice.

**Gotcha: a wrong finding of ours.** A read-only simulation first reported OhahaStore's member price 7 sen above guest on one package. It called `wholesaleTierMarkupPct()` directly and so read a leftover "Test Tier" row, which `storefrontPriceTerms()`, the real seam, ignores for an owned brand. Real orders showed the standard price. Simulate through the seam, and compare with a real order, before reporting a number. `wholesaleTierMarkupPct()` is now removed: its one caller, `storefrontPriceTerms()`, holds the lookup, so a brand's tier can't be read past the owned-brand check.

**Tests changed.** Three fixtures sold membership on a third-party brand, which ADR-061 forbids; they are now owned. `test_a_test_order_never_messages_anyone` used Issue Voucher to reach the message code; it now calls `CustomerNotificationService` directly.

**Verified.**
- `php artisan test` 3,814 of 3,814.
- Report tests on real MySQL 62 of 62.
- Concurrency suite 44 of 48, run before the method was removed, which touches no locking; only `BackupRestoreMysqlTest` fails (no local `mysqldump`).
- A read-only prod check of each finding, above.

**Built on `fix/item-170-small-findings`, into `staging`, not released.** No migration, no env change.

## 2026-11-07 — Items 169 and 173 decided: no grace period, `max_price` parked

**Item 173, closed.** Two tests through `SyncSupplierCatalogJob` prove what the PRD had wrong. A package skipped on a 503 keeps its streak, because its row isn't touched, so it is back on the next good sync, about 30 minutes later. Only Gamevion's own `inactive` resets the streak and costs 6 syncs. The founder chose no grace period: it would sell at an unconfirmed price to save 30 minutes.

**Item 169, parked with a trigger.** The founder challenged the need: a selling price follows cost, so where is the loss? Production agreed. 1 loss in 86 delivered orders, 13 sen, on `PG-HMMEGKPYVN8K`: two failed attempts, then an admin's resend after the cost rose from RM17.94 to RM18.61, which `max_price` wouldn't have stopped. Of 67 orders with a real cost, 4 were above the stored cost, at most 18 sen, and none lost money through it. The design and the trigger are in ADR-137's 2026-11-07 addendum.

**Docs corrected.** The PRD said DigiFlazz doesn't document going over the limit. Its response codes have `rc 69`, Gagal, no transaction formed. Its test cases cover neither `rc 69` nor `max_price`, so the sandbox can't prove it.

**Verified.** `php artisan test` 3,816 of 3,816. Tests and docs only, no product code.

## 2026-11-07 — Item 163's last two inputs: Link dialog no longer edits a live game's checkout

**Why.** The audit report never answered two of its inputs.
- **A package can't move games:** deliberate, and enforced at the link and the repoint. On prod 1 of 1,477 packages differs from its group's game: package 516, by decision.
- **A group link merges the game's rules:** the merge is safe and `linkCategory()` is the one writer. The dialog's "Checkout input needed" dropdown, though, edits the whole game. Changing it while linking a Gamevion group could stop checkout asking for a server ID on live DigiFlazz packages.

**Built on `fix/link-modal-keeps-checkout-input`, into `staging`, not released.** Admin only, no migration, no env change. For an existing game the dialog shows the rule as text and sends no `validation_rules`; a new game keeps the dropdown. The Checkout Input tab is the one place an existing game's rule changes.

**Verified.** In a real browser on a throwaway backend: no dropdown for an existing game, the POST body carried only `supplier_id`, `group_label` and `game_id`, the link answered 200, the game stayed on `server_id`, and New game still shows the dropdown. A new backend test covers a link with no rules. `php artisan test` 3,817 of 3,817; admin `tsc`, `eslint` on the changed files, build, 22 of 22 unit tests.

## 2026-11-07 — Released #728: KOL attribution, item 170, Link dialog

**Released on the founder's go-ahead.** `main` `0df8b387`, box release `82362619`, CI and `deploy` green. Before release, read-only: 0 orders and 0 legs in flight, no migration in the diff. Ships #724 (item 175), #725 (item 170), #726 (items 169 and 173 decided) and #727 (Link dialog).

**Checked after release, read-only.**
- `/api/health` ok and `/api/health/whatsapp` 200. No `ERROR` in the log tail since the deploy.
- `Order::residualProfit()` present, `wholesaleTierMarkupPct()` gone.
- `storefrontPriceTerms()`: PekanGame and OhahaStore no tier and no markup, FixFast Demo tier 5 and markup 5, unchanged.
- Membership effective on PekanGame and OhahaStore, off on FixFast Demo.
- `Order::walletRefundSql()` totals 43,004 sen, equal to the ledger's `wallet_refund` total.
- All four Vercel projects success on the merge commit.

Items 163, 170 and 175 closed.

**Package count 1,389 to 1,365, not from the release.** Run 3378 at 08:30 UTC, before the merge, took 26 DigiFlazz packages off (Valorant Indonesia 17, Mobile Legends Global 5, Mobile Legends Brazil 4), all `inactive` at the supplier.

**Gotcha.** `gh pr checks --watch` right after opening a release PR exited 0 on the Vercel checks alone, before the Actions jobs had registered. The merge guard read the real checks and held. Watch the Actions run itself with `gh run watch <id> --exit-status`.

**Not yet seen:** the Link dialog on the live admin, and the first real KOL redemption.

## 2026-11-07 — Gamevion order test at RM0 balance; item 162 moved to group D

**Why.** The founder is not using Gamevion yet, so the repoint stops at 6 of 71 packages. Before parking it, one order at RM0 balance, as ADR-137 decision 11 planned: `supplier_request_logs` held no `createOrder` call for Gamevion, so the order path had never been exercised.

**What ran on production.** The founder ran the tinker script themselves: read the live balance, stop unless it was exactly 0, then `createOrder` through `SupplierAdapterFactory` for `hok:16`, reference `PG-GV402-20261107`, a made-up player ID. Before it: balance 0, debt 0, credit off.

**Result.** `POST /api/order` answered 402 at 10:40:03 UTC, log row 8095: `insufficient_balance`, "Insufficient balance (RM 0.00 < RM 0.71). Please top up." The adapter returned `outcomeConfirmedFailed: true`, `resendUnsafeWithSameReference: false`. RM 0.71 matches the synced price of `hok:16`. Checked afterwards, read-only: 0 orders with that reference, 0 Gamevion packages on.

**What it proves.** The key, the IP allowlist, the order endpoint and our error mapping work. With a made-up player ID it answered 402, not 422, so the balance check appears to come first, which answers ADR-137's open question. Not proven: a delivery, its webhook, drawdown and real cost. Those wait for the funded test order.

**Gotcha.** Supplier Reactivate would not switch on all 71, as earlier notes said. Through `PackageActivation::blocker()` on production today: 6 repointed packages have no blocker, 65 are blocked `supplier_sync`. At RM0 balance those 6 would still sell, so the warning stays, with the right number.

## 2026-11-07 — Signed-out membership page

**Why.** A visitor to `/membership` saw only an email field and learned nothing about the offer. The founder designed a new page; the content and calculation come from the backend (ADR-055 2026-11-07 addendum).

**Built on `feature/membership-page-v2`, into `staging`, not released.** No migration, no env change. New public `GET /api/membership/offer`. New `MembershipLanding` and `MembershipCalculator`, `lib/membership-calculator.ts`, and `lib/membership-signin.ts` so a sign-in survives a reload. The plan chosen in the calculator carries through to its payment step. `chargeSen()` is extracted so subscribe and the page share one charge calculation.

**Departures from the founder's design, after a mobile UX review the founder accepted.** The hero shows the price and a real example instead of an email form. The final band has a button, not an email field. On a phone only the winning plan is a full card. See the Difference comes before How It Works. Copy changed from "billed monthly" to "30 days", because nothing renews by itself. The hero example uses the top plan. The desktop mascot is the kit's `pointing-camera` pose on a flat yellow panel, primary brand only: the design's thumbs-up wasn't in the package, the `hype` pose was cut at its edges, and the founder found the lavender panel with its diagonal stripe generic.

**Calculator changed after the founder's review.** Showing a loss at low spend worked against the point of membership, so the page now shows one number, the saving, with the cost under it, the break-even as a goal, and a 30-day try with no auto-renewal. Copy was rewritten in the plainest words (ADR-055 addendum, decision 10).

**Verified.** Backend 3,825 tests pass. `tsc` and `eslint` clean. Playwright: the calculator's maths on production's plan figures, and a 390px visitor going from the offer through a reload at the OTP step to the plan's payment step. The existing subscribe spec still passes. Screenshots at 390px and 1440px with production's plans; no sideways scroll; hero and FAQ are in the server HTML.

**Gotchas.**
- A throwaway fixture with a mispriced representative package produced a negative saving, shown as `+-RM22.50`. The storefront now floors the percentage at 0.
- An `IntersectionObserver` misses a jump straight past a section, so the sticky button stayed on at the page end. A very large `rootMargin` makes "above the viewport" count as intersecting.
- `text-tertiary` fell to about 2:1 on the light Bumblebee, Red Giants, Emerald and Cobalt presets, so the page doesn't use it.

- The first offer was cached once for every brand, so a game hidden on a brand could still appear as an example there. OhahaStore hid nothing on 2026-11-07, so nothing showed; a test now covers it.

**Not yet seen:** the page on a real phone, the mail-app round trip in a TikTok or Instagram in-app browser, or OhahaStore itself: local has no such brand, so its panel was checked by forcing the non-primary branch with the Bumblebee theme.

## 2026-11-07 — KOL link applies its code; bank logos load up front

**Why.** 109 KOL clicks, 0 redemptions. The check found the codes work on production and end to end in e2e, but a phone customer could pay full price without noticing the code wasn't applied. Most visitors never reach checkout, so this alone won't fix redemptions. The founder also saw blank bank tiles in TikTok's browser.

**Built on `feature/kol-code-auto-apply`, into `staging`, not released.** Storefront only, no migration, no env change. A link's code applies once email and phone are in; a note under the header says the code is saved (ADR-118 2026-11-07 addendum). Bank logos are webp, unoptimized, eager, cached 30 days (ADR-130 2026-11-07 addendum).

**Verified.** Playwright: link to note to auto-apply, Remove stays removed, note stays closed, no note without a link; the checkout spec still passes. Production read-only: all four codes quote RM1.07 on RM21.55.

**Gotcha.** A failed tinker seed looked like an "invalid code" bug: `createCampaign()` needs an admin id, and the error was hidden. Prod's real codes were checked before blaming the code path.

**Not yet seen:** either change in a real TikTok or Instagram browser.

## 2026-11-08 — The proxy stops holding visitors, and the game card stops over-downloading

**Why.** The founder still felt pages load slowly. PageSpeed scored the homepage 95 and 96 on mobile, because it tests one page once, and it had no real-user data. A read-only stress test found the waits it couldn't see: see the ADR-078 2026-11-08 addendum for the measurements.

**Built on `fix/storefront-proxy-off-the-hot-path`, into `staging`, not released.** No migration, no env change. `lib/stale-cache.ts` serves an expired answer and refreshes behind it, one load per key; `proxy.ts` uses it for both reads, reads them together, and times out at 2 s. `images/` leaves the matcher. `ProductCard` has `sizes`, and an ESLint rule requires `sizes` on a `fill` Image. The storefront gains `npm test`, run in CI.

**Verified.** 8 unit tests, red first. New `storefront-proxy.spec.ts` drives the real file: a saved redirect fires, an unknown host gets 503, and an image on that host gets 200. With the old matcher put back, that last test failed 503 against 200, so it can fail. The checkout, SEO, membership and promo-link specs pass. `tsc` and `eslint` clean; a test file with a bare `fill` Image failed lint.

**Gotchas.**
- The first origin control was wrong: `api.pekangame.space/robots.txt` answered `cf-cache-status: HIT`, so it never reached the box. A unique query string forces a real origin read.
- `npx lighthouse` is blocked in auto mode, and the PageSpeed API hit its daily quota. Expanding the rows on `pagespeed.web.dev` with Playwright reads the same detail.

**Not yet seen:** the same measurements on production after release, and a refresh running behind a response on Vercel. If one never ran, the hour limit would still force a fresh read.

## 2026-11-08 — OPcache was full; cleared by hand; a full cache now shows

**Why.** While measuring the slow pages, an empty route took 340 ms straight to PHP-FPM and 1 ms inside Laravel. OPcache was on and full. Cause and decisions: ADR-066 2026-11-08 addendum.

**What ran on production.** The founder ran both, 2026-11-07 about 16:15 to 16:20 UTC. Each wrote one temp PHP file in `~`, outside the web root, ran it through the FPM socket, and deleted it.
1. A read of `opcache_get_status()`: enabled, full, 128 of 128 MB, strings 8 of 8 MB, wasted 0%, 4,520 scripts, hit rate 22.64%, 0 restarts of any kind, started 2026-11-04 06:21 UTC.
2. `opcache_reset()`: returned true. No restart; running requests were untouched.

**Before and after.**

| Measure | Before | After |
| --- | --- | --- |
| `/up`, straight to PHP-FPM | 340 ms | 17 ms |
| `/api/catalog/seo/redirects`, straight to PHP-FPM | 430 ms | 45 ms |
| Public API | about 450 ms | about 100 ms |
| Page, warm | 0.5 s | 0.2 s |
| Page after 18 s of quiet | 0.9 to 3.0 s | 0.3 to 0.5 s |
| 20 bank logos after 17 s of quiet | about 10 s | 0.7 to 1.7 s |

`/api/health` ok afterwards, no new `ERROR`. The reset doesn't last: each release adds to the cache again, which is §16 item 176.

**Built on `fix/opcache-full-is-visible`, into `staging`, not released.** No migration, no env change. `PhpCodeCache` reads OPcache inside PHP-FPM. A full cache fails `/api/health` as `php_code_cache`, and the Dashboard's System details shows size and hit rate.

**Verified.** Tests red first. A full cache fails health, a cache with room passes, and OPcache off, as in the console and the tests, never counts as full. Backend suite, admin `tsc`, `eslint` and unit tests pass.

**Gotchas.**
- Reading OPcache from the console says nothing: it is off there, and FPM's memory is its own. Timing a large file through the FPM socket showed it with no write: 40 to 47 ms against 58 ms with no cache at all, and 5 ms after the reset.
- A reload isn't free: `process_control_timeout` was 0, so the obvious one-line fix would have ended running requests. That setting goes first.

**Not yet seen:** the row on the live Dashboard, and a release clearing the cache by itself.

## 2026-11-07 — Released #733: membership page, KOL auto-apply, bank logos

**Released on the founder's go-ahead.** `main` `45561549`, box release `82375880`, CI, `deploy` and all four Vercel projects green. Ships #731 and #732, plus the docs of #729 and #730. No migration, no env change. Recorded late, in the 2026-11-08 docs audit.

**Checked after release, read-only.**
- `/api/health` ok, Horizon running, 0 failed jobs in 24 hours, no paid order undelivered. Order 133, created after the deploy, was paid and delivered.
- `/membership` loads with no console error on PekanGame and OhahaStore at phone width, with no sideways scroll. `/api/membership/offer` answers on both with the same plans.
- All 20 bank logos answer 200 as webp with a 30-day cache on the storefront. No code refers to the old `.png` files, which now 404.

**Not checked:** auto-apply and the saved note on production, because opening a KOL link records a click; a real TikTok or Instagram browser; a paid checkout.

**Seen by chance.** `/order/<a wrong slug>` shows "Page not found" with HTTP 200, not 404.

## 2026-11-08 — Item 176 on the box: FPM timeout, OPcache size, reload in the deploy script

**What ran on production.** The founder ran these in the droplet console, 2026-11-07 17:18 UTC, then edited the Forge deploy script. Exact values: ADR-066 2026-11-08 addendum.
- `process_control_timeout = 35s` in `php-fpm.conf`, was 0.
- New `conf.d/99-pekangame-opcache.ini`: `memory_consumption=256`, `interned_strings_buffer=32`, `max_accelerated_files=32531`, were 128, 8 and 10000.
- `php-fpm8.4 -t`, then a restart.
- Forge deploy script: the reload right after `$ACTIVATE_RELEASE()`, and `/up` at `--max-time 40`. The script as it stood had no reload.

**Checked after, read-only.** PHP-FPM reports all three values; `process_control_timeout` reads 35s. `/api/health` ok, Horizon running, 0 failed jobs in 2 hours, no paid order undelivered. `/up` straight to PHP-FPM 16 to 21 ms, and the public API 0.06 to 0.09 s, so the gain held through the restart.

**Not yet seen:** a release running the reload line and emptying the cache. If it doesn't, the socket reset in the ADR comes back.

## 2026-11-08 — Docs audit after release #733

**Why.** The founder asked for one after the release: nothing outdated or contradictory against the live system.

**Corrected in `prd.md`.** §14's latest release, whose "no migration" sat over a migration bullet that belonged to #723. "Not released" on three §15 rows, one of them (ADR-119) out of date since #715. Customer and KOL figures, with the fourth campaign and two missing fees. The domain list, missing `admin.` and `onboarding.`. A Digiflazz SKU count that had drifted. Item 159: OpenWA had shipped the fix, and the doc still called v0.27.1 the latest; it is now upgraded and closed.

**Fired for the first time.** The low-balance alert (ADR-115), 2026-11-07 15:59 UTC: DigiFlazz at about RM 2,375 against the RM 2,391 floor. So it left §14's "not yet seen". The log line is proven; the email's arrival is not.

**Checked and right.** The ADR index, 138 rows for 138 files, no duplicates, no last-addendum date older than its file. No broken doc link, no missing path in the guides. Stack versions, the eight required checks, API v1.5.0, region `sin1`. Payment methods, October's close at RM129.22, CS in Shadow with 9 knowledge entries, no Joki order or partner application, 3 resellers, Gamevion 0 of 71, 1 active member. No §16 item both open and closed.

**Not audited.** The text of each ADR against the code, `docs-site` content, and §6 to §9 requirement by requirement.

## 2026-11-08 — OpenWA v0.29.1: the chat list survives a restart (item 159)

**Why.** rmyndharis/OpenWA#1992, the founder's own request, ships from v0.28.0. v0.29.1 also stops a Baileys start blocking on a large store.

**Checked first.** Release notes for v0.28.0 to v0.29.1 against what we use: five API calls and three webhook events. Our session ids are UUIDs, so the new `404` doesn't apply. `message.ack` now fires for group messages, and neither webhook subscribes to it. The daemon stops on `SIGTERM`, and it can read `.env` and `data/.env.generated`.

**What ran on production.** The founder ran each step at 01:50 MYT with 0 orders in the last hour, 0 open payments and 0 notifications waiting: `scripts/openwa-upgrade.sh build v0.29.1`, daemon stopped in Forge, `switch v0.29.1`, daemon started. Backup: `~/openwa-backups/openwa-v0.27.1-20261107-1751.tar.gz` (62 MB). Rollback is `switch v0.27.1` plus that tarball.

**Checked after, read-only.** Offline 17:51:31 to 17:53:06 UTC for `reseller-bot`, 17:53:11 for `customer-support`, about 1m40s. `/api/health/whatsapp` 200, both `ready`. Laravel recorded both `ready` from the `session.status` webhook, so the webhook path works. No webhook delivery failure. Horizon and `/api/health` untouched; no process was killed by the build. The index migration ran at boot.

| Chats listed after a restart | v0.27.1 (2026-11-04) | v0.29.1 |
| --- | --- | --- |
| `reseller-bot` | not recorded | 19, 10 one-to-one |
| `customer-support` | 8, 1 one-to-one | 38, 31 one-to-one |

The 2026-11-04 row is as item 159 recorded it, without naming the session; it is shown under `customer-support` as the nearest match.

**Gotchas.**
- Auto mode refuses piped remote scripts, so the founder ran `build` and `switch` with `!`. `build` passes the 120 s command limit and carries on in the background: that isn't a failure, and running it twice would stop on "already exists".
- `API_KEY_PEPPER is not set` is a boot warning, not new (22 earlier boots). Leave it: setting it now locks out every key.

**Not yet seen:** a real message in and out on v0.29.1. Nothing has arrived at this hour.

## 2026-11-08 — Released #737: storefront speed, code cache check, docs audit

**Released on the founder's go-ahead.** `main` `dc828aee`, box release `82383059`, CI, `deploy` and all four Vercel projects green. Ships #734, #735 and #736. No migration, no env change. 0 paid orders undelivered before it.

**Measured after release, the same way as before.**

| Measure | Before the work | After |
| --- | --- | --- |
| Order page, load event, desktop | 9 to 14 s | 0.5 to 0.7 s |
| Order page, load event, phone | 4 to 6 s | 0.5 to 0.6 s |
| Page after 18 s of quiet | 0.9 to 3.0 s | 0.22 to 0.29 s |
| 20 bank logos after 17 s of quiet | about 10 s | 0.19 to 0.40 s |
| `/up`, straight to PHP-FPM | 340 ms | 18 to 21 ms |

**Item 176 closed.** The deploy output shows `Restarting FPM...` for the first time. A read of OPcache through FPM, run by the founder: started 2026-11-07 18:43:55 UTC, the deploy's moment, not full, 73 of 256 MB, strings 15.7 of 32 MB, 1,581 scripts, hit rate 99.18%. So a reload does clear it on this box, and the socket reset stays unneeded.

**OpenWA v0.29.1 proven both ways.** The founder's test message reached `cs_messages` (272) with a Shadow draft. Three notifications went out with a WhatsApp id: a status card, a voucher and a delivered receipt.

**Seen while checking, not from this work.**
- Order `PG-7JUVSOBGJWJJ` failed at DigiFlazz, `rc` 02, on First Top Up 250+250. Voucher 13 (RM17.69) was issued and spent on order 137, which delivered.
- The low-balance alert fired at 15:59, 18:33 and 18:43 UTC, and then cleared: DigiFlazz's floor went from IDR 10,000,000 to 5,000,000, and 9,533,687 is above the 7,581,286 forecast. Recovery resets the claim, so a floor moved up and down can send it twice in one day. By design.

**Gotcha.** `gh run list --limit 1` straight after a push returned the previous run, so a watch finished early. The merge guard read the real checks and held. Pick the run by `--commit`.

**Not yet seen:** a KOL link and the bank logos in a real TikTok or Instagram browser.

## 2026-11-08 — Admin ↔ Middleware switch link under the sidebar brand

**Asked by the founder.** Switching panels meant editing the URL by hand. A small link now sits under each panel's brand: "Middleware Panel" under "PekanGame Admin" (super_admin only, since the Middleware layout sends anyone else back to `/admin`) and "Admin Panel" under "Middleware Panel". It opens in a new tab (founder's choice). It first sat in a "Panels" section at the foot of the menu; the founder moved it up because the admin menu needs scrolling to reach its end. `PanelSidebar` takes an optional `switchTo`; collapsed, the link shows its icon only.

**Verified** on a local admin dev server (`:3005`, the API given `ADMIN_URL` / `SANCTUM_STATEFUL_DOMAINS` for that port because `:3000` was busy with a video render): signed in as super_admin, light and dark, expanded and collapsed. The link carries `target="_blank" rel="noopener noreferrer"`, the click opens `/middleware` in a new tab with the original left on `/admin`, and the way back works the same. Computed colour `ink-muted` on `surface` in both themes. `tsc` and `eslint` clean.

## 2026-11-08 — Item 164: Suppliers page on the ADR-104 tokens

**Built** on `feature/164-middleware-suppliers-restyle`, a pure reskin: `/middleware/suppliers` and its Create, Edit and Manage Packages modals carry no `gray-*`, `brand-*` or `white/[…]` class any more. Mapping, benchmarked on admin `/orders`: page title `text-page-title text-ink`, card title `text-section-title`, labels `text-ink-muted`, values and form labels `text-ink`, cards `bg-surface border-border`, hand-rolled inputs on the kit input's colours (`border-border-control`, `focus-ring` outline), error banner `danger-surface/ink`, the Reactivate note `info-surface/ink`, the "Not configured" pill `neutral-surface/ink`. The one layout touch: "Add Supplier" no longer wraps to two lines beside the long description (`gap-3`, `shrink-0`).

**Verified** with Playwright screenshots, light and dark, of the page and all three modals beside `/orders`; `getComputedStyle` on the title (26 px), labels and values in both themes; 390 px phone width with no horizontal scroll. `tsc`, `eslint` and `color-tokens.test.ts` pass.

**Gotcha.** A Next dev server left running across a `git checkout` threw a sidebar hydration error (the server still rendered the other branch's module). A fresh load on each branch had none: not a bug.

## 2026-11-08 — Item 164: Price Sync Center on the ADR-104 tokens

**Built** on `feature/164-middleware-price-sync-restyle`, a pure reskin of `/middleware/price-sync` and its three parts (`SyncDetailsModal`, `PendingPriceChangeSection`, `FxRateHistorySection`): 160 old classes, now none. Same mapping as Suppliers, plus table rules `divide-border` / `border-border`, the empty-state box `bg-subtle`, the "Combo — recomputed" pill `cyan-50` / `cyan-ink`, and the run errors and flags `danger-ink` / `warning-ink`. Section headings take `text-section-title`. One layout touch: "Refresh" and "Sync All Prices Now" were squeezed to three lines beside the description; the header now wraps like `/orders` (buttons stay right on desktop, drop below the text on a phone).

**Verified** with Playwright, light and dark, the full page and the Sync Details modal, and the header at 1440, 1024 and 390 px (no horizontal scroll). `tsc`, `eslint` and `color-tokens.test.ts` pass. Checked against the founder's own `./scripts/dev.sh` servers, which had taken `:3000` and `:8000`.

## 2026-11-08 — Item 164: Middleware Dashboard on the ADR-104 tokens

**Built** on `feature/164-middleware-dashboard-restyle`, a pure reskin of `/middleware` (34 old classes, now none), same mapping as Suppliers and Price Sync: page title, section titles, `surface` cards on `border-border`, inner tiles and API-call rows on `border-border`, muted text `ink-muted`, the error banner `danger-surface` / `danger-ink`.

**Verified** with Playwright, light and dark at 1440 px and light at 390 px (no horizontal scroll), against the founder's `./scripts/dev.sh` servers. `tsc`, `eslint` and `color-tokens.test.ts` pass.

## 2026-11-08 — Item 164: Payment Gateways on the ADR-104 tokens

**Built** on `feature/164-middleware-payment-gateways-restyle`, a pure reskin of `/middleware/payment-gateways` and its Edit modal (33 old classes, now none), same mapping as Suppliers: the card title takes `text-section-title`, the modal's inputs the kit input's colours and `focus-ring` outline, the Configured / Not configured pills `success-*` / `neutral-*`, errors `danger-*`.

**Verified** with Playwright, light and dark at 1440 px with the Edit modal open, and light at 390 px (no horizontal scroll). `tsc`, `eslint` and `color-tokens.test.ts` pass. **Seen, not changed:** this modal and the supplier Edit modal are narrow (about 210 px of input), so placeholders cut off; that is layout, outside a reskin.

## 2026-11-08 — Item 164: Validators on the ADR-104 tokens

**Built** on `feature/164-middleware-validators-restyle`, a pure reskin of `/middleware/validators`, `ValidatorCard`, `CreateValidatorModal` and `ValidateByGamePanel` (about 80 old classes, now none). Beyond the shared mapping: the country chips and their arrow on `cyan-50` / `cyan-ink` / `cyan-600`, the text actions (Edit, Game ID Checker, Test this validator) `text-cyan-ink` with an underline on hover, Delete `danger-ink`, the dashed "Add Country" button and add form on `border-border-strong`, result pills on the `success` / `danger` / `warning` surface and ink pairs, the inline inputs on `border-border-control`.

**Verified** with Playwright, light and dark at 1440 px and light at 390 px. The local dev DB has no validator, so the cards were rendered from a mocked `GET /api/middleware/validators` (route interception, nothing written to the DB) with the add-country form open. `tsc`, `eslint` and `color-tokens.test.ts` pass.

## 2026-11-08 — Item 164: Payment Methods on the ADR-104 tokens

**Built** on `feature/164-middleware-payment-methods-restyle`, a pure reskin of `/middleware/payment-methods` (42 old classes, now none): shared mapping, the fee inputs on `border-border-control` / `surface`, the active category chip `bg-cyan-600 text-on-cyan` (the kit's primary button colours), the failed test result `danger-ink`.

**Verified** with Playwright, light and dark at 1440 px and light at 390 px. `tsc`, `eslint` and `color-tokens.test.ts` pass. **Seen, not changed (layout, not colour):** at 390 px the channel table is clipped inside its card (Last Test and Actions are off to the right) and the category chips wrap.

## 2026-11-08 — Item 164: Developer / API Tester on the ADR-104 tokens

**Built** on `feature/164-middleware-developer-tools-restyle`, a pure reskin of `/middleware/developer-tools` (54 old classes, now none): shared mapping, the selected endpoint `bg-cyan-50 text-cyan-ink` (the sidebar's active look) with `hover:bg-subtle` on the rest, the Create Order note `warning-surface` / `warning-ink`.

**Verified** with Playwright, light and dark at 1440 px with Digiflazz chosen and an endpoint selected, and light at 390 px. `tsc`, `eslint` and `color-tokens.test.ts` pass. The screenshot script now signs in once and reuses the session: the fifth sign-in in a few minutes hit the login rate limit.

## 2026-11-08 — Item 164: Backups on the ADR-104 tokens

**Built** on `feature/164-middleware-backups-restyle`, a pure reskin of `/middleware/backups` (68 old classes, now none), the shared mapping only: stat cards and the table card on `surface` / `border-border`, muted labels and cells `ink-muted`, the error banner `danger-*`.

**Verified** with Playwright, light and dark at 1440 px and light at 390 px. The local DB has no backup, so the table showed its empty state; its row cells use the same classes as the header and stat cards. `tsc`, `eslint` and `color-tokens.test.ts` pass.

## 2026-11-08 — Item 164: Request Logs on the ADR-104 tokens

**Built** on `feature/164-middleware-request-logs-restyle`, a pure reskin of `/middleware/request-logs` and its View modal (85 old classes, now none), the shared mapping, plus `text-ink` on the payload `<pre>` blocks: they had no text colour of their own, the gap ADR-104 found rendering black on a dark card.

**Verified** with Playwright, light and dark at 1440 px with a log's View modal open (52 local rows, payloads readable in both themes), and light at 390 px. `tsc`, `eslint` and `color-tokens.test.ts` pass.

## 2026-11-08 — Item 164: Sandbox on the ADR-104 tokens

**Built** on `feature/164-middleware-sandbox-restyle`, a pure reskin of `/middleware/sandbox` and `CreateSandboxOrderModal` (about 60 old classes, now none): the shared mapping, the search box on `border-border-control` / `surface` with the `focus-ring`, the back link `hover:text-ink`. The order detail it opens reuses the admin Orders components, which are not touched here.

**Verified** with Playwright, light and dark at 1440 px (page, and the Create Test Order modal open), and light at 390 px. The local DB has no test order, so the list showed its empty state. `tsc`, `eslint` and `color-tokens.test.ts` pass.

## 2026-11-08 — Item 164: Product Manager on the ADR-104 tokens

**Built** on `feature/164-middleware-product-manager-restyle`, a pure reskin of `/middleware/product-manager` and its Link Category, Promote and Repoint modals (about 150 old classes, now none): the shared mapping, the group search and the in-group search on `border-border-control` / `surface` with a cyan focus, the three group tabs on `/orders`' own active style (`border-cyan-600 text-cyan-ink`, `hover:text-ink` on the rest), the back link `hover:text-ink`.

**Verified** with Playwright, light and dark at 1440 px: the group list (21 local groups), a group's Available Packages tab, and the Link Category modal; light at 390 px. `tsc`, `eslint` and `color-tokens.test.ts` pass.

## 2026-11-08 — Middleware dialogs get a width; Payment Methods chips stop wrapping

**Found during item 164.** A `DialogPopup` with no width class sizes to its content, so the supplier Edit modal and the Payment Gateway Edit modal shrank to about 210 px of input and cut every placeholder, while Manage Packages stretched to about 950 px around its one long note. Fifty-seven dialogs in the admin already pass `w-full max-w-*`; ten did not, seven of them under `/middleware`.

**Fixed** on `fix/middleware-modal-and-table-layout`: the supplier Create, Edit and Manage Packages modals take `max-w-lg`; the Payment Gateway Edit modal and the Activate / Deactivate / Delete / backup confirms on the Suppliers and Backups pages take `max-w-md`. On Payment Methods the category chips no longer break "E-Wallet" over two lines (`flex-wrap`, `whitespace-nowrap`).

**Corrected claim.** The Payment Methods table at 390 px was not clipped, as an earlier entry said: the DataTable's own container scrolls sideways (356 px wide, 600 px of table) and the Test button is reachable by a swipe, the same as admin `/orders`.

**Verified** with Playwright: dialog widths 512 / 448 px at 1440 (inputs 474 and 410 px, from about 210), 326 px at 390; every chip 32 px tall at 390. `tsc` and `eslint` clean. **Left:** the four dialogs without a width outside `/middleware` (admin Resellers, admin Suppliers, Affiliates, Partner Applications).

## 2026-11-08 — `DialogPopup` gets a default width

**Why.** The entry above fixed the seven width-less dialogs under `/middleware` one by one; the cause is the kit itself, where a `DialogPopup` with no width class sized to its content. The founder asked for the fix at the source so a new dialog can't repeat it.

**Built** on `fix/dialog-popup-default-width`: `admin/src/components/ui/dialog.tsx` now defaults to `w-full max-w-md`. A caller's own `max-w-*` still wins (`cn` runs tailwind-merge). The portal copy was regenerated with `node scripts/sync-shared-files.mjs`; the portal uses no `DialogPopup` today, so no portal screen changes. Affected in the admin: the four width-less dialogs left outside `/middleware` (Resellers: Deactivate, Change tier, Delete tier; Suppliers: Reply SLA, Deactivate; Affiliates: Deactivate, Delete, Delete tier; Partner Applications: Approve / Decline / Delete), now 448 px instead of content width, and the Request Logs View modal, which passed `max-w-2xl` without `w-full` and now opens at 672 px instead of about 310 px, so payloads read without wrapping.

**Verified** with Playwright at 1440 and 390 px: each of those dialogs 448 px (Request Logs 672 px), 326 px on a phone; a dialog with its own width keeps it (Repoint `max-w-lg` 512 px, Promote `max-w-md` 448 px). `tsc` for admin and portal, `eslint` clean.

## 2026-11-08 — Admin Orders components: the last old colour classes

**Built** on `style/orders-components-tokens`, the same pure reskin as item 164 for the three admin Orders components the Middleware Sandbox reuses: `DeliveryLogsTable`, `OrderDetailCards` and `ResendDeliveryModal`. They were already on `ink` / `surface`; the cards, table rules and Resend option boxes still drew `border-gray-*` / `divide-gray-*` with `dark:` pairs, now `border-border` / `divide-border`. The negative profit figure and the Resend error banners move to `danger-ink` / `danger-surface`, and the Resend warning banner to `warning-surface` / `warning-ink` (its dark text had been `orange-400`).

**Verified** with Playwright, light and dark, on a failed order's detail page (cards and Delivery & Activity Logs). The Resend modal could not be opened locally: every failed order in the dev DB is already compensated, so the button is hidden; its change is borders and banners only, with the mapping checked on the other pages. `tsc`, `eslint` and `color-tokens.test.ts` pass.

## 2026-11-08 — Released #755: middleware restyle, panel switch link, dialog widths

**Released on the founder's go-ahead.** `main` `261345ca`, box release `82402542`, CI (all jobs) and `deploy` green, Vercel production rebuilt admin and portal from that commit (storefront and docs skipped by their ignore step, as intended). Ships #738 to #754: item 164 (#740 to #751), the panel switch link (#739), the middleware dialog widths and Payment Methods chips (#752), the `DialogPopup` default width (#753) and the admin Orders detail components (#754). Frontend only: no backend change, no migration, no env change. 0 paid orders in flight before it.

**Checked after release.** `/api/health` ok, `php_code_cache` true; `/api/health/whatsapp` 200; storefront, admin, portal, docs and onboarding all 200; the box's `current` at `261345c`; 0 paid orders in flight.

**Gotcha.** Thirteen page PRs that each appended a build-log entry would conflict with each other at every merge and re-run CI each time. Moving the entries to one docs PR left the page PRs touching disjoint files, so they merged in any order on their first green run.

## 2026-11-08 — ADR-139 proposed: reseller milestone rebate and automatic refund (docs only)

The founder reopened item 161. Asked why they won't switch, agents said our price is about the same as their leader's (both at the floor). They proposed a rebate themselves: reach a monthly milestone, claim it, credited to Purchase Balance, "like a battle pass".

**Decided with the founder:**
- A monthly ladder (RM500 to RM20k to start), with a fixed RM reward per step that is set per tier.
- The reward is funded from a share of our own actual profit, rising from 10% to 35%. We keep at least 65%, and a 50% hard ceiling sits in code.
- The reseller claims every step in the portal only.
- Claims are open until the end of the next month.
- Unspent rebate is forfeited at account closure.
- Tier changes take effect on the 1st.
- The ladder is changed only with MFA, effective next month.
- The automatic refund is only for a supplier-confirmed Failed. Tier SSS is the one at the leader's price.

**Code trace:** three read-only forks (pricing; ledger; readers and accounting), with the top findings re-checked in code. It found:
- Closure would have cashed out rebate credit.
- A `wallet_topup` row reads as bank cash in the Register.
- No profit report or month-close line sees a wallet credit.
- Delivery rewrites `platform_profit` from real cost. Production has a delivered wallet order at −13 sen.
- Splitting the wallet into two ledger accounts would touch about 26 balance readers. Unspent rebate is therefore derived by replaying the ledger instead.

**Production, read-only:** 3 resellers, 1 buying (Tier SS, RM446.56 sales / RM12.69 profit to date). 4 failed wallet orders and 4 manual refunds (RM430.04). 0 late contradicting supplier results in 138 orders.

**Grill (three rounds, 18 questions) and simulation, same day:**
- **Simulation:** read-only on production through `OrderPricingResolver::resolveResellerWallet()`.
  - 9 of 11 delivered wallet orders reproduce their selling price exactly.
  - Actual profit is 94.56% of formula profit, which is real-cost drift.
  - Repricing 100 delivered orders shows no rounding loss at any tier.
  - So the ladder top share went from 35% to 32% under a 35% cap. We keep 70% at RM10k and 68% at RM20k.
- **Final stress test, eight findings folded in:**
  - one entitlement function, so an early cut self-heals and is never "claim late, get more";
  - tier change history, so finalise reads the right tier;
  - the claim deadline is checked by date;
  - the refund sweep skips sandbox orders and closed resellers, which `settle()` refuses;
  - a `WalletEntryType` enum makes the unspent-rebate replay exhaustive;
  - step notifications come from a scheduled task, not the money path.
- **Accepted.** The accountant confirms the accounting decisions before PR 3.
- **Accountant review, same day.** The accountant confirmed the treatment: a revenue reduction recognised at reach; a claim moves the liability to Purchase Balance; revenue is recognised on use. Two changes:
  - an unclaimed reward that expires reverses the revenue reduction rather than being breakage;
  - the open month's rebate line is live from `RebateEntitlement` rather than provisional.

  Added:
  - versioned programme terms, accepted at the first claim;
  - per-row calculation inputs and a CSV export, for income tax.

  The founder confirmed there is no marketing obligation and that the business charges no SST (a scope check with a tax agent is company-level, not a gate). ADR-139 addendum; PR 3 unblocked.
- **Programme terms version 1 approved by the founder** and recorded verbatim in the ADR-139 addendum. Clause 2 was aligned with mid-month claims and the monthly cap. Clause 7's notice promise is enforced in code: a ladder version must be saved at least 7 days before its month, and saving it notifies every eligible reseller (portal banner and bot).

## 2026-11-08 — ADR-139 PR 1: automatic refund of a Failed wallet order

Branch `feature/auto-refund-failed-wallet-orders`, into `staging` (not released).

**Shipped:**
- `app:refund-failed-wallet-orders` runs every minute (`CRITICAL`, `wallet-order-auto-refund`). It settles each uncompensated Failed wallet order through `OrderSettlementService::settle()`, with the system as actor.
- It reuses `Order::needsAction()`. It skips sandbox orders, closed resellers (which `settle()` refuses) and orders with a late supplier Success.
- Each order is isolated, and an email goes out on the third failed run.
- `settle()` accepts a null actor for wallet orders only. The `wallet_refund` row now records `created_by`.
- `finalizePendingDelivery()` emails every admin on any late contradicting Success.

**Found while building:** a Success arriving after Failed but before the sweep would have been refunded on top of a possible delivery. It is now skipped and alerted (ADR-139 PR 1 addendum).

**Tests:**
- 7 new, using a sqlite trigger for a real failure inside `settle()`.
- Full suite: 3,837 passed.
- The concurrency suite was not run: no locking changed, and `settle()`'s lock is already proven by `RefundToWalletConcurrencyTest`.
- Production has 0 uncompensated Failed wallet orders (all 4 refunded by hand), so the first run after release refunds nothing old.

## 2026-11-08 — ADR-139 PR 2: wallet tier changes take effect on the 1st; markup fixed per tier

Branch `feature/reseller-tier-from-the-1st`, into `staging` (not released).

**Shipped:**
- `reseller_wallet_tier_changes`, backfilled with each reseller's current tier. The local dev DB has 1 row for 1 tiered reseller after a plain `migrate`.
- `ResellerTierChangeService` is the one writer. A reseller's first tier is immediate. A later change waits for the next 1st (KL); a new choice replaces it and the current tier cancels it.
- An hourly `CRITICAL` `app:apply-reseller-tier-changes`.
- The admin list shows "→ Tier X from 1 Dec 2026". The Change Tier dialog says when the change takes effect.
- A tier's markup is fixed after creation: the API answers 422 with guidance, and the admin edit form disables the field. The founder chose this over hard-coding five 1–5% tiers.
- A tier that a pending change points to can't be deleted.

**Gotcha:** the sqlite `date` cast stores a time, so a string `<=` on `effective_on` missed midnight on the 1st. The comparison uses `whereDate`. Caught by a test pinning 23:59 and 00:00 KL.

**Verified:**
- `php artisan test`: 3,844 passed. Two old tier tests that edited markup now assert the refusal.
- Admin: `tsc`, lint, 22 unit tests and a build.
- Browser, on a local built admin and backend with a throwaway admin, deleted afterwards:
  - scheduled Scenario Reseller → Tier SS and saw it pending until 1 Dec 2026;
  - the Edit Tier form shows markup disabled with the note;
  - cancelled the test change by choosing the current tier, leaving 0 pending rows.
- Fixed an outdated "direct FK assignment" line under Wallet tiers.

## 2026-11-08 — ADR-139 PR 3a: rebate core (ladder, entitlement, claim)

Branch `feature/rebate-core`, into `staging` (not released). It changes no live behaviour: production has no ladder version, and nothing calls the claim yet.

**Shipped:**
- Ladder versions with notice and on-paper ceiling validation.
- `RebateEntitlement`, the one function every amount comes from.
- `RebateClaimService`: claims in order, pays `min(ladder at k, cap) − paid`, accepts terms once per version, and refuses after the window, for an inactive or ineligible account, or for a login of another account.
- `WalletEntryType` across all wallet writers.
- `wallet_rebate` added to the ledger dedupe backstop.

**Build decisions** (ADR-139 PR 3a addendum): the ladder grid is JSON (5 tables, not 6); claims go in order; the master switch is the ladder itself.

**Verified:**
- `php artisan test`: 3,858 passed (14 new).
- On MySQL, `RebateClaimConcurrencyTest` gives one credit from two simultaneous claims. `RefundToWalletConcurrencyTest` and `OrderSettlementConcurrencyTest` still pass after the dedupe-key rebuild.
- Full local concurrency run: 45 of 49 passed. The 4 errors are `BackupRestoreMysqlTest`, which needs `mysqldump`; that tool is not installed on this machine but is in CI's `backend-concurrency` job.
- Local dev DB migrated with a plain `migrate`.

**Gotcha:** the docker MySQL still held the `llm_report_*` views from an earlier killed run (backend AGENTS gotcha 8). Dropped them before the run.

## 2026-11-08 — ADR-139 PR 3b-i: rebate month end and the closure forfeit

Branch `feature/rebate-month-close`, into `staging` (not released). It is inert until a ladder version exists.

**Shipped:**
- `RebateMonthService`: finalise writes available rows and the adjustment row, and alerts on a cut, an adjustment or an overpayment. Expire dates each row at its deadline.
- Two daily `CRITICAL` tasks.
- Claims of finalised and adjustment rows.
- `UnspentRebate`, the ledger replay, which fails closed on an unknown type.
- Closure forfeits unspent rebate, refunds only the rest, and lapses unclaimed rewards.
- `close-check` returns the refund. The admin close modal shows it, with the forfeited part explained.

**PR 3b is split in two** (ADR-139 addendum). 3b-ii carries the accounting lines together with their admin display.

**Verified:**
- `php artisan test`: 3,865 passed (7 new).
- `ResellerClosureConcurrencyTest` and `RebateClaimConcurrencyTest` pass on MySQL.
- Admin: `tsc`, lint and 22 unit tests pass.
- Local dev DB migrated.
- Browser, on a local build with a throwaway admin deleted afterwards: the close modal shows "Balance to refund" from `refund_sen`.


## 2026-11-09 — ADR-139 PR 3b-ii: rebate accounting lines and the month-close gate

Branch `feature/rebate-accounting`, into `staging` (not released). It is inert until a ladder version exists, and no ladder can exist before PR 4.

**Shipped:**
- `RebateLiability`: the revenue reduction, breakage and payable. A month not finalised yet is live, through `RebateMonthService::liveTotalSen()`. That method shares finalise's reseller set and skip rule, so finalising moves no figure.
- Monthly Summary: "Less: reseller rebates" (subtracted in operating profit) and "Reseller rebate breakage" (added).
- Cash Position: the rebate payable claim.
- Reports profit Bridge: both rows.
- A month cannot close while its ladder month is not finalised.
- Admin: definitions for both lines, the cash-equation row and the Bridge rows.

**Found in the code trace, fixed here:** making a reseller eligible again after its month was finalised let a claim add a new row to that month, which may already be closed (invariant 2). A finalised month now takes no claim for a step finalise did not write.

**Moved to PR 4** (ADR-139 2026-11-09 addendum): decision 24's rebate line in Reports' profit totals, export and channel split; the Customer Analytics and Report Assistant labels; the notify tasks. The founder's answers on PR 4's three open points are recorded in the same addendum.

**Verified:**
- `php artisan test`: 3,874 passed (9 new).
- `RebateClaimConcurrencyTest` passes on MySQL.
- The new `FinancialScenario` step: the October gap does not move when a ladder starts, a step is claimed, the account closes with rebate unspent, or the month is finalised; the Bridge has nothing unexplained.
- Admin: `tsc`, lint and the unit tests pass.
- Browser, on a throwaway sqlite backend and admin deleted afterwards:
  - the Summary shows "Less: reseller rebates RM 6.00";
  - September's cash equation shows "Reseller rebates earned, not claimed yet RM 6.00", with the envelopes RM 6.00 lower;
  - the Reports Profit bridge shows the "−" rebate row;
  - the close is blocked with the rebate message until finalise runs.
- No migration in this PR.

**Gotcha.** The first scenario run expected a 25 sen reward and got 21: the scenario reseller's October profit is 43 sen, so the 50% cap applied. That is correct behaviour; the test's rewards were lowered below the cap.

**Same day, a review before PR 4.** The founder asked whether the rebate's money logic and code met our bar before PR 4 started. Three read-only forks ran, one per layer (AGENTS.md money-critical review). None found wrong money; I verified each finding against the code before fixing it. Fixed test-first in the same PR (#762), details in the ADR-139 2026-11-09 addendum, item 6:
- A ragged ladder could make a column fall to 0 (reachable only once PR 4's editor exists).
- A late expiry run could date reversals into a closed month. Month close now also waits for expiry.
- A claim at midnight could put the payable and the balance in two months. Both now take the ledger row's instant.
- Hygiene: one home for the claim deadline; month close asks the rebate module; constructor injection; no repeated claimed-sum query; a `ponytail:` scale note; the wallet-balance text.

Read-only production check: wallet ledger types are only `wallet_topup`, `wallet_debit` and `wallet_refund`.

`php artisan test`: 3,877 passed (3 new). The MySQL concurrency tests for rebate and closure pass. Admin `tsc` and lint pass.

## 2026-11-09 — ADR-139 PR 4a: rebate admin and portal API, bot messages

Branch `feature/rebate-api`, into `staging` (not released). PR 4 is split in three (4a API, 4b admin screens, 4c portal screens; ADR-139 addendum). Nothing reaches resellers yet: no ladder can be saved without 4b's screen, except by a super admin with MFA calling the API directly.

**Shipped:**
- Admin endpoints: ladders (list, plus save behind the step-up, which sends the notice), month view, CSV export, and an eligibility switch behind the step-up with a recorded reason.
- Portal endpoints: the Rebate page data, with the reseller's own tier only and no share, and the claim.
- Rebate detail on statement lines.
- `RebateNotifier` and the `rebate-progress-notify` task (`ROUTINE`, every 10 minutes).
- The `reseller_rebate_eligibility_changes` table.

**Found while building:**
- `SendCustomerWhatsAppJob` sent every bot-session message through `SupplierGroupNotifier::prepare()`, which skips anything without an order, so rebate messages would never have been sent. It now routes by event.
- The `dedupe_key` backstop applies only when `reason` is null, so the statement label is read from the milestone row rather than stored as a reason.

**Gotcha:** a Pint run given the whole `app/` folder reformatted about 30 unrelated files. I reverted them before committing; run Pint only on changed files.

**Verified:**
- `php artisan test`: 3,888 passed (11 new).
- Local dev DB migrated.
- A live check with `curl` on a throwaway sqlite backend (deleted afterwards): the page, the terms refusal, a claim (+RM1.00), a repeat refused, the statement detail, and 401 without a portal login.

**For the founder:** the three draft bot messages are in the ADR-139 PR 4a addendum, item 4.

## 2026-11-09 — ADR-139 PR 4b: rebate admin screens and the Reports rebate line

Branch `feature/rebate-admin-screens`, into `staging` (not released). The founder approved the three bot messages as drafted. A ladder can now be saved from the panel, behind the MFA step-up; nothing reaches resellers until the first one is saved.

**Shipped:**
- Admin → Reseller Rebate:
  - Ladder tab: the next and in-force versions, the no-column warning, all versions, and the New version dialog (effective date, ceiling flag, step-up).
  - Month tab: cards, standings, rows and Export CSV.
- Resellers: a Rebate button for eligibility (reason and step-up required) and a "rebate off" marker.
- Wallet history: rebate labels.
- Reports: a rebate line on the summary, the wallet channel, the CSV and the PDF; owner profit is labelled as before rebates.
- Customer Analytics and the Report Assistant prompt: profit labelled as before rebates.

**Found in the browser check:** a live month's "Waiting to be claimed" read only finalised rows and showed RM0.00 while a step was unclaimed. It now uses entitlement minus claimed, the payable's own figure.

**Gotcha:** the admin's own unit test refuses a `JSON.stringify` body passed to `apiFetch`, which encodes the body itself.

**Verified:**
- `php artisan test`: 3,890 passed (2 new, plus one new assertion).
- Admin: `tsc`, lint and 22 unit tests pass.
- Browser (Playwright, throwaway backend and admin deleted afterwards): ladder page, editor, month view, CSV, eligibility dialog, wallet label and Reports note. Saves answered `mfa_not_enabled` for the MFA-less throwaway admin, as designed; the request payload was correct.

## 2026-11-09 — ADR-139 PR 4c: rebate portal screens

Branch `feature/rebate-portal-screens`, into `staging` (not released). This completes ADR-139's build. Nothing reaches a reseller until the founder saves the first ladder, and that waits for this PR to merge.

**Shipped:**
- Portal → Rebate (`/rebate`, in the reseller nav under Orders & money):
  - the notice for a new table;
  - unspent rebate;
  - this month and last month, each with purchases counted, progress to the next target, and every step with its reward and state;
  - Claim buttons in step order, plus "Claim all";
  - the terms dialog on the first claim;
  - reward history and the programme terms.
- Dashboard: a Rebate card showing "RM X more to reach RM Y" and a Claim button with the count, which also carries the new-table notice. It loads on its own, so a rebate error never blanks the dashboard.
- Statement: `wallet_rebate` is labelled "Rebate" with its step and month, and `wallet_rebate_forfeit` is labelled "Rebate forfeited".
- Backend: the portal history rows now carry `threshold_sen`, so a history line reads like its statement line.

**Verified:**
- Rebate tests: 45 passed (one new assertion).
- Portal: `tsc`, lint, build and 22 unit tests pass (`rebate.test.ts` is new, and `format.test.ts` gains the rebate labels).
- Browser (Playwright, on a throwaway sqlite backend and the portal dev server, both deleted afterwards). Seeded with one SS reseller, a 4-step ladder from October, a new table from December, October finalised at RM1,600 and November at RM2,000:
  - the first claim opened the terms and sent `accept_terms: true`;
  - "Claim all" sent steps 1 and 2 in order;
  - the second claim did not ask for the terms again;
  - unspent rebate reached RM7.00;
  - the statement labels were right;
  - checked in light and dark, and at 390px with no horizontal overflow.

## 2026-11-09 — ADR-139 money review: no wrong money; three small fixes

The founder asked whether rebate profit, accounting and ledger are right. Three read-only forks ran in parallel (entitlement/claim/ledger, accounting, downstream readers). Each simulated through the real services on a throwaway sqlite DB.

**Found no wrong money.** Proven with numbers:
- an early capped claim pays itself back, and finalise writes an adjustment;
- double claims are blocked, and claims must go in order;
- closure forfeit + closure refund = balance;
- over three months, Σ reduction = Σ claimed, and the payable rolls forward exactly and equals Cash Position;
- the cash equation does not move;
- operating profit = profit − rebate + breakage, and the Bridge's unexplained difference stays 0;
- a claim at 00:00 KL lands in the right month;
- no reader counts a rebate as a cash top-up, a CHIP payment or a receipt.

**Accepted risks, unchanged** (ADR-139 by design):
- an overpayment against the final cap after a negative-profit order is not clawed back; admins get an email;
- turning eligibility off is final for a month that has ended.

**Fixed, branch `fix/rebate-report-labels`:**
- The Reports "Before reseller rebates" note now says which months count: each month whose 1st is in the range, less lapses. A range such as 2–30 Nov shows RM0 by design.
- Partners tab: "Reseller rebates, net of lapsed rewards". The figure can be negative.
- Transaction Register: rows for a rebate claim (`reseller_rebate`, with its step and month) and a closure forfeit (`reseller_rebate_forfeit`). Before, the year-end record had no trace of either. `RebateRegisterTest` covers it.
- `RebateLiability::payableSen`: a `ponytail` note. For an open month it uses entitlement as of now, which is exact for every reader today (month-end only).

**Gotcha:** `RebateFixtures::delivered()` writes no `paid_at`, so any test that reaches the register's `orderRecognitionRows` must set one. Real orders always have one.

**Verified:**
- `php artisan test`: 3,891 passed.
- Admin: `tsc`, lint and 22 unit tests pass.
- Pint is clean.

## 2026-11-09 — ADR-139 decision 46: ladder simulation re-run

The re-run happened before the first ladder is saved. Result: decision 3's grid is used as is. Numbers are in the ADR-139 addendum.

**Production, read-only:**
- Nothing new since 2026-11-08: 11 delivered wallet orders, none in November, and every month below RM500.
- SS drift is 97.6%, so the 94.56% design figure stays.

**Grid through the real `RebateEntitlement`** (a scratch test on sqlite, deleted afterwards):
- At 94.56% drift, none of 30 tier/step points is cut, and we keep 66.2–94.5%.
- At 90% and 85% drift, the 35% cap cuts the top steps and holds us at ≥ 65%.

**Gotcha:** on `main`, `monthBounds()` lives on `MonthlyAccountingSummaryService`, not `RecognisedRevenue`. A production script written against staging failed on it first.

**#765 (portal screens) and #766 (review fixes) were merged to `staging`.**

## 2026-11-10 — Cyber Bumblebee dark: the "Bumblebee Dark v3" palette

Branch `feature/bumblebee-dark-v3-palette`, into `staging`. The founder sent a Figma redesign of Bumblebee dark and asked for its colours only; layout, borders and shadows are unchanged. `tokensDark` in `storefront/src/lib/theme-presets.ts` was changed and synced to the portal copy. Affects PajoStore and FixFast Demo (production branding, read-only); PekanGame stays on Digital Architect light. Decision and deviations: ADR-113 2026-11-10 addendum. DESIGN.md's Tinted-Surface Rule names the exception.

**Constraint found:** `--color-ink` is both the 2px border and the bottom-nav pill whose labels are `surface`. A hairline-dark ink (v3's 8% white) would put the labels at 2.4:1, so ink is `#8C8983`.

**Verified** (throwaway e2e backend with the primary brand on Bumblebee dark; production storefront build on :3011; both deleted afterwards):
- Before and after screenshots of home, order and track order, at 1440 and 390. Layout is identical and there is no horizontal overflow.
- Measured with `getComputedStyle`: heading 15.8:1, muted 7.0:1.
- Computed: bottom-nav active label 5.4:1 (was 4.8). Inactive label 2.9:1 (was 2.7), below 4.5 before and after.

**Then the bottom nav** (founder reviewed the mobile screens and approved it):
- What was wrong: the grey `ink` pill looked off on charcoal, and the More drawer's backdrop lightened the page.
- The fix: `BottomNav` now reads `nav` / `on-nav` / `on-nav-muted` / `scrim`. Their defaults are the old ink and `surface`.
- Bumblebee dark: a charcoal pill, the active label in yellow (11.3:1), the inactive labels at 7.0:1, and a black scrim.
- Regression check with `getComputedStyle` on each preset: Digital Architect light and Bumblebee light are identical to before (6.32 / 15.64 and 6.52 / 16.18).
- Red Giants dark is unchanged (2.69 / 4.55). The same four tokens would fix it and the other dark presets.

**Gotchas:**
- A `git worktree` with a symlinked `node_modules` fails `next build`, because Turbopack refuses a `node_modules` outside its root. Build in the real checkout instead.
- Switching a throwaway brand's preset needs `.next/cache/fetch-cache` cleared and both servers restarted; this is the "scripted loop did not switch" mystery in the verification recipes. In zsh, pass `${=t}` to split a "preset mode" pair; a plain `$t` writes one bad value, which the storefront's schema check rejects, so it falls back to the default.
## 2026-11-10 — Release #769: ADR-139 reseller rebate and the Bumblebee Dark v3 palette

The founder asked for it. `staging` → `main`, merge `aac82832`, carrying #756–#768.

**Pre-release checks (production, read-only):**
- 5 new migrations;
- `ledger_entries` holds 267 rows, so the `dedupe_key` rebuild is instant;
- 0 failed wallet orders would be picked by the auto-refund sweep (the 4 Failed are already refunded);
- no pending migrations; health 200.

**CI incident:**
- The `main` run's `backend-concurrency` died after 50 minutes with "The hosted runner lost communication with the server". It was a GitHub runner failure: the same code passed on `staging` 20 minutes earlier, in 19 minutes.
- `deploy` was skipped as a result. The backend stayed on the old release (`/api/reseller-portal/rebate` answered 404) while Vercel had already deployed the frontends from `main`. No money was at risk: no ladder existed and no claim was possible.
- `gh run rerun --failed`: concurrency passed in 15 minutes and `deploy` succeeded.
- The workflow has no `timeout-minutes`, so a hung job would hold a deploy for up to 6 hours. A 30-minute limit is proposed.

**Checked after release (production, read-only):**
- `current` is `aac8283`. The 5 migrations ran in batch 69.
- `reseller_wallet_tier_changes` backfilled 3 rows (resellers 1, 2, 3 on tiers 4, 1, 5).
- `dedupe_key` includes `wallet_rebate`, and its unique index is present; 267 ledger rows, unchanged.
- 0 ladders and 0 milestones; all 3 resellers are eligible.
- Scheduler: `refund-failed-wallet-orders` (every minute), `apply-reseller-tier-changes` (hourly), `expire-rebate-rewards` 16:05 UTC and `finalize-rebate-months` 16:10 UTC (00:05 and 00:10 KL), `notify-rebate-progress` every 10 minutes.
- `/api/health`: ok (database, queue, Horizon, scheduler). `/api/reseller-portal/rebate` answers 401 (live).
- The laravel.log errors are all from before the release: 9 Nov 03:38 memory exhaustion (admin user 2) and a 13:02 Plunk 422.
- Storefront: pajostore.com serves `--color-surface: #111113` and `--color-nav: #18181B`; ohahastore.com (Bumblebee light) still `#fff8e1`. The Vercel production deployments for admin, portal and storefront are on `aac8283`.

**Next:** the founder saves the first ladder by 24 Nov (KL) for 1 Dec, then announces it to agents.

**Separate findings:**
- The 9 Nov dependency audit lists 7 advisories in 4 Symfony packages (for example `symfony/http-foundation`, `symfony/filesystem`).
- A memory-exhaustion error hit an admin request on 9 Nov at 03:38.
