"use client";

/**
 * ADR-124 decision 10 (+ its 2026-10-18 addendum): when a customer's game
 * login is wiped, how often a super admin may reveal one, and the consent
 * the customer ticks at checkout. Saving needs MFA step-up.
 */

import { useState } from "react";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api-client";
import { updateCustomerLoginPolicy, type PlatformSettings } from "@/lib/settings";

const NUMBER_FIELDS = [
  {
    key: "credential_close_grace_hours",
    label: "Wipe after the order closes (hours)",
    help: "Delivered or compensated. The grace leaves time to check a \"not received\" complaint.",
  },
  {
    key: "credential_unpaid_hours",
    label: "Wipe an unpaid order after (hours)",
    help: "Counted from when the order was placed. Includes failed payments, since a late payment can still arrive.",
  },
  {
    key: "credential_max_days",
    label: "Wipe any login after (days)",
    help: "The ceiling, counted from when the login was last saved. Replacing it restarts the count.",
  },
  {
    key: "credential_reveal_limit_per_hour",
    label: "Reveals per admin per hour",
    help: "Each reveal is logged with the admin, field and IP.",
  },
] as const;

type NumberKey = (typeof NUMBER_FIELDS)[number]["key"];

export default function CustomerLoginPolicySection({
  platform,
  onSaved,
}: {
  platform: PlatformSettings;
  onSaved: () => void;
}) {
  const [numbers, setNumbers] = useState<Record<NumberKey, string>>(() =>
    Object.fromEntries(NUMBER_FIELDS.map((f) => [f.key, String(platform[f.key])])) as Record<NumberKey, string>,
  );
  const [consentText, setConsentText] = useState(platform.credential_consent_text ?? "");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  async function handleSave() {
    const parsed = Object.fromEntries(NUMBER_FIELDS.map((f) => [f.key, Number(numbers[f.key])])) as Record<NumberKey, number>;
    if (Object.values(parsed).some((n) => !Number.isInteger(n) || n < 1)) {
      setError("Every number must be a whole number of at least 1.");
      return;
    }

    setSaving(true);
    setError(null);
    setSaved(false);
    try {
      await updateCustomerLoginPolicy({ ...parsed, credential_consent_text: consentText });
      setSaved(true);
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save the customer login policy.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="space-y-5">
      {error && (
        <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <h3 className="mb-1 text-sm font-semibold text-gray-800 dark:text-white/90">When a customer&apos;s login is wiped</h3>
        <p className="mb-4 text-theme-xs text-gray-500 dark:text-gray-400">
          For games topped up through the customer&apos;s own login. Passwords are stored encrypted and wiped by a check
          that runs every 15 minutes. An open order that failed or needs review keeps its login, so it can be retried.
        </p>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          {NUMBER_FIELDS.map((f) => (
            <div key={f.key}>
              <Label htmlFor={f.key}>{f.label}</Label>
              <Input
                id={f.key}
                inputMode="numeric"
                value={numbers[f.key]}
                onChange={(e) => setNumbers((prev) => ({ ...prev, [f.key]: e.target.value }))}
              />
              <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{f.help}</p>
            </div>
          ))}
        </div>
      </div>

      <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <Label htmlFor="credential_consent_text">Consent the customer ticks before paying</Label>
        <textarea
          id="credential_consent_text"
          value={consentText}
          onChange={(e) => setConsentText(e.target.value)}
          rows={3}
          maxLength={1000}
          className="w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
        />
        <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
          Each order keeps a copy of the text the customer agreed to, so editing it here doesn&apos;t change past orders.
        </p>
      </div>

      <div className="flex items-center justify-end gap-3">
        {saved && <p className="text-theme-xs text-success-600">Saved.</p>}
        <Button type="button" onClick={handleSave} disabled={saving}>
          {saving ? "Saving…" : "Save Customer Login Policy"}
        </Button>
      </div>
    </div>
  );
}
