<!-- BEGIN:nextjs-agent-rules -->

# This is NOT the Next.js you know

This version has breaking changes — APIs, conventions, and file structure may all differ from your training data. Read the relevant guide in `node_modules/next/dist/docs/` (resolved from this file's directory; in monorepos the `next` package may not be visible from the repo root) before writing any code. Heed deprecation notices.

This block is written and re-added by `next dev` — verify at `node_modules/next/dist/server/lib/generate-agent-files.js`. Removing it from a diff only re-creates the uncommitted change; committing it with your work keeps the tree clean.

<!-- END:nextjs-agent-rules -->

# reseller/ — Reseller Portal (ADR-059)

The third Next.js app, alongside `admin/` and `storefront/`. A reseller
signs in here to see their own storefront's orders, earnings ledger,
withdrawal history, and wholesale-tier subscription — and (59c) to edit
their branding/SEO/footer, markup, and catalog, and request payouts.

- **Auth is the `reseller` Sanctum guard** (backend `config/auth.php`,
  ADR-058 58a) — a completely separate guard from `admin/`'s. This app
  physically cannot render an admin screen; that separation is the point
  (ADR-059 decision 1).
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
- **Read-only in 59b.** Dashboard / Orders / Earnings / Subscription only.
  The write surface (storefront settings, catalog toggle, withdrawal
  request) and the impersonation banner land in 59c.

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
