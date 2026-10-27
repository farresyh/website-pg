# ADR-128: Partner portal visual system — the PekanGame Admin design system, as a "PekanGame Partner" variant, frontend only

> **Standing (2026-10-27):** Accepted; one shared component kit with the admin (2026-10-26 addendum); status-severity buttons are soft `-surface`/`-ink` pairs (2026-10-27 addendum). Extends [ADR-104](./ADR-104-admin-panel-visual-redesign.md)'s design system from `admin/` to `reseller/`; keeps [ADR-112](./ADR-112-affiliate-reseller-portal-mobile-first-redesign.md)'s navigation model and mobile acceptance criteria. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted (design), 2026-10-26. Grilled with the founder (`/mattpocock-skills:grilling`, Q1–Q15, three rounds). Mockups come first, in the founder's design-system artifact ("PekanGame Admin", `https://claude.ai/artifact/Jr2dGDUAFasPMedVayHPHe`), and are approved there before any code (PRD §16 item 133).

## Context

- The partner portal (`reseller/`, ADR-072: Affiliate and Reseller accounts in one app) still carries the TailAdmin-era tokens the admin dropped in ADR-104: a `brand-*` blue scale wired into PrimeReact, and Outfit. ADR-112 (2026-09) reorganised the portal for mobile but deliberately changed no visual system.
- The founder, 2026-10-26: the portal looks plain next to the admin, and on money pages it reads as untrustworthy. The example was Wallet (production screenshot): a bare balance box, a plain amount field and select, a full-width blue button, and an undifferentiated ledger table. For a page that takes a partner's money, "too simple" reads as "fishy".
- Data the portal has today (checked 2026-10-26): Affiliate has `GET /affiliate/dashboard` (sales today, sales this month, withdrawable earnings), orders, earnings, withdrawals, subscription, storefront and domains. Reseller has no dashboard endpoint: its pages read `GET /reseller-portal/wallet` (`balance`, `entries`) and orders. A pending top-up attempt and its `expires_at` come back only from `POST /wallet/topup`, never from a read.
- Production scale (read-only, 2026-10-26): 2 Reseller accounts (11 and 4 wallet orders, 9 in the last 30 days), 1 third-party Affiliate (the `fixfastapp.com` test account, 5 orders). Charts would be near-empty today.
- The repo already shares one admin-owned file with the portal by generation, not by package: `scripts/sync-theme-presets.mjs` writes `reseller/src/lib/theme-presets.ts`, and CI's `theme-preset-drift` job fails when the copy is stale. Each frontend builds from its own Vercel root, and `scripts/vercel-ignore-build.sh` skips a build when nothing under that app's directory changed.

## Decision

1. **Layer 1 only: a frontend-only reskin.** No backend route, response contract, payment, ledger, wallet, top-up, voucher, delivery, API-key or bot behaviour changes (ADR-112 decision 5 stands). Charts, trends, top games, a "needs attention" aggregate (item 25), a pending top-up read/resume/cancel (item 24) and any new settings field are layer 2, each with its own ADR.
2. **One design system, a "PekanGame Partner" variant.** Same tokens, type, spacing, radii, components and light/dark themes as ADR-104. The variant is the **nude/warm accent on the shell only** (sidebar header, the product chip, avatars), the colour the admin system already reserves for people and partners. Cyan still means "act here" and purple still means "this needs you", so a colour means the same thing in both apps.
3. **Name and identity:** the product is "PekanGame Partner", with a role chip (Reseller or Affiliate) and the account name. Replaces "Reseller Portal" / "Affiliate Portal".
4. **Navigation unchanged, layouts free.** ADR-112's destinations, mobile four-slot bar and More drawer stay. The sidebar is grouped like the admin (for an Affiliate: Overview · Sales · Store · Account). Each page's layout may be redesigned completely, using only data the current APIs return. Example, Wallet: a balance hero card, quick amounts (RM 50 / 100 / 200 / 500) beside the amount field, payment methods as cards with their logos, and a history with typed badges, copyable order numbers and date over time.
5. **Money is still never computed in the browser.** The top-up shows no fee estimate (the API knows the fee only after the attempt is created); the page says the fee is shown on CHIP's payment page.
6. **Trust signals on every page:**
   - a footer: "Operated by LWF Group Sdn. Bhd. (202601038896)", linking to pekangame.com's About Us, Terms and Refund Policy (admin-edited pages). The company line itself is fixed text in the portal; it practically never changes, and every editable part stays in the panel;
   - support WhatsApp and email in the shell, read from the primary brand's public `GET /api/catalog/branding` (`support_phone`, `support_email`), so they stay panel-configured;
   - "Payments processed by CHIP" with the FPX mark on top-up.
7. **Tokens shared by generation.** ADR-104's token block moves into one source file, `admin/src/app/tokens.css`, which the admin imports. A sync script (the `sync-theme-presets.mjs` pattern) writes `reseller/src/app/tokens.generated.css`, never hand-edited. The portal's variant sits in its own small file on top. CI's existing `theme-preset-drift` job gains the stale-copy check; the job keeps its name, because it is one of the ruleset's required checks (ADR-037 2026-10-22 addendum).
8. **Language stays English** for layer 1 (any language change goes with item 117).
9. **Mockups before code**, in the founder's design-system artifact under a "Partner Portal" section, with realistic sample data (real game names, plausible RM amounts, invented order numbers; no production partner data): Reseller Dashboard, Affiliate Dashboard, Orders, Order detail, Wallet, Earnings + Withdrawal, Login, and 390 px phone views of Dashboard and Orders. Profile, Storefront, Domains, API Keys and Subscription follow the approved settings-page pattern. The founder reviews by commenting in the artifact.
10. **Build order after approval:** PR 1 tokens, sync script, CI check and the new shell (sidebar, top bar, trust footer, product chip); PR 2 both Dashboards, Orders and Order detail; PR 3 Wallet, Earnings and Withdrawal; PR 4 the settings pages, Login and Set password. Every screen, the impersonation banner included, is in scope.

## Rationale

- Reusing ADR-104's system keeps one visual language for the business and one place to change it. A different primary colour would double the contrast work and make cyan mean two things.
- Generation plus a drift check is the pattern this repo already runs. A shared package outside `reseller/` would let `vercel-ignore-build.sh` skip a portal build when only the tokens changed, a silent stale deploy; a generated file inside `reseller/` changes with them, so the portal rebuilds.
- Frontend-only keeps the redesign off every money path. With two Resellers and one test Affiliate, charts would mostly show empty space; they are worth a backend once partners grow (item 13, self-serve signup).
- Trust is carried by who runs the platform, how to reach them and who processes the payment, all of which already exist; it needs no new data.

## Consequence to track

- ADR-112's acceptance criteria apply to every PR: both roles at 375/390 px, 768 px and ~1440 px, no page-level horizontal scroll, the bottom bar never covering content, separate Payment and Delivery labels, 44 px touch targets, visible focus, light and dark contrast measured with `getComputedStyle`, loading, empty and error states.
- A visual claim needs a live screenshot compared side by side with the approved mockup (the ADR-104 2026-09-17 audit found two dark-mode token bugs that code reading missed).
- If the company registration ever changes, the footer line is a code change; a panel field for it is a layer 2 candidate.
- Removing the portal's `brand-*` scale must leave no orphaned class: Tailwind v4 drops a colour class whose token doesn't exist without an error, so the portal needs the same guard as the admin's `color-tokens.test.ts`.

### 2026-10-26 build note — PR 1 (tokens, sync, CI guard, shell)

- `admin/src/app/tokens.css` now holds ADR-104's tokens (PrimeReact bridge, `.dark` values, both `@theme` blocks); `admin/src/app/globals.css` imports it. The admin's built CSS is the same bytes as before except one rule's position (`.dark input[type=date]` now follows the `.dark` block; the two selectors never compete).
- `scripts/sync-theme-presets.mjs` became `scripts/sync-shared-files.mjs`, writing both generated portal copies (theme presets, and `reseller/src/app/tokens.generated.css` with a do-not-edit banner). CI's `theme-preset-drift` job checks both and keeps its name.
- Decision 7's "variant in its own small file" proved unnecessary: the variant is the shell's use of `nude-100` / `nude-ink`, which are ADR-104 tokens, so the portal adds no token and no variant stylesheet.
- The portal gained the admin's colour-token test (`npm test` in CI). Its first run found three shades that had never existed in the portal's old token set either (`warning-300`, `warning-700`, `error-200` on the API keys, webhook, domains and storefront screens), so those colours had silently not rendered; they now use `warning-surface` / `warning-ink` / `danger-ink`. The admin's low-contrast-text check joins when PRs 2–4 move the pages.
- Support contacts come from `GET /api/catalog/branding` with no `X-Storefront-Host` header, which resolves to the primary brand (`ResolveStorefrontBrand`); CORS already allows the portal origin. No new env var.
- Found, not changed: neither app loads a JetBrains Mono file, so `font-mono` falls back to the system monospace in both. The artifact ships the files; adding them is a PR 2 item, when order numbers move to `code-id`.

### 2026-10-26 build note — PR 2 (dashboards, orders, order detail)

- Both dashboards, Orders and Order detail follow the mockups, using only existing API data. The Reseller dashboard reads wallet, profile (tier), API keys, webhook and the 5 newest orders; nothing is summed in the browser. Orders tabs show the server's `total` per delivery filter (`per_page=1` requests).
- Delivery words: `pending` (waiting on the supplier) reads "Pending", so a badge always matches the tab that lists it; "Processing" is `processing` only. Not started and partly delivered stay under All.
- Order detail never shows a supplier, supplier message, cost or platform margin. A failed or under-review order gets a plain-words alert and, when compensated, a resolution card (wallet refund, store voucher or restored voucher).
- Shared tokens: the admin's `.dark` block lacked `on-cyan` and `focus-ring`, which the artifact defines (its comment said they "inherit light"). White on the dark cyan-600 measured 2.09:1 on every admin primary button in dark mode; the artifact's dark label is 8.1:1 and its dark ring 8.4:1 on surface. Both now follow the artifact, in both apps.
- JetBrains Mono (the artifact's files) is declared in `tokens.css` and copied with it by the sync script, so `font-mono` is the real face in both apps.
- A phone-width table's screen-reader-only header label is absolutely positioned; with `body` as its containing block it escaped the table's scroll box and widened the page at 768px. The scroll box is `relative` now.

### 2026-10-26 build note — PR 3 (Wallet, Earnings, Withdrawal)

- Wallet follows the mockup. The payment methods are the API's active ones, each shown with the storefront's official mark. The marks and CHIP's "Powered by CHIP" lockup are synced from `storefront/public/images` by `scripts/sync-shared-files.mjs` and checked by CI. The fee is never estimated, and an empty method list says that online top-up is paused and to contact support (admin manual credit exists, ADR-073 PR-C). On phones the top-up form comes right after the balance.
- Ledger rows say where money came from from the row's own `reference_type`: `wallet_topup_attempt` is "Paid online through CHIP"; a receipt or no reference is "Credited by PekanGame". Credits are `success-ink` with a plus; debits are `ink` with a minus.
- Withdrawal copy follows the code. `Admin\WithdrawalController::approve` writes the ledger debit, `complete` stamps `processed_at`, and `reject` only applies to Pending, with no debit. One request can be open at a time, and it always pays the Profile bank account (masked to its last 4 digits here). "Earnings are added when an order is delivered" is true because every path into Delivered calls `creditProfit`.
- Found while verifying: `proxy.ts` sent every request without the session cookie to `/login`, including `public/` files. The image optimizer fetches those without the cookie, so the payment marks returned 400. `images/` is now excluded from the matcher, and protected pages still redirect.
- Typed ringgit is converted to sen in one place, `toSen`: digits with at most 2 decimals and more than zero. Tested.

### 2026-10-26 build note — PR 4 (settings pages, Login, Set password)

- Profile, Storefront (all five tabs), Domains, API keys with the webhook panel, Subscription, Impersonate, Login and Set password are on the semantic tokens. About 700 TailAdmin colour classes (`gray-*`, `brand-*`, `error-*` …, each with a `dark:` twin) were mapped one to one onto `ink` / `ink-muted` / `ink-subtle`, `surface` / `subtle`, `border` / `border-control`, `cyan-*` and the status tokens. Only class strings changed; the diff was checked line by line for any other change. The `dark:` twins were dropped because the tokens carry their own dark values.
- One `inputClass` (in `components/ui.tsx`) replaces five copies. The old ones set `outline-none` with no focus style, so a keyboard user could not see where focus was; the new one shows the 2px `focus-ring` and is 44px tall on phones. Primary buttons use `buttonClass`, and controls use the 8px radius.
- Login and Set password share `AuthLayout`, from the mockup: the nude panel (who runs the portal and what partners get) and the form, with the support WhatsApp from the primary brand's branding. Set password keeps its Malay copy.
- Subscription charges are ordinary spend, so they show in `ink`, not red. API key status is a badge.
- The portal colour test now also fails on any TailAdmin colour class or `dark:` colour override, so the old palette cannot come back.

### 2026-10-26 addendum — one shared component kit (decided, not built)

**Found after PR 4:** the portal's screens use their own small Tailwind kit (`reseller/src/components/ui.tsx`: `buttonClass`, `inputClass`, `Badge`, tables, `Pager` …) and no PrimeReact component at all. `primereact`, `@primereact/core`, `tailwindcss-primeui`, the `PrimeProvider` and the `NEXT_PUBLIC_PRIMEUI_LICENSE_KEY` check remain installed but do nothing. This departs from ADR-038 and the root AGENTS.md rule ("Admin/reseller UI is PrimeReact"). It was not raised before building; the screens work, but two kits would drift.

**Decision (founder, 2026-10-26):** one shared kit. The admin's `admin/src/components/ui` (the PrimeReact wrappers: Button, Input, Label, Tag, Select, OptionSelect, Switch, Tabs, Dialog, Popover, DataTable) and `admin/src/lib/utils.ts` are the source, and `scripts/sync-shared-files.mjs` generates the portal's copy, as with the tokens. The portal's own composites that the admin has no equivalent for (balance hero, facts, copyable ID, pager, ledger table, order table) stay portal-only, built on the shared kit.

**Why the admin kit goes first:** most of the kit still carries TailAdmin styling. `gray-*`/`brand-*` classes have `dark:` twins (only `Tag` is on the semantic tokens), inputs are 12px-radius with `focus:outline-hidden`, and the primary `Button` fills with PrimeReact's `--p-primary-color` (= `brand-500`, `#17879a`). White on that measures 4.23:1, below AA, where the design system's `cyan-600` is 5.6:1. A straight copy would take the portal back to the old look and fail its colour test.

**Build order (next session):**
1. Move the admin kit onto the semantic tokens, using the same one-to-one class mapping as PR 4. Point the PrimeReact bridge's primary colour at `cyan-600` / `cyan-700` / `on-cyan` in `tokens.css`. Every admin screen's buttons, inputs, selects, tables and dialogs change slightly; this needs a browser check of the admin.
2. Generate the kit into `reseller/src/components/ui/` and move the portal's composites to their own file. Replace `buttonClass`, `inputClass`, `Badge` and the hand-made tabs and tables with the kit. Keep `PrimeProvider`. Touch targets stay 44px on phones (ADR-112).

### 2026-10-27 addendum — status-severity buttons are soft, not solid

**Context.** Generating the shared kit into the portal hit the Button's status severities (`info`/`success`/`warn`/`danger`/`help`). They used Tailwind's stock solid fills (`bg-red-500 text-white` …) with `dark:` twins. The portal's `color-tokens.test.ts` rejects `dark:` colour overrides, and the design system defines status colour only as `-surface`/`-ink` pairs (ADR-104 decision 3). A solid `bg-*-ink` is not an option: the `-ink` tokens flip to a light value in dark mode, so white text on them fails contrast.

**Decision (founder, 2026-10-26, asked during shared-kit PR 2):** status-severity buttons use the soft pair: `-surface` fill, `-ink` text, a faint `-ink` border that strengthens on hover. Outlined and text variants use the same tokens. `danger` is a soft red, not a solid red. The primary (`default`) button stays solid `cyan-600`/`on-cyan`; `contrast` is `ink` on `surface`.

**Why.** One rule for both apps, no `dark:` overrides, and AA in both themes without per-theme tuning. Measured 2026-10-27 on the admin's Vouchers "Revoke" button: 5.70:1 light, 7.66:1 dark. A destructive action is still marked by its red and by its confirmation dialog; a solid fill was not needed for that.

**Consequence to track.** A future screen that wants a louder destructive button must not add a solid `bg-red-*` back into the kit; raise it as a new addendum.
