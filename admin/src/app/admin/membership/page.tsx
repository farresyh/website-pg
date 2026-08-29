"use client";

/**
 * ADR-027's 2026-08-29 addendum, decisions 14/15/19/20: /admin/membership
 * — edit-only against the two fixed membership_plans rows (no add/delete
 * tier action, per decision 15's anchor/decoy pricing requirement), plus
 * this feature's own pre-launch kill switch (PlatformSettings.membership_enabled).
 * Same super_admin tier and card layout as the Settings screen's Platform tab.
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { getSettings } from "@/lib/settings";
import {
  getMembershipPlans,
  updateMembershipPlan,
  updateMembershipEnabled,
  previewMembershipPricing,
  type MembershipPlan,
  type MembershipPricingPreviewRow,
} from "@/lib/membership";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";

function Switch({ checked, onChange }: { checked: boolean; onChange: (v: boolean) => void }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      onClick={() => onChange(!checked)}
      className={`relative h-5.5 w-10 flex-shrink-0 rounded-full transition-colors ${checked ? "bg-brand-500" : "bg-gray-300 dark:bg-gray-700"}`}
    >
      <span className={`absolute top-0.5 h-4.5 w-4.5 rounded-full bg-white transition-transform ${checked ? "translate-x-[19px]" : "translate-x-0.5"}`} />
    </button>
  );
}

function PreviewRows({ rows }: { rows: MembershipPricingPreviewRow[] }) {
  if (rows.length === 0) {
    return <p className="text-theme-xs text-gray-500 dark:text-gray-400">No active packages yet — nothing to preview against.</p>;
  }

  return (
    <div className="space-y-2">
      {rows.map((row) => (
        <div
          key={row.package_name}
          className="flex items-center justify-between rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-800 dark:bg-white/[0.02]"
        >
          <div>
            <p className="text-theme-xs font-medium text-gray-700 dark:text-gray-300">{row.package_name}</p>
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">
              You give up RM{(row.margin_forgone_sen / 100).toFixed(2)} margin per sale at this tier.
            </p>
          </div>
          <div className="flex items-center gap-2">
            <span className="rounded-full bg-success-50 px-2 py-0.5 text-theme-xs font-bold text-success-600 dark:bg-success-500/15 dark:text-success-400">
              Save {row.savings_percent}%
            </span>
            <div className="text-right">
              <p className="text-sm font-bold text-success-600 dark:text-success-400">RM{(row.member_price_sen / 100).toFixed(2)}</p>
              <p className="text-theme-xs text-gray-400 line-through dark:text-gray-500">RM{(row.normal_price_sen / 100).toFixed(2)}</p>
            </div>
          </div>
        </div>
      ))}
    </div>
  );
}

function TierCard({ token, plan, onSaved }: { token: string; plan: MembershipPlan; onSaved: () => void }) {
  const [name, setName] = useState(plan.name);
  const [feeRm, setFeeRm] = useState(String(plan.fee_sen / 100));
  const [quotaRm, setQuotaRm] = useState(String(plan.quota_sen / 100));
  const [discountPercent, setDiscountPercent] = useState(plan.discount_percent);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  const [previewRows, setPreviewRows] = useState<MembershipPricingPreviewRow[] | null>(null);
  const [previewing, setPreviewing] = useState(false);
  const [previewError, setPreviewError] = useState<string | null>(null);

  async function handlePreview() {
    const discount = parseFloat(discountPercent);
    if (!Number.isFinite(discount) || discount < 0) {
      setPreviewError("Enter a valid discount percentage first.");
      return;
    }

    setPreviewing(true);
    setPreviewError(null);
    try {
      const rows = await previewMembershipPricing(token, discount);
      setPreviewRows(rows);
    } catch (err) {
      setPreviewError(err instanceof ApiError ? err.message : "Could not preview this discount.");
    } finally {
      setPreviewing(false);
    }
  }

  async function handleSave() {
    const feeSen = Math.round(parseFloat(feeRm) * 100);
    const quotaSen = Math.round(parseFloat(quotaRm) * 100);
    const discount = parseFloat(discountPercent);

    if (!Number.isFinite(feeSen) || feeSen < 0) {
      setError("Enter a valid monthly fee.");
      return;
    }
    if (!Number.isFinite(quotaSen) || quotaSen < 0) {
      setError("Enter a valid monthly quota.");
      return;
    }
    if (!Number.isFinite(discount) || discount < 0) {
      setError("Enter a valid discount percentage.");
      return;
    }

    setSaving(true);
    setError(null);
    setSaved(false);
    try {
      await updateMembershipPlan(token, plan.id, {
        name,
        fee_sen: feeSen,
        quota_sen: quotaSen,
        discount_percent: discountPercent,
      });
      setSaved(true);
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save this tier.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
      <Input value={name} onChange={(e) => setName(e.target.value)} className="mb-4 font-semibold" />

      {error && (
        <p className="mb-3 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div>
          <Label htmlFor={`fee-${plan.id}`}>Monthly fee (RM)</Label>
          <Input id={`fee-${plan.id}`} value={feeRm} onChange={(e) => setFeeRm(e.target.value)} placeholder="8.90" />
        </div>
        <div>
          <Label htmlFor={`quota-${plan.id}`}>Monthly quota (RM)</Label>
          <Input id={`quota-${plan.id}`} value={quotaRm} onChange={(e) => setQuotaRm(e.target.value)} placeholder="100" />
        </div>
        <div>
          <Label htmlFor={`discount-${plan.id}`}>Discount off package markup (%)</Label>
          <Input id={`discount-${plan.id}`} value={discountPercent} onChange={(e) => setDiscountPercent(e.target.value)} placeholder="50" />
        </div>
      </div>

      <p className="mt-3 text-theme-xs text-gray-500 dark:text-gray-400">
        Reduces each package&apos;s own markup % by this amount for members on this tier — never below cost price. Doesn&apos;t apply to
        a flat member-wide price.
      </p>

      <div className="mt-4 flex items-center justify-between gap-3">
        <Button type="button" variant="outlined" size="small" onClick={handlePreview} disabled={previewing}>
          {previewing ? "Previewing…" : "Preview Pricing Impact"}
        </Button>
        <div className="flex items-center gap-3">
          {saved && <span className="text-theme-xs text-success-600">Saved.</span>}
          <Button type="button" onClick={handleSave} disabled={saving}>
            {saving ? "Saving…" : "Save Tier"}
          </Button>
        </div>
      </div>

      {previewError && (
        <p className="mt-3 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {previewError}
        </p>
      )}

      {previewRows !== null && (
        <div className="mt-4 border-t border-gray-200 pt-4 dark:border-gray-800">
          <p className="mb-2 text-theme-xs font-medium text-gray-600 dark:text-gray-400">
            How this discount would look against real packages right now — and what it costs you per sale:
          </p>
          <PreviewRows rows={previewRows} />
        </div>
      )}
    </div>
  );
}

export default function MembershipPage() {
  const router = useRouter();
  const session = useClientSession();
  const [plans, setPlans] = useState<MembershipPlan[] | null>(null);
  const [enabled, setEnabled] = useState(false);
  const [togglingEnabled, setTogglingEnabled] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function refresh(token: string) {
    return Promise.all([getMembershipPlans(token), getSettings(token)])
      .then(([plansResponse, settingsResponse]) => {
        setPlans(plansResponse);
        setEnabled(settingsResponse.platform.membership_enabled);
      })
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load membership settings.");
      });
  }

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    refresh(s.token);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function handleToggleEnabled(next: boolean) {
    if (!session) return;
    setTogglingEnabled(true);
    setError(null);
    try {
      await updateMembershipEnabled(session.token, next);
      setEnabled(next);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not update the membership feature toggle.");
    } finally {
      setTogglingEnabled(false);
    }
  }

  if (error) {
    return <p className="rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>;
  }

  if (!session || !plans) {
    return <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>;
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Membership</h1>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
          Two fixed tiers (ADR-027) — fee, monthly quota, and discount % are editable here. Adding or removing a tier isn&apos;t
          supported from this screen; the anchor/decoy pricing this feature relies on needs exactly two tiers live together.
        </p>
      </div>

      <div className="mb-5 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="flex items-center justify-between">
          <div>
            <h3 className="text-sm font-semibold text-gray-800 dark:text-white/90">Membership feature</h3>
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">
              Gates the storefront member-price badge and the checkout verify prompt. Keep this off until real numbers and the
              email OTP vendor are ready.
            </p>
          </div>
          <Switch checked={enabled} onChange={handleToggleEnabled} />
        </div>
        {togglingEnabled && <p className="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">Saving…</p>}
      </div>

      <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
        {plans.map((plan) => (
          <TierCard key={plan.id} token={session.token} plan={plan} onSaved={() => refresh(session.token)} />
        ))}
      </div>
    </div>
  );
}
