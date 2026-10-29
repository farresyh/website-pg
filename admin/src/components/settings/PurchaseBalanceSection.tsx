"use client";

/**
 * ADR-131 decisions 4 and 11: the Purchase Balance limits (saving needs MFA
 * step-up) and the seller details printed on top-up receipts.
 */

import { useState } from "react";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api-client";
import { notify } from "@/components/ui/toast";
import { updatePlatformSettings, updatePurchaseBalancePolicy, type PlatformSettings } from "@/lib/settings";

const CARD = "rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]";
const HELP = "mt-1 text-theme-xs text-gray-500 dark:text-gray-400";

export default function PurchaseBalanceSection({
  platform,
  onSaved,
}: {
  platform: PlatformSettings;
  onSaved: () => void;
}) {
  const [maxRm, setMaxRm] = useState(String(platform.topup_max_sen / 100));
  const [capRm, setCapRm] = useState(String(platform.balance_cap_default_sen / 100));
  const [legalName, setLegalName] = useState(platform.company_legal_name ?? "");
  const [regNo, setRegNo] = useState(platform.company_reg_no ?? "");
  const [address, setAddress] = useState(platform.company_address ?? "");
  const [savingLimits, setSavingLimits] = useState(false);
  const [savingCompany, setSavingCompany] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSaveLimits() {
    setError(null);
    const topupMaxSen = Math.round(parseFloat(maxRm) * 100);
    const capSen = Math.round(parseFloat(capRm) * 100);
    if (!Number.isFinite(topupMaxSen) || !Number.isFinite(capSen) || topupMaxSen < 1000 || capSen < 1) {
      setError("Enter valid amounts. The maximum per top-up must be at least RM 10.00 (the minimum top-up).");
      return;
    }
    if (capSen < topupMaxSen) {
      setError("The default balance cap cannot be lower than the maximum per top-up.");
      return;
    }

    setSavingLimits(true);
    try {
      await updatePurchaseBalancePolicy({ topup_max_sen: topupMaxSen, balance_cap_default_sen: capSen });
      notify.success("Purchase Balance limits saved");
      onSaved();
    } catch (err) {
      notify.error("Could not save the limits", err instanceof ApiError ? err.message : "Action failed.");
    } finally {
      setSavingLimits(false);
    }
  }

  async function handleSaveCompany() {
    setSavingCompany(true);
    try {
      // The platform-settings route still requires its other fields, so they go back unchanged.
      await updatePlatformSettings({
        maintenance_mode: platform.maintenance_mode,
        maintenance_message: platform.maintenance_message,
        vip_spend_threshold_sen: platform.vip_spend_threshold_sen,
        whatsapp_notifications_enabled: platform.whatsapp_notifications_enabled,
        company_legal_name: legalName.trim() || null,
        company_reg_no: regNo.trim() || null,
        company_address: address.trim() || null,
      });
      notify.success("Company details saved");
      onSaved();
    } catch (err) {
      notify.error("Could not save the company details", err instanceof ApiError ? err.message : "Action failed.");
    } finally {
      setSavingCompany(false);
    }
  }

  return (
    <div className="space-y-5">
      {error && (
        <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <div className={CARD}>
        <h3 className="mb-1 text-sm font-semibold text-gray-800 dark:text-white/90">Purchase Balance limits</h3>
        <p className="mb-4 text-theme-xs text-gray-500 dark:text-gray-400">
          Top-ups above the maximum, and any top-up that would take a balance above its cap, are blocked. A payment
          already made is never rejected. A single reseller&apos;s cap can be changed from its Wallet screen.
        </p>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label htmlFor="topup_max_rm">Maximum per top-up (RM)</Label>
            <Input id="topup_max_rm" inputMode="decimal" value={maxRm} onChange={(e) => setMaxRm(e.target.value)} />
          </div>
          <div>
            <Label htmlFor="balance_cap_rm">Default balance cap (RM)</Label>
            <Input id="balance_cap_rm" inputMode="decimal" value={capRm} onChange={(e) => setCapRm(e.target.value)} />
            <p className={HELP}>Used for every reseller without its own cap.</p>
          </div>
        </div>
        <div className="mt-4 flex justify-end">
          <Button type="button" onClick={handleSaveLimits} disabled={savingLimits}>
            {savingLimits ? "Saving…" : "Save Limits"}
          </Button>
        </div>
      </div>

      <div className={CARD}>
        <h3 className="mb-1 text-sm font-semibold text-gray-800 dark:text-white/90">Company details (shown on receipts)</h3>
        <p className="mb-4 text-theme-xs text-gray-500 dark:text-gray-400">
          The seller on every Payment Receipt. A receipt is built when it is opened, so a change here also changes how
          earlier receipts print.
        </p>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label htmlFor="company_legal_name">Legal name</Label>
            <Input id="company_legal_name" value={legalName} maxLength={255} onChange={(e) => setLegalName(e.target.value)} />
          </div>
          <div>
            <Label htmlFor="company_reg_no">Registration number (SSM)</Label>
            <Input id="company_reg_no" value={regNo} maxLength={64} onChange={(e) => setRegNo(e.target.value)} />
          </div>
        </div>
        <div className="mt-4">
          <Label htmlFor="company_address">Registered address</Label>
          <textarea
            id="company_address"
            value={address}
            onChange={(e) => setAddress(e.target.value)}
            rows={3}
            maxLength={500}
            className="w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
          />
        </div>
        <div className="mt-4 flex justify-end">
          <Button type="button" onClick={handleSaveCompany} disabled={savingCompany}>
            {savingCompany ? "Saving…" : "Save Company Details"}
          </Button>
        </div>
      </div>
    </div>
  );
}
