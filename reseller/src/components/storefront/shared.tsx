"use client";

/** ADR-060 PR-6 — form primitives shared across the four Storefront tabs. */

export const inputClass =
  "w-full rounded-lg border border-gray-300 px-3 py-2 text-theme-sm text-gray-800 outline-none focus:border-brand-400 disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90";

export function Field({
  label,
  hint,
  children,
}: {
  label: string;
  hint?: string;
  children: React.ReactNode;
}) {
  return (
    <label className="block space-y-1.5">
      <span className="text-theme-sm font-medium text-gray-700 dark:text-gray-300">{label}</span>
      {children}
      {hint && <span className="block text-theme-xs text-gray-500 dark:text-gray-400">{hint}</span>}
    </label>
  );
}

export function SaveButton({
  saving,
  saved,
  disabled,
  label = "Save changes",
}: {
  saving: boolean;
  saved: boolean;
  disabled?: boolean;
  label?: string;
}) {
  return (
    <div className="flex items-center gap-3">
      <button
        type="submit"
        disabled={saving || disabled}
        className="rounded-lg bg-brand-500 px-4 py-2 text-theme-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
      >
        {saving ? "Saving…" : label}
      </button>
      {saved && <span className="text-theme-sm text-success-600 dark:text-success-500">Saved.</span>}
    </div>
  );
}

export function Toggle({
  checked,
  onChange,
  disabled,
}: {
  checked: boolean;
  onChange: (next: boolean) => void;
  disabled?: boolean;
}) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      disabled={disabled}
      onClick={() => onChange(!checked)}
      className={`relative h-6 w-11 shrink-0 rounded-full transition-colors disabled:opacity-40 ${
        checked ? "bg-brand-500" : "bg-gray-300 dark:bg-gray-700"
      }`}
    >
      <span
        className={`absolute top-0.5 h-5 w-5 rounded-full bg-white transition-transform ${
          checked ? "translate-x-[22px]" : "translate-x-0.5"
        }`}
      />
    </button>
  );
}

export function InactiveNotice() {
  return (
    <p className="mb-4 rounded-lg bg-warning-50 px-4 py-3 text-theme-sm text-warning-700 dark:bg-warning-500/15 dark:text-warning-500">
      Your account is inactive, so these settings are read-only. Contact support.
    </p>
  );
}

export function TabLoading() {
  return <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>;
}
