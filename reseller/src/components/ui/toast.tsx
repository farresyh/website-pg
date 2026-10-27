'use client';

/**
 * Action feedback (toasts) — ADR-129.
 *
 * One PrimeReact headless toaster for the whole app: the module-level
 * `toast()` manager is the single writer, and this `ToastProvider` is the
 * single renderer (it subscribes via `useToaster()`, which reads the same
 * default manager). There is deliberately no bespoke toast store or React
 * context here — two managers is the failure mode where a toast never
 * appears.
 *
 * Call sites use `notify.*` (or `useToast()`), never `toast()` directly,
 * so the library, duration and wording live in one file.
 *
 * Styling uses the semantic status tokens only (`-surface` / `-ink`), the
 * same pairs as `tag.tsx` and `button.tsx` (ADR-104 decision 3). They carry
 * their own `.dark` values, so there are no `dark:` overrides and the file
 * passes both apps' `color-tokens.test.ts` (the portal's test rejects any
 * `error-<shade>` class and any `dark:` colour override).
 */

import * as React from 'react';
import { toast, useToaster } from '@primereact/headless/toaster';
import type { ToastId, ToastType } from 'primereact/toaster';
import { cn } from '@/lib/utils';

/**
 * How long a toast stays before it leaves on its own. `Infinity` means
 * sticky — it stays until dismissed (used for money-action failures, so a
 * failed approve/credit/voucher cannot vanish before it is read).
 */
const DEFAULT_SUCCESS_MS = 5000;
const DEFAULT_ERROR_MS = 8000;

/** At most this many toasts render at once; the rest wait their turn. */
const MAX_VISIBLE = 4;

type Severity = 'success' | 'info' | 'warn' | 'error';

/**
 * PrimeReact's `ToastSeverity` is wider than ours; collapse every non-status
 * severity onto the neutral look, and map `error` onto the semantic `danger`
 * pair (the design system has no `error-*` surface token).
 */
const SEVERITY_STYLES: Record<Severity, { shell: string; icon: string }> = {
  success: {
    shell: 'border-success-ink/25 bg-success-surface text-success-ink',
    icon: 'text-success-ink',
  },
  info: {
    shell: 'border-info-ink/25 bg-info-surface text-info-ink',
    icon: 'text-info-ink',
  },
  warn: {
    shell: 'border-warning-ink/25 bg-warning-surface text-warning-ink',
    icon: 'text-warning-ink',
  },
  error: {
    shell: 'border-danger-ink/25 bg-danger-surface text-danger-ink',
    icon: 'text-danger-ink',
  },
};

function severityOf(toast: ToastType): Severity {
  switch (toast.severity) {
    case 'success':
      return 'success';
    case 'info':
      return 'info';
    case 'warn':
      return 'warn';
    case 'error':
      return 'error';
    default:
      // normal / secondary / contrast and the loading toast read neutral.
      return 'info';
  }
}

function SeverityIcon({ severity }: { severity: Severity }) {
  const cls = SEVERITY_STYLES[severity].icon;
  const common = { className: cn('size-5 shrink-0', cls), viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round' } as const;

  switch (severity) {
    case 'success':
      return (
        <svg {...common} aria-hidden="true">
          <circle cx="12" cy="12" r="9" />
          <path d="m8.5 12.5 2.5 2.5 5-5.5" />
        </svg>
      );
    case 'error':
      return (
        <svg {...common} aria-hidden="true">
          <circle cx="12" cy="12" r="9" />
          <path d="m9 9 6 6M15 9l-6 6" />
        </svg>
      );
    case 'warn':
      return (
        <svg {...common} aria-hidden="true">
          <path d="M10.3 4.6 2.9 17.5a1.7 1.7 0 0 0 1.5 2.5h15.2a1.7 1.7 0 0 0 1.5-2.5L13.7 4.6a1.7 1.7 0 0 0-3.4 0Z" />
          <path d="M12 9.5v4M12 16.5h.01" />
        </svg>
      );
    case 'info':
      return (
        <svg {...common} aria-hidden="true">
          <circle cx="12" cy="12" r="9" />
          <path d="M12 11v5M12 8h.01" />
        </svg>
      );
  }
}

function ToastItem({
  toast: t,
  onDismiss,
  regionRef,
}: {
  toast: ToastType;
  onDismiss: (id: ToastId) => void;
  regionRef: React.RefObject<HTMLDivElement | null>;
}) {
  const [paused, setPaused] = React.useState(false);
  const duration = t.duration ?? DEFAULT_SUCCESS_MS;
  const severity = severityOf(t);
  const rootRef = React.useRef<HTMLDivElement>(null);
  // The manager always assigns an id on add (see `S()` in the headless
  // toaster); the type marks it optional only because a caller may pass
  // `id` explicitly before that assignment.
  const id = t.id as ToastId;

  React.useEffect(() => {
    // A loading toast or a sticky (Infinity) toast never auto-dismisses; a
    // hovered one pauses. Success fires only because the caller added the
    // toast after its promise resolved (ADR-129 decision 3).
    if (t.loading || paused || duration === Infinity) return;
    const timer = setTimeout(() => onDismiss(id), duration);
    return () => clearTimeout(timer);
  }, [t.loading, paused, duration, onDismiss, id]);

  const dismissible = t.dismissible !== false && !t.loading;

  /**
   * Explicit dismissal (close button / keyboard) returns focus to the
   * region when it was inside the toast being closed, so a keyboard user
   * is never left on a removed element. The auto-dismiss timer above does
   * not move focus — it should never steal focus mid-typing.
   */
  function handleExplicitDismiss() {
    const focusedInside = rootRef.current?.contains(document.activeElement) ?? false;
    onDismiss(id);
    if (focusedInside) regionRef.current?.focus();
  }

  return (
    <div
      ref={rootRef}
      role={severity === 'error' ? 'alert' : 'status'}
      aria-live={severity === 'error' ? 'assertive' : 'polite'}
      aria-atomic="true"
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
      className={cn(
        'pointer-events-auto flex w-full items-start gap-3 rounded-lg border p-3.5 shadow-md',
        SEVERITY_STYLES[severity].shell,
      )}
    >
      <SeverityIcon severity={severity} />
      <div className="min-w-0 flex-1">
        {t.title !== undefined && <div className="text-sm font-semibold">{t.title}</div>}
        {t.description !== undefined && <div className="mt-0.5 text-sm opacity-90">{t.description}</div>}
      </div>
      {dismissible && (
        <button
          type="button"
          onClick={handleExplicitDismiss}
          aria-label="Dismiss notification"
          className="ml-1 -mr-1 -mt-1 rounded p-1 text-sm leading-none opacity-60 hover:opacity-100 focus-visible:outline-2 focus-visible:outline-focus-ring"
        >
          ✕
        </button>
      )}
    </div>
  );
}

/**
 * The one mount, rendered once in each app's root layout (inside
 * `PrimeProvider`). The fixed region sits above the app header (z-99999) and
 * PrimeReact overlays (z-100000 modal/overlay), so a toast stays visible
 * after a dialog closes.
 */
export function ToastProvider({ children }: { children: React.ReactNode }) {
  const { toasts, removeFromQueue } = useToaster({ position: 'top-right', gap: 12 });
  const regionRef = React.useRef<HTMLDivElement>(null);

  return (
    <>
      {children}
      <div className="pointer-events-none fixed inset-0 z-[100600] flex items-start justify-end p-4">
        <div
          ref={regionRef}
          tabIndex={-1}
          aria-label="Notifications"
          className="flex w-full max-w-sm flex-col gap-3 outline-none"
        >
          {toasts.slice(0, MAX_VISIBLE).map((t) => (
            <ToastItem key={t.id as ToastId} toast={t} onDismiss={removeFromQueue} regionRef={regionRef} />
          ))}
        </div>
      </div>
    </>
  );
}

type NotifyOptions = { duration?: number; dismissible?: boolean };

/**
 * The one facade call sites use. `promise` drives a loading → success/error
 * toast off the actual promise, so a success toast can never fire before the
 * server answered (ADR-129 decision 3).
 */
export const notify = {
  success: (title: React.ReactNode, description?: React.ReactNode, opts: NotifyOptions = {}) =>
    toast.success({ title, description, duration: opts.duration ?? DEFAULT_SUCCESS_MS, dismissible: opts.dismissible ?? true }),
  info: (title: React.ReactNode, description?: React.ReactNode, opts: NotifyOptions = {}) =>
    toast.info({ title, description, duration: opts.duration ?? DEFAULT_SUCCESS_MS, dismissible: opts.dismissible ?? true }),
  warn: (title: React.ReactNode, description?: React.ReactNode, opts: NotifyOptions = {}) =>
    toast.warn({ title, description, duration: opts.duration ?? 6000, dismissible: opts.dismissible ?? true }),
  error: (title: React.ReactNode, description?: React.ReactNode, opts: NotifyOptions = {}) =>
    toast.error({ title, description, duration: opts.duration ?? DEFAULT_ERROR_MS, dismissible: opts.dismissible ?? true }),
  /**
   * Money-action failures (approve/reject/complete withdrawal, wallet credit,
   * voucher issue/revoke, refund) — sticky and dismissible, so the failure
   * cannot vanish before it is read. ADR-129 decision 6 (founder chose
   * sticky over keeping the inline banner).
   */
  stickyError: (title: React.ReactNode, description?: React.ReactNode) =>
    toast.error({ title, description, duration: Infinity, dismissible: true }),
  promise: toast.promise,
};

/** Thin sugar for `notify`, kept so call sites read as a hook. */
export function useToast() {
  return notify;
}
