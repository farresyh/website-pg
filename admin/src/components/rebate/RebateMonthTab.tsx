"use client";

/**
 * ADR-139 decision 18 and the accountant addendum, item 6: one rebate month.
 * Each reseller's standing (live until the month is finalised), every
 * milestone row with the inputs it was written with, and the CSV of the
 * month's rewards, claims, expiries and forfeits.
 */

import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { StatusText } from "@/components/orders/StatusText";
import { RebateTable, TD, TD_STRONG } from "@/components/rebate/RebateTable";
import { rebateRm } from "@/components/rebate/RebateLadderGrid";
import { ApiError } from "@/lib/api-client";
import { downloadRebateMonth, formatRebatePeriod, getRebateMonth, type RebateMonth } from "@/lib/rebate";

const rm = rebateRm;
const stateSeverity = { available: "info", claimed: "success", expired: "secondary" } as const;
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString("en-MY", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }) : "—");

export function RebateMonthTab({ reloadKey }: { reloadKey: number }) {
  const now = new Date();
  const [year, setYear] = useState(now.getFullYear());
  const [month, setMonth] = useState(now.getMonth() + 1);
  const [data, setData] = useState<RebateMonth | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [exporting, setExporting] = useState(false);

  useEffect(() => {
    getRebateMonth(year, month)
      .then((d) => {
        setData(d);
        setError(null);
      })
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load the month."));
  }, [year, month, reloadKey]);

  async function handleExport() {
    setExporting(true);
    try {
      await downloadRebateMonth(year, month);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Export failed.");
    } finally {
      setExporting(false);
    }
  }

  const totals = data?.resellers.reduce(
    (t, r) => ({ entitlement: t.entitlement + r.entitlement_sen, claimed: t.claimed + r.claimed_sen, available: t.available + r.available_sen, expired: t.expired + r.expired_sen }),
    { entitlement: 0, claimed: 0, available: 0, expired: 0 },
  );

  return (
    <div className="pt-4">
      <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div className="flex items-end gap-3">
          <div>
            <Label htmlFor="rebate_year">Year</Label>
            <input id="rebate_year" type="number" value={year} onChange={(e) => setYear(Number(e.target.value))} className="h-10 w-24 rounded-lg border border-gray-200 bg-surface px-3 text-theme-sm text-ink dark:border-gray-800" />
          </div>
          <div>
            <Label htmlFor="rebate_month">Month</Label>
            <select id="rebate_month" value={month} onChange={(e) => setMonth(Number(e.target.value))} className="h-10 rounded-lg border border-gray-200 bg-surface px-3 text-theme-sm text-ink dark:border-gray-800">
              {Array.from({ length: 12 }, (_, i) => i + 1).map((m) => (
                <option key={m} value={m}>{new Date(2000, m - 1, 1).toLocaleString("en-US", { month: "long" })}</option>
              ))}
            </select>
          </div>
          {data && (
            <span className="mb-2.5">
              <StatusText severity={data.finalised ? "success" : "warn"}>{data.finalised ? "finalised" : "live, not finalised"}</StatusText>
            </span>
          )}
        </div>
        <Button variant="outlined" size="small" disabled={exporting} onClick={handleExport}>
          {exporting ? "Exporting…" : "Export CSV"}
        </Button>
      </div>

      {error && <p className="mb-4 rounded-lg bg-danger-surface px-4 py-3 text-sm text-danger-ink">{error}</p>}
      {!data && !error && <p className="text-sm text-ink-muted">Loading…</p>}

      {data && totals && (
        <>
          <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {[
              ["Earned", totals.entitlement, data.finalised ? "Final entitlement, every reseller" : "Live: steps reached so far"],
              ["Claimed", totals.claimed, "In Purchase Balance"],
              ["Waiting to be claimed", totals.available, `Until ${new Date(data.claim_deadline).toLocaleDateString("en-MY", { day: "numeric", month: "long", year: "numeric" })} (KL), then it lapses`],
              ["Lapsed", totals.expired, "Not claimed in time"],
            ].map(([label, sen, note]) => (
              <div key={label as string} className="rounded-2xl border border-gray-200 bg-surface p-5 dark:border-gray-800">
                <p className="text-theme-sm text-ink-muted">{label}</p>
                <p className="mt-2 text-2xl font-semibold text-ink">{rm(sen as number)}</p>
                <p className="mt-1 text-theme-xs text-ink-muted">{note}</p>
              </div>
            ))}
          </div>

          <RebateTable
            title={<h2 className="text-section-title font-semibold text-ink">Resellers, {formatRebatePeriod(data.period)}</h2>}
            headers={["Reseller", "Sales", "Our profit", "Step reached", "Cap", "Earned", "Claimed", "Waiting", "Lapsed"]}
            rows={data.resellers.map((r) => ({ ...r, id: r.reseller_id }))}
            empty="No reseller sales or rebate rows this month."
            cells={(r) => [
              [
                TD_STRONG,
                <>
                  {r.business_name}
                  {r.closed ? (
                    <StatusText severity="secondary"> closed</StatusText>
                  ) : (
                    !r.eligible && <StatusText severity="warn"> not in programme</StatusText>
                  )}
                  {r.adjusted && <span className="block text-theme-xs font-normal text-warning-ink">A reward was cut to the cap</span>}
                </>,
              ],
              [TD, rm(r.sales_sen)],
              [TD, rm(r.profit_sen)],
              [TD, r.reached_step === 0 ? "—" : r.reached_step],
              [TD, rm(r.cap_sen)],
              [TD_STRONG, rm(r.entitlement_sen)],
              [TD, rm(r.claimed_sen)],
              [TD, rm(r.available_sen)],
              [TD, rm(r.expired_sen)],
            ]}
          />

          <RebateTable
            title={<h2 className="text-section-title font-semibold text-ink">Rewards</h2>}
            headers={["Reseller", "Step", "Amount", "State", "Sales / profit when written", "Ladder / cap", "Claimed", "Lapsed"]}
            rows={data.rows}
            empty="No rewards claimed or written yet this month."
            cells={(row) => [
              [TD_STRONG, row.business_name ?? "—"],
              [TD, row.kind === "adjustment" ? "Adjustment" : `Step ${row.step}`],
              [
                TD_STRONG,
                <>
                  {rm(row.amount_sen)}
                  {row.adjusted && <span className="block text-theme-xs font-normal text-warning-ink">cut to the cap</span>}
                </>,
              ],
              [TD, <StatusText key="s" severity={stateSeverity[row.state]}>{row.state === "available" ? "waiting" : row.state === "expired" ? "lapsed" : "claimed"}</StatusText>],
              [TD, `${rm(row.sales_sen)} / ${rm(row.profit_sen)}`],
              [TD, `${rm(row.ladder_cumulative_sen)} / ${rm(row.cap_sen)}`],
              [TD, when(row.claimed_at)],
              [TD, when(row.expired_at)],
            ]}
          />
        </>
      )}
    </div>
  );
}
