# ADR-129: Action feedback (toasts) across admin, middleware and the partner portal

> **Standing (2026-10-27):** Accepted (design). One PrimeReact headless toaster, one shared `components/ui/toast.tsx` wrapper generated into the portal, a toast-vs-inline policy, and a promise-driven success that can never fire before the server answers. Extends the [ADR-104](./ADR-104-admin-panel-visual-redesign.md) / [ADR-128](./ADR-128-partner-portal-visual-system.md) design system and shared kit. Build and live status: [`prd.md` §15](../prd.md); open work: §16 item 136. The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted (design), 2026-10-27. Reviewed with the founder in conversation (the plan plus a Claude code review, reconciled against the repo), then this ADR was written before any build.

## Context

- Neither frontend gives consistent feedback after an action. The admin (`admin/`, including the middleware panel under `admin/src/app/middleware`) and the partner portal (`reseller/`) show success and error as inline page-top `<p>` state (`setError` / `setNotice` / "Saved.") inside each modal or page. 112 admin files and 20 portal files use this pattern. There is no toast, no flash query parameter, and no third-party library (sonner / react-hot-toast / notistack — none installed).
- The result the founder names: after an action, an admin can't tell whether it ran, is running, or failed — the feedback is a quiet line at the top of the page, or nothing.
- ADR-104 (admin) and ADR-128 (portal) already established one design system and one **shared component kit**: `admin/src/components/ui/*` is the source, and `scripts/sync-shared-files.mjs` generates the portal's copy verbatim; CI's `theme-preset-drift` job fails on a stale copy.
- PrimeReact `^11.1.0` (already a dependency of both apps) ships a **headless toaster**: a module-level `toast()` manager (`.success/.error/.info/.warn/.promise/.update/.dismiss`) and a `useToaster()` hook that subscribe to the same manager, plus compound `Toast.*` primitives. No new dependency is required.

## Decision

1. **One engine, not a bespoke store.** Feedback goes through PrimeReact's headless toaster (`@primereact/headless/toaster`): the module-level `toast()` manager for calls and `useToaster()` for the one render mount. `toast()` and `useToaster()` share the same default manager, so a call always reaches the mount. We do **not** build our own toast state/context/reducer — two managers is the failure mode where a toast never appears.
2. **One shared wrapper.** A single `admin/src/components/ui/toast.tsx` exports `ToastProvider` (the mount) and a `notify` facade plus a `useToast()` hook (thin sugar returning `notify`). The file is added to the `KIT` list in `scripts/sync-shared-files.mjs`, so the portal's `reseller/src/components/ui/toast.tsx` is **generated, never hand-edited**.
3. **The `notify` API** carries the semantics: `notify.success / info / warn / error` and `notify.promise(promise, { loading, success, error })` for async actions. **Success fires only when the promise resolves** — never optimistically, never before `await` returns, never in a `catch`.
4. **Styling uses the semantic status tokens only** — `bg-success-surface text-success-ink`, `bg-danger-surface text-danger-ink`, `bg-warning-surface text-warning-ink`, `bg-info-surface text-info-ink`, neutral `bg-surface text-ink border-border` — the same `-surface`/`-ink` pairs `tag.tsx` and `button.tsx` use (ADR-104 decision 3). These tokens carry their own `.dark` values, so there are no `dark:` overrides; the file passes **both** `color-tokens.test.ts` (the portal's test rejects `error-<shade>` and any `dark:` colour override, which a hand-written or admin-only wrapper would trip).
5. **A toast-vs-inline policy**, not a blanket replace:
   - **Toast** = the transient outcome of a user action (create / update / delete / approve / reject / retry / resend / export / upload / save). An async action uses `notify.promise` (loading → success/error).
   - **Inline (keep)** = field validation (Laravel 422 `errors.*`), page/section load errors, empty states, persistent warnings and "needs attention" banners, and destructive confirmation dialogs.
   - One action → one toast; never an inline error **and** a toast for the same thing; no automatic toast of every error from `apiRequest` (too noisy, double-reports, and validation must stay anchored to its field).
6. **Money-action errors are sticky.** For approve / reject / complete withdrawal, wallet credit, voucher issue / revoke and refund, a failed action shows a sticky toast (`duration: 0`, `dismissible: true`) so the failure cannot vanish before it is read. The founder chose sticky over keeping the inline banner.
7. **Accessibility and motion.** Success/info toasts are `role="status"` + `aria-live="polite"`; error toasts are `role="alert"` + `assertive`. Dismiss keeps focus management (the headless hook provides it), and motion honours `prefers-reduced-motion`.
8. **Frontend-only.** No backend route, ledger, order-state, voucher, wallet or money-logic change. The success toast is driven by the actual 2xx response, so a money screen can never show "Saved" for an action that failed.

## Rationale

- One manager + one facade means swapping the library or retuning duration/position later is a one-file change, not a sweep across ~130 files.
- Generation plus a drift check is the repo's existing, proven pattern (ADR-128). A generated file inside `reseller/` also makes `vercel-ignore-build.sh` rebuild the portal when the wrapper changes.
- `notify.promise` answers the actual complaint — "is it running / did it succeed" — with a loading state that resolves to a real outcome, so the UI can't lie on money screens.
- The token-only rule keeps contrast AA in light and dark by construction (the shared-kit status buttons measured 5.70:1 light / 7.66:1 dark on 2026-10-27, ADR-128's addendum), instead of a per-theme pass that code reading misses.

## Consequence to track

- The sweep is incremental (PR 1: wrapper + provider + 2–3 screens — Withdrawals, Orders, the portal Withdrawal; later PRs the rest). It stays open as §16 item 136 until every action outcome uses `notify`.
- Each sweep PR must not leave a `setError`/`setNotice` removed without a replacement, and must not add a `notify` call inside a `catch` or before the `await` resolves.
- The portal's `toast.tsx` must stay generated; the drift check guards it. Editing it by hand and re-running the script is a diff the reviewer rejects.
- Any existing Playwright assertion on inline success text is audited in the same PR that removes that text (only one delivery-outcome text was found in `e2e/`, but this stays a per-PR check).
- The wrapper adds no dependency; `@primereact/headless/toaster` is already shipped with PrimeReact 11.1.0.
