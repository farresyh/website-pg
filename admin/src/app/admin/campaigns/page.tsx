"use client";

/**
 * ADR-118 V14/V15 (PR 4): Campaigns. Is each KOL worth paying again? The
 * scorecard is ReportService::campaignBreakdown(): profit from the ledger
 * (as Reports), fees from the accrued schedule (never payment dates), CAC
 * on qualified new customers. Super admin only (the API enforces it).
 * Layout follows Reports: header, filter row, KPI cards, a ReportTable.
 */

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Plus } from "@primeicons/react/plus";
import { Button } from "@/components/ui/button";
import { Tag } from "@/components/ui/tag";
import { notify } from "@/components/ui/toast";
import { KpiCard, Pending, ReportCard, ReportTable, type ReportColumn } from "@/components/reports/ReportKit";
import CampaignSettingsCard from "@/components/campaigns/CampaignSettingsCard";
import { FeeForm, FormDialog } from "@/components/campaigns/CampaignForms";
import { useRange } from "@/components/campaigns/RangeFilter";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import { createCampaign, listCampaigns, rm, verdictLabel, type CampaignSettings, type CampaignSummary } from "@/lib/campaigns";

const profitOf = (s: CampaignSummary["range"]) => s.redeemed.profit_sen + s.attributed_only.profit_sen;

export default function CampaignsPage() {
  const router = useRouter();
  const { range, filter, isAllTime } = useRange();
  const [campaigns, setCampaigns] = useState<CampaignSummary[] | null>(null);
  const [settings, setSettings] = useState<CampaignSettings | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);

  const load = useCallback(() => {
    listCampaigns(range).then(
      (r) => {
        setCampaigns(r.campaigns);
        setSettings(r.settings);
        setError(null);
      },
      (err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load campaigns."),
    );
  }, [range.from, range.to]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (!getClientSession()) {
      router.replace("/login");
      return;
    }
    load();
  }, [load, router]);

  const total = (pick: (c: CampaignSummary) => number) => (campaigns ?? []).reduce((sum, c) => sum + pick(c), 0);
  const scope = isAllTime ? "All time" : "In this range";

  const columns: ReportColumn<CampaignSummary>[] = [
    {
      key: "name",
      header: "Campaign",
      render: (c) => (
        <div className="min-w-0">
          <Link href={`/admin/campaigns/${c.id}`} className="font-medium text-ink hover:underline">
            {c.name}
          </Link>
          <p className="text-theme-xs text-ink-muted">
            {c.codes.length > 0 ? c.codes.join(", ") : "No codes yet"}
            {c.ended_at && <span> · Ended</span>}
          </p>
        </div>
      ),
    },
    { key: "fee", header: "Fee / month", align: "right", render: (c) => rm(c.monthly_fee_sen) },
    { key: "clicks", header: "Clicks", align: "right", render: (c) => c.range.clicks.toLocaleString() },
    {
      key: "customers",
      header: "New (qualified)",
      align: "right",
      render: (c) => `${c.range.new_customers} (${c.range.qualified_new_customers})`,
    },
    { key: "profit", header: "Profit", align: "right", render: (c) => rm(profitOf(c.range)) },
    { key: "fees", header: "Fees accrued", align: "right", render: (c) => rm(c.range.fees_accrued_sen) },
    { key: "cac", header: "CAC", align: "right", render: (c) => rm(c.range.cac_sen) },
    {
      key: "net",
      header: "Net, all time",
      align: "right",
      render: (c) => <span className={c.cumulative.net_sen < 0 ? "text-danger-ink" : "text-success-ink"}>{rm(c.cumulative.net_sen)}</span>,
    },
    {
      key: "verdict",
      header: "Verdict",
      render: (c) => {
        const v = verdictLabel(c.verdict);
        return v ? <Tag severity={v.tone}>{v.text}</Tag> : <span className="text-theme-xs text-ink-muted">Set a target</span>;
      },
    },
  ];

  return (
    <div>
      <div className="mb-5 flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-page-title font-semibold tracking-tight text-ink">Campaigns</h1>
          <p className="mt-1 text-theme-sm text-ink-muted">KOL promo codes and whether each KOL is worth paying again.</p>
        </div>
        <Button onClick={() => setCreating(true)}>
          <Plus width={14} height={14} />
          New campaign
        </Button>
      </div>

      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        {filter}
        <p className="text-theme-xs text-ink-muted">Paid orders · profit from the ledger · Malaysia time (GMT+8)</p>
      </div>

      {error && <p className="mb-4 rounded-lg bg-danger-surface px-4 py-3 text-sm text-danger-ink">{error}</p>}

      {campaigns === null ? (
        <Pending error={error} />
      ) : (
        <div className="space-y-5">
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <KpiCard lead label="Qualified new customers" value={total((c) => c.range.qualified_new_customers).toLocaleString()} sub={`${total((c) => c.range.new_customers)} new in all · ${scope.toLowerCase()}`} />
            <KpiCard lead label="Profit from KOL customers" value={rm(total((c) => profitOf(c.range)))} sub="Ledger profit, after the discount" />
            <KpiCard label="KOL fees accrued" value={rm(total((c) => c.range.fees_accrued_sen))} sub="By month of service, not payment date" />
            <KpiCard label="Link clicks" value={total((c) => c.range.clicks).toLocaleString()} sub={`${total((c) => c.range.redemptions)} codes redeemed`} />
          </div>

          <ReportCard title="All campaigns" subtitle={`${scope}. Net and verdict are all time.`} flush>
            <ReportTable columns={columns} rows={campaigns} rowKey={(c) => c.id} empty="No campaigns yet. Create one for each KOL you pay." />
          </ReportCard>

          {settings && <CampaignSettingsCard key={JSON.stringify(settings)} settings={settings} onSaved={(s) => { setSettings(s); load(); }} />}
        </div>
      )}

      <FormDialog open={creating} title="New campaign" onClose={() => setCreating(false)}>
        <FeeForm
          withName
          submitLabel="Create campaign"
          onClose={() => setCreating(false)}
          onSubmit={async (v) => {
            const { id } = await createCampaign(v);
            notify.success("Campaign created", "Add its promo code next.");
            router.push(`/admin/campaigns/${id}`);
          }}
        />
      </FormDialog>
    </div>
  );
}
