# ADR-121: Admin MFA (TOTP) and a cookie-session admin login: opt-in per account, step-up on five dangerous actions

> **Standing (2026-10-18):** In force; ADR-124 adds three step-up routes (2026-10-18 addendum). Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted (design), 2026-10-14. Grilled with the founder in five rounds via `/mattpocock-skills:grilling` (Q1–Q17), then stress-tested against code and production (read-only). PRD §16 item 10, AUTH-7.

**Context:** An admin account can change the CHIP credentials (customer payments would go to another account), change a supplier's API credentials (orders would draw down someone else's balance at our cost), credit a reseller wallet by hand, approve an affiliate withdrawal, and create or edit other admins. Today a password alone is enough for all of these. The admin login is a Sanctum bearer token in `sessionStorage` (`admin/src/lib/session.ts`, ADR-009), so it expires after 12 h (`config/sanctum.php`) and every new tab asks for a login again. Facts checked on 2026-10-14:
- Production has one admin account (`super_admin`, `mfa_enabled = false`). `admin_users.mfa_secret` (encrypted cast) and `mfa_enabled` already exist; no TOTP code exists.
- 15 files in `admin/` send `Authorization: Bearer` (`api-client.ts`, `echo.ts`, the `lib/*` fetchers, components given a `token` prop, and the server route `app/api/logout/route.ts`).
- The `web` guard is already a session guard on `admin_users`; `SESSION_DRIVER=database`.
- `admin.pekangame.space` and `api.pekangame.space` are same-site. Local dev is not: admin on `localhost:3000` calls `https://kedairuncit-backend.test`.
- CORS allows only `ADMIN_URL`, `STOREFRONT_URL`, `RESELLER_PORTAL_URL`, so admin Vercel previews cannot call the prod API today.
- The prod clock is NTP-synchronised (`timedatectl`).
- `PricingService` already refuses a selling price below cost.

MFA for `affiliate_users` and resellers is out of scope (PRD §16 item 10 records why).

**Decision:**

1. **Admin login moves to a cookie session (Sanctum SPA mode).**
   - The admin route group authenticates by the `web` session only. Admin bearer tokens are no longer issued. A migration deletes every existing `personal_access_tokens` row whose tokenable is an `AdminUser`, and a test pins that an admin bearer token gets 401. Affiliate tokens (`auth:affiliate`, impersonation) and reseller credentials are untouched.
   - The session cookie is host-only on `api.pekangame.space` (`SESSION_DOMAIN` stays null), `HttpOnly`, `Secure`, `SameSite=Lax`, so the storefront, portal and docs subdomains never receive it. The admin app fetches its CSRF token from a small JSON endpoint and sends it as `X-CSRF-TOKEN`. It does not read an `XSRF-TOKEN` cookie, which would need a domain-wide cookie.
   - CORS: `supports_credentials = true`, and the admin origin is listed in `SANCTUM_STATEFUL_DOMAINS`.
   - Every admin tab in one browser shares the login.
   - **Lifetime:** at most 7 days from login, and the login ends after 2 days unused. Idle time is `SESSION_LIFETIME = 2880`. The 7-day cap is an `authenticated_at` value in the session, checked by middleware. Both are server-side.
   - Logout ends the session, so every tab in that browser is logged out.
   - Changing a password ends that admin's other sessions.
   - The admin frontend drops every `Bearer` header and `token` prop in favour of `credentials: 'include'`. Echo authenticates with `withCredentials`. The presence-only httpOnly cookie behind `proxy.ts`'s redirect gate stays as it is: it is not a security boundary.
   - Local dev: the admin app's `NEXT_PUBLIC_API_URL` becomes `http://localhost:8000` (the `serve` process `composer run dev` already starts), because `localhost` → `*.test` is cross-site and the cookie would not be sent. e2e already runs both sides on `localhost`.

2. **TOTP via `pragmarx/google2fa`, QR via `bacon/bacon-qr-code`** (SVG, rendered by the backend). Codes are 6 digits, 30-second steps, ±1 step window. Fortify is not used: it is built around Blade/session flows and brings far more than this needs.

3. **MFA is opt-in per account, switched on by an admin.**
   - `/admin/users` gets a **"Require MFA"** action. It only sets a flag; no QR is shown there.
   - The action is one-way and needs no step-up: it only adds protection, and the first admin to use it has no code yet. Turning MFA off is only possible over SSH (decision 7).
   - `/admin/users` shows each account's MFA state: None / Awaiting setup / Active.

4. **Login flow, all on `/login`:**
   1. The admin enters email and password.
   2. If the account does not require MFA, the login completes.
   3. If it does, the admin is **not** logged in yet. The session holds a pending admin id that expires after 5 minutes.
   4. An account **awaiting setup** sees a QR and confirms one code. Its 8 recovery codes are then shown once, and only after that is MFA active.
   5. An **active** account enters a 6-digit code or one recovery code.
   6. Only then does Laravel log the admin in and regenerate the session id.
   7. Each admin scans the QR with their own phone, so a secret never passes through another person's hands.

5. **Step-up for five dangerous actions.**
   - One route middleware, `mfa.stepup`, covers these actions:
     - **CHIP credentials:** `PaymentGatewayController@update`.
     - **Supplier credentials:** `SupplierController@store`, and `SupplierController@update` only when `api_config` is in the request. Routine supplier edits stay code-free.
     - **Manual reseller wallet credit:** `ResellerWalletController@credit`.
     - **Affiliate withdrawal approval:** `WithdrawalController@approve` only. `reject` and `complete` stay code-free, because `complete` only records a transfer the founder made from the bank.
     - **Admin account changes:** `AdminUserController@store`, `@update` and `@updateStatus`, but not "Require MFA".
     - Regenerating one's own recovery codes also needs step-up.
   - An account without active MFA is **refused** these actions outright, with a message telling the admin to turn MFA on.
   - An account with active MFA is asked for a code once. A step-up then stays valid for **5 minutes** in that session, checked server-side.
   - A test pins the exact set of routes that carry `mfa.stepup`, so a new dangerous route cannot be added without updating the set.

6. **Code hygiene and abuse limits.**
   - Wrong codes (TOTP or recovery, at login or step-up) count through the existing `AccountLoginThrottle` and lock the account the same way wrong passwords do.
   - The last accepted TOTP time-step is stored per admin, so a code can never be accepted twice.
   - Recovery codes: 8, single-use, stored hashed, deleted when used.
   - "Regenerate recovery codes" in the header user dropdown (`UserDropdown`) invalidates all old codes. There is no profile page, and this does not add one.
   - The TOTP and recovery logic lives in one service. The routes and middleware call it, and nothing else touches `mfa_secret`.

7. **Recovery and reset.**
   - A lost phone: sign in with one recovery code.
   - A lost phone and lost codes: run `php artisan admin:mfa-reset {email}` over SSH on the Forge box. It clears the secret, the recovery codes and the require-MFA flag, and deletes every session of that admin. The admin then logs in with a password only. A super admin can switch "Require MFA" back on, and the admin re-enrols on the next login.

8. **Security emails (Plunk) to the affected admin, and to every super admin:** a recovery code was used; an account was locked by wrong codes; MFA was reset over SSH. A normal login sends no email.

**Rationale:**
- **Cookie instead of `localStorage`.** A 7-day bearer token in `localStorage` is the most valuable thing an XSS could steal, and it would stay usable from anywhere until it expired. An httpOnly cookie limits an XSS to acting inside the open tab. The `web` guard was already a session guard, so this changes how the frontend sends auth rather than inventing a new auth system.
- **The founder chose opt-in (option B) over mandatory MFA.** It keeps the panel usable for an account that has not set up MFA, while the actions that move money or control stay locked without it. The weakest account cannot reach those actions, so the protection does not hinge on every account being enrolled.
- **Issuing vouchers stays code-free on purpose.** Issuing a voucher is the daily recovery path for a failed order (ADR-004), so a code there would be friction on routine work. This is an **accepted risk** (founder, 2026-10-14): someone with an admin password can issue vouchers. Each issue is recorded in the ledger (ADR-083). A value threshold is the upgrade path if it is ever needed.
- Refund/resend and affiliate impersonation also stay code-free:
  - Refund and resend have their own state guards (ADR-105).
  - An affiliate withdrawal still needs an approval that is itself stepped up.
- A package over a self-written TOTP: security code that many people have reviewed, for two small dependencies.

**Consequence to track:**
- **Revises ADR-009:** the admin app now calls the API with a cookie session, not a bearer token (see its 2026-10-14 addendum).
- **Release-day sequence for the founder:**
  1. Log in again, since all admin tokens are deleted.
  2. In `/admin/users`, set "Require MFA" on your own account.
  3. Log out, log back in and enrol.
  - Until step 3, the five step-up actions are refused.
- `backend/AGENTS.md` local-dev gotchas: the admin dev API URL is `localhost:8000`, and why.
- e2e: the admin login spec is updated. An e2e admin account does not require MFA, so its golden paths run unchanged unless they hit a step-up route.
- Not a money-math change. It gates money actions but reads and writes no price, cost, ledger or wallet column, so the ADR-118 code-trace pass does not apply beyond confirming each gated route (done during the stress test).

### 2026-10-14 addendum — build notes and where the build differs from the design

Built on `feature/admin-mfa`. Unless a point below says otherwise, the decisions above hold as written.

- **Admin auth guard (decision 1).** The admin route group keeps `auth:sanctum`, not a plain `web` guard. `AppServiceProvider` refuses every access token whose tokenable is an `AdminUser` (`Sanctum::authenticateAccessTokensUsing`), so the effect is the same (session only, pinned by a test). The ~60 feature tests that use `Sanctum::actingAs` keep working unchanged.
- **CORS (decision 1).** `supports_credentials` stays `false` globally. A new `AllowAdminCorsCredentials` middleware turns it on per request, only for the `ADMIN_URL` origin(s). `CorsConfigTest` already pinned that a custom affiliate domain never gets credentials (ADR-078), and a global `true` broke that test.
- **Login only from the admin app.** `/api/login`, `/api/login/mfa` and `/api/csrf-token` answer 400 without a session, which means a request from outside a Sanctum stateful origin. The default stateful list is now `localhost:3000` plus the host of `ADMIN_URL`, so production needs no new env var.
- **Session lifetime** is fixed at 2 days in `config/session.php` rather than read from env, because production's `.env` still says `SESSION_LIFETIME=120`. Production needs no `.env` change for this release.
- **Security emails (decision 8)** go through the existing Plunk alert path (`BackupFailureAlerter`, area `Security`), which emails every active admin. That is a superset of "the affected admin and every super admin"; today both are the founder.
- **TOTP library gotcha.** `pragmarx/google2fa`'s `verifyKeyNewer()` returns `true`, not the matched time-step, when the old time-step is `null`. Storing "now" then would let a code from the next step be accepted twice. `AdminMfa` always passes an int.
- **Recovery codes** are `xxxxx-xxxxx`, drawn from an alphabet with no look-alike characters (no 0/o, 1/l/i), and stored as sha256 hashes (about 50 random bits each, so a fast hash is enough).
- **Admin frontend.**
  - The cached admin profile (id, role, name, email, MFA status) moved from `sessionStorage` to `localStorage` so every tab shares it. It holds no credential.
  - All bearer-token plumbing was removed: about 1,200 references across 124 files.
  - `api-client` sends the session cookie with every call and adds the CSRF header to mutating ones. It retries once on 419, and on `mfa_step_up_required` it waits for `StepUpDialog`.
  - The eight copies of the Blob download code are now one `apiDownload()`.
  - The dashboard used to clear the profile cache on any `/api/me` error, including a fetch aborted by navigation. Because the cache is now shared, that would log every tab out, so the dashboard now relies on the central 401 handling.
- **Local dev and e2e.** The admin app must call the API on the same site. `localhost:3000` → `https://kedairuncit-backend.test` is cross-site, so the session cookie is not sent. Use `NEXT_PUBLIC_API_URL=http://localhost:8000` (backend `AGENTS.md` gotchas 6–7). e2e now runs `SESSION_DRIVER=file`, because the array driver is emptied on every `php artisan serve` request.
- **Known limit (accepted).** An account awaiting setup is enrolled by whoever next signs in with its password. Require MFA only on accounts whose password is known to be safe, for example straight after creating them.


### 2026-10-18 addendum — ADR-124 adds three guarded actions

[ADR-124](./ADR-124-customer-account-credentials.md) (customer game logins) puts three more routes behind `mfa.stepup`: revealing a customer's login, replacing it, and saving the Customer Login Policy. The guarded set is no longer "five actions": `AdminMfaStepUpTest::test_exactly_these_routes_require_step_up` is the one list of them.
