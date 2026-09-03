"use client";

/**
 * ADR-027 decisions 14/15/19 — one of the two fixed membership_plans
 * rows, editable in place (name / fee / quota / discount %) with a live
 * "Preview Pricing Impact" against a real package. Split out of
 * /admin/membership's page.tsx (ADR-068 PR-3).
 */

import { useState } from "react";
import { ApiError } from "@/lib/api-client";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Button } from "@/components/ui/button";
import {
  previewMembershipPricing,
  updateMembershipPlan,
  type MembershipPlan,
  type MembershipPricingPreview,
} from "@/lib/membership";

function PreviewCard({ preview }: { preview: MembershipPricingPreview }) {
  if (preview.package_name === null) {
    return <p className="text-theme-xs text-gray-500 dark:text-gray-400">No active packages yet — nothing to preview against.</p>;
  }

  return (
    <div className="rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-white/[0.02]">
      <p className="text-theme-xs font-medium text-gray-700 dark:text-gray-300">{preview.package_name} (example package)</p>

      <div className="mt-2 flex items-center gap-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
        <span>Package markup {preview.package_markup_percent}%</span>
        <span>→</span>
        <span>your discount {preview.discount_percent}%</span>
        <span>→</span>
        <span className="font-semibold text-gray-700 dark:text-gray-300">effective markup {preview.effective_markup_percent}%</span>
      </div>

      <div className="mt-3 flex items-center justify-between">
        <p className="text-theme-xs text-gray-500 dark:text-gray-400">
          You give up RM{(preview.margin_forgone_sen / 100).toFixed(2)} margin per sale at this tier.
        </p>
        <div className="flex items-center gap-2">
          <span className="rounded-full bg-success-50 px-2 py-0.5 text-theme-xs font-bold text-success-600 dark:bg-success-500/15 dark:text-success-400">
            Save {preview.savings_percent}%
          </span>
          <div className="text-right">
            <p className="text-sm font-bold text-success-600 dark:text-success-400">RM{(preview.member_price_sen / 100).toFixed(2)}</p>
            <p className="text-theme-xs text-gray-400 line-through dark:text-gray-500">RM{(preview.normal_price_sen / 100).toFixed(2)}</p>
          </div>
        </div>
      </div>
    </div>
  );
}

export default function TierCard({ token, plan, onSaved }: { token: string; plan: MembershipPlan; onSaved: () => void }) {
  const [name, setName] = useState(plan.name);
  const [feeRm, setFeeRm] = useState(String(plan.fee_sen / 100));
  const [quotaRm, setQuotaRm] = useState(String(plan.quota_sen / 100));
  const [discountPercent, setDiscountPercent] = useState(plan.discount_percent);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  const [preview, setPreview] = useState<MembershipPricingPreview | null>(null);
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
      setPreview(await previewMembershipPricing(token, discount));
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

      {preview !== null && (
        <div className="mt-4 border-t border-gray-200 pt-4 dark:border-gray-800">
          <p className="mb-2 text-theme-xs font-medium text-gray-600 dark:text-gray-400">
            How this discount breaks down against a real package right now — and what it costs you per sale:
          </p>
          <PreviewCard preview={preview} />
        </div>
      )}
    </div>
  );
}
