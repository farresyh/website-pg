"use client";

import { Button } from "@/components/ui/button";
import { Switch } from "@/components/ui/switch";

/** ADR-060 PR-6 — form primitives shared across the four Storefront tabs. */

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
      <Button type="submit" disabled={saving || disabled}>
        {saving ? "Saving…" : label}
      </Button>
      {saved && <span className="text-theme-sm text-success-ink">Saved.</span>}
    </div>
  );
}

/** The on/off toggle, from the shared kit's Switch (ADR-128). */
export function Toggle({
  checked,
  onChange,
  disabled,
}: {
  checked: boolean;
  onChange: (next: boolean) => void;
  disabled?: boolean;
}) {
  return <Switch checked={checked} onChange={onChange} disabled={disabled} />;
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
