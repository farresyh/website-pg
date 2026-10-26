"use client";

import { buttonClass } from "@/components/ui";

/** ADR-060 PR-6 — form primitives shared across the four Storefront tabs. */

export { inputClass } from "@/components/ui";

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
      <span className="text-theme-sm font-medium text-ink-muted">{label}</span>
      {children}
      {hint && <span className="block text-theme-xs text-ink-subtle">{hint}</span>}
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
        className={buttonClass("primary")}
      >
        {saving ? "Saving…" : label}
      </button>
      {saved && <span className="text-theme-sm text-success-ink">Saved.</span>}
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
        checked ? "bg-cyan-600" : "bg-border-strong"
      }`}
    >
      <span
        className={`absolute top-0.5 h-5 w-5 rounded-full bg-surface transition-transform ${
          checked ? "translate-x-[22px]" : "translate-x-0.5"
        }`}
      />
    </button>
  );
}

export function InactiveNotice() {
  return (
    <p className="mb-4 rounded-lg bg-warning-surface px-4 py-3 text-theme-sm text-warning-ink">
      Your account is inactive, so these settings are read-only. Contact support.
    </p>
  );
}

export function TabLoading() {
  return <p className="text-sm text-ink-subtle">Loading…</p>;
}
