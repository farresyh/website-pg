<!-- BEGIN:nextjs-agent-rules -->

# This is NOT the Next.js you know

This version has breaking changes — APIs, conventions, and file structure may all differ from your training data. Read the relevant guide in `node_modules/next/dist/docs/` (resolved from this file's directory; in monorepos the `next` package may not be visible from the repo root) before writing any code. Heed deprecation notices.

This block is written and re-added by `next dev` — verify at `node_modules/next/dist/server/lib/generate-agent-files.js`. Removing it from a diff only re-creates the uncommitted change; committing it with your work keeps the tree clean.

<!-- END:nextjs-agent-rules -->

# reseller/ — Partner Portal (ADR-059, ADR-072)

The fourth Next.js app, alongside `admin/` and `storefront/` (runs on
`:3002`). **One app, two account types** since ADR-072:

- **Affiliate** — a whitelabel storefront owner. Nav: Dashboard / Orders /
  Earnings / Withdrawals / Storefront config / Subscription / Domain. Earns a
  margin per order into an earnings ledger, requests manual payouts, runs a
  branded `Host`-resolved storefront (ADR-060) on a custom domain. *This is
  the entity ADR-058/059 originally called "Reseller".*
- **Reseller** — a prepaid-wallet, spend-only account (ADR-072/073). Nav:
  Dashboard / Orders / Wallet / API Keys / Profile. Tops up the wallet via
  CHIP, spends it placing orders through three channels: this portal, the
  REST API (per-tenant keys — ADR-074, docs at `/docs/api`), and a WhatsApp
  bot (OpenWA — ADR-075). Never earns.

The account type is **backend-enforced by `EnsureAccountType`** on every
reseller-guard endpoint, not just a hidden nav tab.

- **Auth is the `affiliate` Sanctum guard** (backend `config/auth.php`) —
  renamed from `reseller` in ADR-072. `affiliate_users.owner_type`/`owner_id`
  is polymorphic: both an `Affiliate` and a wallet `Reseller` log in through
  the same `/api/affiliate/login`. A completely separate guard from
  `admin/`'s — this app physically cannot render an admin screen (ADR-059
  decision 1).
- **Same browser-calls-Laravel-directly model as `admin/`** (ADR-009):
  the bearer token lives in `sessionStorage` (`lib/session.ts`), a
  presence-only httpOnly cookie drives `proxy.ts`'s optimistic redirect
  (never a security boundary — Laravel enforces on every request).
- **Mirror `admin/`'s conventions** — `useClientSession()` (the
  `useSyncExternalStore` shape that dodges the `react-hooks/set-state-in-effect`
  trap, ADR-038 decision 8), `lib/api-client.ts`, the `@/` path alias,
  PrimeReact-Tailwind (ADR-038) with the shared `globals.css` design
  tokens. `admin/` is the up-to-date reference for every Next.js 16
  pattern this app needs.

## Env

- `NEXT_PUBLIC_API_URL` — Laravel backend base URL (e.g.
  `http://kedairuncit-backend.test` or `http://127.0.0.1:8000`).
- `NEXT_PUBLIC_PRIMEUI_LICENSE_KEY` — the founder's free PrimeUI
  "Community" tier key (same value as `admin/`; verification runs
  client-side so it must be `NEXT_PUBLIC_`). See `admin/`'s
  `components/prime-provider.tsx` for why.

## Build & Test

```bash
npm run dev     # next dev on :3002 (matches backend RESELLER_PORTAL_URL)
npm run build   # + npm run lint
```
