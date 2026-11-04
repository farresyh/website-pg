"use client";

/**
 * ADR-118 V12/V14/V15/V17 (PR 4): one campaign. Its scorecard (range and
 * all time), the fee schedule with the prepaid / owed balance, and its
 * promo codes. Payments are recorded on the Envelope Ledger (Accounting),
 * tagged with this campaign; this page only reads them.
 */

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft } from "@primeicons/react/arrow-left";
import { Copy } from "@primeicons/react/copy";
import { Plus } from "@primeicons/react/plus";
import { Button } from "@/components/ui/button";
import { Tag } from "@/components/ui/tag";
import { notify } from "@/components/ui/toast";
import { KpiCard, Pending, ReportCard, ReportTable, type ReportColumn } from "@/components/reports/ReportKit";
import { CodeForm, EndMonthForm, FeeForm, FormDialog } from "@/components/campaigns/CampaignForms";
import { useRange } from "@/components/campaigns/RangeFilter";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import {
  addFee,
  createCode,
  getCampaign,
  monthLabel,
  rm,
  setFeeEndMonth,
  updateCampaign,
  updateCode,
  verdictLabel,
  type CampaignDetail,
  type CampaignFee,
  type CodeScore,
  type PromoCodeRow,
  type Scorecard,
} from "@/lib/campaigns";

type Editing = { kind: "fee" } | { kind: "feeEnd"; fee: CampaignFee } | { kind: "code"; code: PromoCodeRow | null } | null;

const pct = (part: number, whole: number) => (whole > 0 ? `${Math.round((part / whole) * 100)}%` : "—");

function ScoreGrid({ s }: { s: Scorecard }) {
  const profit = s.redeemed.profit_sen + s.attributed_only.profit_sen;
  return (
    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <KpiCard lead label="Qualified new customers" value={s.qualified_new_customers.toLocaleString()} sub={`${s.new_customers} new · ${s.clicks} clicks`} />
      <KpiCard lead label="CAC" value={rm(s.cac_sen)} sub={`${rm(s.fees_accrued_sen)} fees ÷ qualified`} />
      <KpiCard label="Profit" value={rm(profit)} sub={`Redeemed ${rm(s.redeemed.profit_sen)} · later ${rm(s.attributed_only.profit_sen)}`} />
      <KpiCard label="Net (profit − fees)" value={rm(s.net_sen)} sub={`Discount given ${rm(s.discount_sen)}`} />
      <KpiCard label="Sales" value={rm(s.redeemed.sales_sen + s.attributed_only.sales_sen)} sub={`${s.redeemed.orders} redeemed · ${s.attributed_only.orders} later orders`} />
      <KpiCard label="Median first order" value={rm(s.median_first_order_sen)} />
      <KpiCard label="Came back" value={pct(s.returned_customers, s.new_customers)} sub={`${s.new_customers - s.returned_customers} never returned`} />
      <KpiCard label="Repeat within 30 days" value={pct(s.repeat_30d_customers, s.new_customers)} />
    </div>
  );
}

export default function CampaignDetailPage() {
  const router = useRouter();
  const id = Number(useParams<{ id: string }>().id);
  const { range, filter, isAllTime } = useRange();
  const [campaign, setCampaign] = useState<CampaignDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<Editing>(null);

  const load = useCallback(() => {
    getCampaign(id, range).then(
      (c) => {
        setCampaign(c);
        setError(null);
      },
      (err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load this campaign."),
    );
  }, [id, range.from, range.to]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (!getClientSession()) {
      router.replace("/login");
      return;
    }
    load();
  }, [load, router]);

  async function act(fn: () => Promise<unknown>, done: string) {
    try {
      await fn();
      notify.success(done);
      load();
    } catch (err) {
      notify.error(err instanceof ApiError ? err.message : "Something went wrong.");
    }
  }

  if (!campaign) return <Pending error={error} className="p-6" />;

  const verdict = verdictLabel(campaign.verdict);
  const scores = new Map(campaign.range.codes.map((c) => [c.promo_code_id, c]));
  const lastFee = campaign.fees[campaign.fees.length - 1];

  const feeColumns: ReportColumn<CampaignFee>[] = [
    { key: "fee", header: "Monthly fee", render: (f) => rm(f.monthly_fee_sen) },
    { key: "from", header: "From", render: (f) => monthLabel(f.from_month) },
    { key: "to", header: "To", render: (f) => (f.to_month ? monthLabel(f.to_month) : "Open") },
    {
      key: "end",
      header: "",
      align: "right",
      render: (f) =>
        f.id === lastFee?.id ? (
          <Button size="small" variant="text" onClick={() => setEditing({ kind: "feeEnd", fee: f })}>
            Set last month
          </Button>
        ) : null,
    },
  ];

  const codeColumns: ReportColumn<PromoCodeRow>[] = [
    {
      key: "code",
      header: "Code",
      render: (c) => (
        <div>
          <span className="font-mono font-medium text-ink">{c.code}</span>
          <p className="text-theme-xs text-ink-muted">
            {c.discount_percent}% off{c.max_discount_sen ? `, max ${rm(c.max_discount_sen)}` : ""}
          </p>
        </div>
      ),
    },
    {
      key: "status",
      header: "Status",
      render: (c) => (
        <div className="flex flex-wrap gap-1">
          <Tag severity={c.is_redeemable_now ? "success" : "secondary"}>{c.is_redeemable_now ? "Live" : c.is_active ? "Not in window" : "Paused"}</Tag>
          {c.over_cap && <Tag severity="warn">Over budget</Tag>}
        </div>
      ),
    },
    { key: "budget", header: "Budget used", align: "right", render: (c) => `${rm(c.used_sen)} / ${rm(c.budget_sen)}` },
    { key: "clicks", header: "Clicks", align: "right", render: (c) => (scores.get(c.id) as CodeScore | undefined)?.clicks ?? 0 },
    { key: "new", header: "New customers", align: "right", render: (c) => scores.get(c.id)?.new_customers ?? 0 },
    { key: "sales", header: "Sales", align: "right", render: (c) => rm(scores.get(c.id)?.sales_sen ?? 0) },
    {
      key: "actions",
      header: "",
      align: "right",
      render: (c) => (
        <div className="flex justify-end gap-1">
          <Button
            size="small"
            variant="text"
            onClick={() => {
              void navigator.clipboard.writeText(c.tracker_url).then(() => notify.success("Tracker link copied", c.tracker_url));
            }}
          >
            <Copy width={12} height={12} />
            Link
          </Button>
          <Button size="small" variant="text" onClick={() => setEditing({ kind: "code", code: c })}>
            Edit
          </Button>
        </div>
      ),
    },
  ];

  return (
    <div>
      <Link href="/admin/campaigns" className="mb-3 inline-flex items-center gap-1.5 text-theme-sm text-ink-muted hover:text-ink">
        <ArrowLeft width={12} height={12} /> Campaigns
      </Link>
      <div className="mb-5 flex flex-wrap items-start justify-between gap-4">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-page-title font-semibold tracking-tight text-ink">{campaign.name}</h1>
            {campaign.ended_at && <Tag severity="secondary">Ended</Tag>}
            {verdict && <Tag severity={verdict.tone}>{verdict.text}</Tag>}
          </div>
          <p className="mt-1 text-theme-sm text-ink-muted">
            Since {monthLabel(campaign.started_month)} · {rm(campaign.monthly_fee_sen)} this month
          </p>
        </div>
        <Button
          variant="outlined"
          severity={campaign.ended_at ? "secondary" : "danger"}
          onClick={() => {
            const ending = !campaign.ended_at;
            if (ending && !window.confirm("End this campaign? Its codes stop working at once. Customers it brought in keep counting.")) return;
            void act(() => updateCampaign(campaign.id, { ended: ending }), ending ? "Campaign ended" : "Campaign reopened");
          }}
        >
          {campaign.ended_at ? "Reopen campaign" : "End campaign"}
        </Button>
      </div>

      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        {filter}
        <p className="text-theme-xs text-ink-muted">Paid orders · profit from the ledger · Malaysia time (GMT+8)</p>
      </div>

      <div className="space-y-5">
        <ReportCard title={isAllTime ? "All time" : "In this range"} subtitle="Qualified: a first order of at least the settings minimum, or a repeat purchase.">
          <ScoreGrid s={campaign.range} />
        </ReportCard>
        {!isAllTime && (
          <ReportCard title="All time">
            <ScoreGrid s={campaign.cumulative} />
          </ReportCard>
        )}

        <ReportCard
          title="Promo codes"
          subtitle="Share the tracker link with the KOL. It prefills the code for 7 days; the customer still applies it."
          actions={
            <Button size="small" onClick={() => setEditing({ kind: "code", code: null })} disabled={campaign.ended_at !== null}>
              <Plus width={12} height={12} /> New code
            </Button>
          }
          flush
        >
          <ReportTable columns={codeColumns} rows={campaign.codes} rowKey={(c) => c.id} empty="No codes yet." />
        </ReportCard>

        <div className="grid grid-cols-1 gap-5 lg:grid-cols-[2fr_1fr]">
          <ReportCard
            title="Fee schedule"
            subtitle="The agreement. A renegotiation adds a row; nothing in a closed month can change."
            actions={
              <Button size="small" variant="outlined" onClick={() => setEditing({ kind: "fee" })}>
                <Plus width={12} height={12} /> New fee
              </Button>
            }
            flush
          >
            <ReportTable columns={feeColumns} rows={campaign.fees} rowKey={(f) => f.id} />
          </ReportCard>
          <ReportCard title={`Balance, end of ${monthLabel(campaign.balance.month)}`}>
            <p className={`text-[22px] font-semibold tabular-nums ${campaign.balance.balance_sen < 0 ? "text-danger-ink" : "text-ink"}`}>
              {rm(Math.abs(campaign.balance.balance_sen))}
              <span className="ml-2 text-theme-sm font-medium text-ink-muted">
                {campaign.balance.balance_sen > 0 ? "prepaid" : campaign.balance.balance_sen < 0 ? "owed to the KOL" : "settled"}
              </span>
            </p>
            <dl className="mt-3 space-y-1 text-theme-sm">
              <div className="flex justify-between"><dt className="text-ink-muted">Fees accrued</dt><dd className="tabular-nums">{rm(campaign.balance.accrued_sen)}</dd></div>
              <div className="flex justify-between"><dt className="text-ink-muted">Paid</dt><dd className="tabular-nums">{rm(campaign.balance.paid_sen)}</dd></div>
            </dl>
            <p className="mt-3 text-theme-xs text-ink-muted">
              Record each payment in <Link href="/admin/accounting" className="underline">Accounting</Link> → Envelope Ledger as an Advertising expense tagged with this campaign.
            </p>
          </ReportCard>
        </div>
      </div>

      <FormDialog open={editing?.kind === "fee"} title="New fee row" onClose={() => setEditing(null)}>
        <FeeForm
          withName={false}
          submitLabel="Add fee"
          onClose={() => setEditing(null)}
          onSubmit={async (v) => {
            await addFee(campaign.id, { monthly_fee_sen: v.monthly_fee_sen, from_month: v.from_month, to_month: v.to_month });
            notify.success("Fee added");
            load();
          }}
        />
      </FormDialog>
      <FormDialog open={editing?.kind === "feeEnd"} title="Set the last month" onClose={() => setEditing(null)}>
        {editing?.kind === "feeEnd" && (
          <EndMonthForm
            current={editing.fee.to_month}
            onClose={() => setEditing(null)}
            onSubmit={async (to) => {
              await setFeeEndMonth(editing.fee.id, to);
              notify.success("Fee updated");
              load();
            }}
          />
        )}
      </FormDialog>
      <FormDialog
        open={editing?.kind === "code"}
        title={editing?.kind === "code" && editing.code ? `Edit ${editing.code.code}` : "New promo code"}
        onClose={() => setEditing(null)}
      >
        {editing?.kind === "code" && (
          <CodeForm
            existing={editing.code}
            onClose={() => setEditing(null)}
            onSubmit={async (v) => {
              if (editing.code) await updateCode(editing.code.id, v);
              else await createCode(campaign.id, v);
              notify.success(editing.code ? "Code saved" : "Code created");
              load();
            }}
          />
        )}
      </FormDialog>
    </div>
  );
}
