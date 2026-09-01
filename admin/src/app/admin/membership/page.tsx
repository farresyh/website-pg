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
import {
  DataTable,
  DataTableTableContainer,
  DataTableTable,
  DataTableTHead,
  DataTableTHeadRow,
  DataTableTHeadCell,
  DataTableTBody,
  DataTableRow,
  DataTableCell,
} from "@/components/ui/datatable";
import { Tag } from "@/components/ui/tag";
import { SimpleSelect } from "@/components/ui/select";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { getSettings } from "@/lib/settings";
import { PlusIcon } from "@/icons";
import {
  getMembershipPlans,
  updateMembershipPlan,
  updateMembershipEnabled,
  previewMembershipPricing,
  listMemberships,
  listMembershipBrands,
  recordMembershipPayment,
  type MembershipPlan,
  type MembershipPricingPreview,
  type MembershipListItem,
  type MembershipBrand,
  type MembershipStatusFilter,
} from "@/lib/membership";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import RecordPaymentModal from "@/components/membership/RecordPaymentModal";

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

function PreviewCard({ preview }: { preview: MembershipPricingPreview }) {
  if (preview.package_name === null) {
    return <p className="text-theme-xs text-gray-500 dark:text-gray-400">No active packages yet — nothing to preview against.</p>;
  }

  return (
    <div className="rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-white/[0.02]">
      <p className="text-theme-xs font-medium text-gray-700 dark:text-gray-300">{preview.package_name} (example package)</p>

      {/* The markup% breakdown itself — package markup -> discount -> effective markup — not just the final RM numbers. */}
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

function TierCard({ token, plan, onSaved }: { token: string; plan: MembershipPlan; onSaved: () => void }) {
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

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

function formatDate(iso: string | null): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleDateString("en-MY", { day: "numeric", month: "short", year: "numeric" });
}

const statusSeverity: Record<MembershipListItem["status"], "success" | "secondary"> = {
  active: "success",
  expired: "secondary",
};

function MembersSection({ token, plans, onChanged }: { token: string; plans: MembershipPlan[]; onChanged: () => void }) {
  const [data, setData] = useState<MembershipListItem[] | null>(null);
  const [statusFilter, setStatusFilter] = useState<MembershipStatusFilter>("all");
  // ADR-061 decision 5: memberships are per-brand — "all" or one brand id.
  const [brands, setBrands] = useState<MembershipBrand[]>([]);
  const [brandFilter, setBrandFilter] = useState<string>("all");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [error, setError] = useState<string | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [presetEmail, setPresetEmail] = useState<string | undefined>(undefined);
  const [presetResellerId, setPresetResellerId] = useState<number | undefined>(undefined);

  function refresh(nextPage = 1) {
    listMemberships(token, {
      status: statusFilter,
      search: search || undefined,
      page: nextPage,
      resellerId: brandFilter === "all" ? undefined : Number(brandFilter),
    })
      .then((res) => {
        setData(res.data);
        setLastPage(res.last_page);
        setTotal(res.total);
        setPage(res.current_page);
      })
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load members.");
      });
  }

  useEffect(() => {
    listMembershipBrands(token)
      .then(setBrands)
      .catch(() => setBrands([]));
  }, [token]);

  useEffect(() => {
    refresh(1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusFilter, brandFilter]);

  async function handleRecorded(values: Parameters<typeof recordMembershipPayment>[1]) {
    await recordMembershipPayment(token, values);
    setIsModalOpen(false);
    setPresetEmail(undefined);
    setPresetResellerId(undefined);
    await refresh(page);
    onChanged();
  }

  return (
    <div className="mt-8">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-base font-semibold text-gray-800 dark:text-white/90">Members</h2>
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">
            {total} member{total === 1 ? "" : "s"} — tier, status, and quota usage.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search email…"
            className="w-56"
          />
          <Button size="small" variant="outlined" onClick={() => refresh(1)}>
            Search
          </Button>
          <SimpleSelect
            options={[
              { value: "all", label: "All" },
              { value: "active", label: "Active" },
              { value: "expired", label: "Expired" },
            ]}
            value={statusFilter}
            onChange={(v) => setStatusFilter(v as MembershipStatusFilter)}
            className="w-36"
          />
          {brands.length > 1 && (
            <SimpleSelect
              options={[{ value: "all", label: "All brands" }, ...brands.map((b) => ({ value: String(b.id), label: b.business_name }))]}
              value={brandFilter}
              onChange={setBrandFilter}
              className="w-44"
            />
          )}
          <Button size="small" onClick={() => { setPresetEmail(undefined); setPresetResellerId(undefined); setIsModalOpen(true); }}>
            <PlusIcon />
            Record Payment
          </Button>
        </div>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={data ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Email</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Brand</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Tier</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Expires</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Quota Used</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Orders</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item }) => {
                    const m = item as unknown as MembershipListItem;

                    return (
                      <DataTableRow key={m.id}>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                          {m.email}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {m.brand_name ?? `brand #${m.reseller_id}`}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {m.plan_name ?? `plan #${m.plan_id}`}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <Tag severity={statusSeverity[m.status]}>{m.status}</Tag>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {formatDate(m.expires_at)}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {formatRm(m.quota_used_sen)} / {formatRm(m.quota_total_sen)}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {m.orders_count}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <Button size="small" variant="outlined" onClick={() => { setPresetEmail(m.email); setPresetResellerId(m.reseller_id); setIsModalOpen(true); }}>
                            Record Payment
                          </Button>
                        </DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>

          {data !== null && data.length === 0 && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No members yet.</p>
          )}
        </div>

        {lastPage > 1 && (
          <div className="flex items-center justify-between border-t border-gray-100 px-5 py-3 dark:border-gray-800">
            <span className="text-theme-xs text-gray-500 dark:text-gray-400">
              Page {page} of {lastPage} ({total} total)
            </span>
            <div className="flex items-center gap-2">
              <Button size="small" variant="outlined" disabled={page <= 1} onClick={() => refresh(page - 1)}>
                Previous
              </Button>
              <Button size="small" variant="outlined" disabled={page >= lastPage} onClick={() => refresh(page + 1)}>
                Next
              </Button>
            </div>
          </div>
        )}
      </div>

      <RecordPaymentModal
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        plans={plans}
        brands={brands}
        presetResellerId={presetResellerId}
        presetEmail={presetEmail}
        onSubmit={handleRecorded}
      />
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

      <MembersSection token={session.token} plans={plans} onChanged={() => refresh(session.token)} />
    </div>
  );
}
