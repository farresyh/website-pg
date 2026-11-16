"use client";

/**
 * ADR-142 decision 25: reseller dormancy. A reseller with no order and no
 * added balance for the set number of days is warned, then deactivated until
 * an admin reactivates it. Saving needs MFA step-up.
 */

import { useState } from "react";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Switch } from "@/components/ui/switch";
import { ApiError } from "@/lib/api-client";
import { notify } from "@/components/ui/toast";
import { updateResellerDormancyPolicy, type PlatformSettings } from "@/lib/settings";

const CARD = "rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]";
const HELP = "mt-1 text-theme-xs text-gray-500 dark:text-gray-400";

export default function ResellerInactivitySection({ platform, onSaved }: { platform: PlatformSettings; onSaved: () => void }) {
  const [enabled, setEnabled] = useState(platform.reseller_dormancy_enabled);
  const [days, setDays] = useState(String(platform.reseller_dormancy_days));
  const [warningDays, setWarningDays] = useState(String(platform.reseller_dormancy_warning_days));
  const [whatsapp, setWhatsapp] = useState(platform.reseller_support_whatsapp ?? "");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const daysN = Number(days);
  const warningN = Number(warningDays);
  const warnOn = Number.isInteger(daysN) && Number.isInteger(warningN) ? daysN - warningN : null;

  async function handleSave() {
    setError(null);
    if (!Number.isInteger(daysN) || daysN < 2 || daysN > 365) {
      setError("The period must be a whole number of days from 2 to 365.");
      return;
    }
    if (!Number.isInteger(warningN) || warningN < 1 || warningN >= daysN) {
      setError("The warning must come at least 1 day before, and before the period ends.");
      return;
    }

    setSaving(true);
    try {
      await updateResellerDormancyPolicy({
        reseller_dormancy_enabled: enabled,
        reseller_dormancy_days: daysN,
        reseller_dormancy_warning_days: warningN,
        reseller_support_whatsapp: whatsapp.trim() || null,
      });
      notify.success("Reseller inactivity settings saved");
      onSaved();
    } catch (err) {
      notify.error("Could not save the settings", err instanceof ApiError ? err.message : "Action failed.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="space-y-5">
      {error && (
        <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <div className={CARD}>
        <div className="mb-4 flex items-start justify-between gap-4">
          <div>
            <h3 className="mb-1 text-sm font-semibold text-gray-800 dark:text-white/90">Deactivate inactive resellers</h3>
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">
              A reseller with no order and no added balance for the period is warned by email and in its bot group, then
              deactivated: no portal login, no order or top-up by bot or API. Its Purchase Balance is kept. Reactivate it
              from Resellers when it asks. Test and internal accounts are never deactivated.
            </p>
          </div>
          <Switch checked={enabled} onChange={setEnabled} ariaLabel="Deactivate inactive resellers" />
        </div>
        {enabled && !platform.reseller_dormancy_enabled && (
          <p className="mb-4 rounded-lg bg-warning-50 px-3 py-2 text-theme-xs text-warning-600 dark:bg-warning-500/15 dark:text-warning-400">
            Every reseller&apos;s count starts from the moment you save this, so no one is deactivated for the days before.
          </p>
        )}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label htmlFor="dormancy_days">Days with no transaction</Label>
            <Input id="dormancy_days" inputMode="numeric" value={days} onChange={(e) => setDays(e.target.value)} />
            <p className={HELP}>An order (even one later refunded) or added balance resets the count.</p>
          </div>
          <div>
            <Label htmlFor="dormancy_warning_days">Warn this many days before</Label>
            <Input id="dormancy_warning_days" inputMode="numeric" value={warningDays} onChange={(e) => setWarningDays(e.target.value)} />
            <p className={HELP}>
              {warnOn !== null && warnOn > 0 ? `The warning goes out on day ${warnOn}. ` : ""}
              A reseller is never deactivated without a warning at least this long before.
            </p>
          </div>
          <div className="sm:col-span-2">
            <Label htmlFor="support_whatsapp">WhatsApp number for reactivation</Label>
            <Input id="support_whatsapp" inputMode="tel" placeholder="+60 12-345 6789" value={whatsapp} onChange={(e) => setWhatsapp(e.target.value)} />
            <p className={HELP}>Shown to a deactivated reseller at login, in the bot and in the API error.</p>
          </div>
        </div>
        <div className="mt-4 flex justify-end">
          <Button type="button" onClick={handleSave} disabled={saving}>
            {saving ? "Saving…" : "Save"}
          </Button>
        </div>
      </div>
    </div>
  );
}
