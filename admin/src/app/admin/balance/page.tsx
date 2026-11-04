"use client";

/**
 * Balance — what we hold at each supplier, whether it lasts until a top-up
 * started today would land (ADR-115 2026-11-05 addendum: the busiest window
 * of the last 60 Malaysia days, through Monday from Thursday, never below the
 * admin's floor), and what every Reseller has prepaid. Supplier balances are
 * in their own currency; Reseller wallets are MYR sen — never added together.
 * Layout follows Campaigns/Reports: header, KPI cards, ReportCards.
 * The buffer figures are SupplierBufferService's; no calculation here.
 */

import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { Button } from "@/components/ui/button";
import { Tag } from "@/components/ui/tag";
import { KpiCard, Pending, ReportCard, ReportTable, type ReportColumn } from "@/components/reports/ReportKit";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import { formatMyrEquivalent, formatSupplierBalance, getSupplierBuffers, type SupplierBuffer } from "@/lib/dashboard";
import { listSuppliers, refreshSupplierBalance, supplierDisplayBalance, type Supplier } from "@/lib/suppliers";
import { listResellers, type ResellerRow } from "@/lib/resellers";

const MALAYSIA = "Asia/Kuala_Lumpur";

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

function formatChecked(iso: string | null): string {
  if (!iso) return "never";
  return new Date(iso).toLocaleString("en-MY", { timeZone: MALAYSIA, day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" });
}

/** A Malaysia calendar day (Y-m-d) as "Mon 10 Nov". */
function formatDay(ymd: string): string {
  return new Date(`${ymd}T12:00:00+08:00`).toLocaleDateString("en-MY", { timeZone: MALAYSIA, weekday: "short", day: "numeric", month: "short" });
}

function BufferCards({ buffer }: { buffer: SupplierBuffer }) {
  const amount = (value: number, myr: number | null) => ({
    value: formatSupplierBalance(value, buffer.currency),
    myr: formatMyrEquivalent(myr, buffer.currency),
  });
  const balance = amount(buffer.balance, buffer.balance_myr);
  const required = amount(buffer.required, buffer.required_myr);
  const until = formatDay(buffer.covers_until);
  const byFloor = buffer.floor !== null && buffer.required <= buffer.floor;
  const window =
    buffer.busiest_from && buffer.busiest_to
      ? `busiest ${buffer.window_days} days of the last 60: ${formatDay(buffer.busiest_from)} – ${formatDay(buffer.busiest_to)}, ${formatSupplierBalance(buffer.forecast, buffer.currency)}`
      : `no spend in the last 60 days`;

  return (
    <ReportCard
      title={buffer.name}
      subtitle={`Must last until the end of ${until}, when a top-up started today would land.`}
      actions={<Tag severity={buffer.is_short ? "warn" : "success"}>{buffer.is_short ? "Top up now" : "Enough"}</Tag>}
    >
      {buffer.is_short && (
        <p className="mb-4 rounded-lg bg-warning-surface px-4 py-3 text-sm text-warning-ink">
          The balance is below what the next {buffer.window_days} days may need. Top up now; an email has gone to every admin (at most one a day).
        </p>
      )}
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <KpiCard lead label="Balance" value={balance.value} sub={[balance.myr, "lower of polled and ledger"].filter(Boolean).join(" · ")} />
        <KpiCard lead label={`Needed until ${until}`} value={required.value} sub={[required.myr, byFloor ? "your floor" : "busiest window"].filter(Boolean).join(" · ")} />
        <KpiCard
          label={buffer.is_short ? "Short by" : "Headroom"}
          value={formatSupplierBalance(Math.abs(buffer.balance - buffer.required), buffer.currency)}
          sub={buffer.is_short ? "top up at least this much" : "above what is needed"}
        />
      </div>
      <p className="mt-4 text-theme-xs text-ink-muted">
        How it is worked out: the higher of your floor ({buffer.floor === null ? "not set" : formatSupplierBalance(buffer.floor, buffer.currency)}) and the {window}
        {buffer.margin_pct > 0 ? `, plus ${buffer.margin_pct}%` : ""}. From Thursday the window runs through Monday. Floor, lead time and margin:{" "}
        <Link href="/middleware/suppliers" className="underline">
          Middleware → Suppliers
        </Link>
        .
      </p>
    </ReportCard>
  );
}

export default function BalanceOverviewPage() {
  const router = useRouter();
  const [suppliers, setSuppliers] = useState<Supplier[] | null>(null);
  const [buffers, setBuffers] = useState<SupplierBuffer[] | null>(null);
  const [resellers, setResellers] = useState<ResellerRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [refreshingId, setRefreshingId] = useState<number | null>(null);

  const load = useCallback(
    () =>
      Promise.all([listSuppliers(), getSupplierBuffers(), listResellers()]).then(
        ([s, b, r]) => {
          setSuppliers(s);
          setBuffers(b);
          setResellers(r.resellers);
          setError(null);
        },
        (err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load balances."),
      ),
    [],
  );

  useEffect(() => {
    if (!getClientSession()) {
      router.replace("/login");
      return;
    }
    load();
  }, [load, router]);

  // A new balance moves the buffer too, so reload everything rather than patch one row.
  async function handleRefreshSupplier(supplierId: number) {
    setRefreshingId(supplierId);
    try {
      await refreshSupplierBalance(supplierId);
      await load();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Balance check failed.");
    } finally {
      setRefreshingId(null);
    }
  }

  const supplierColumns: ReportColumn<Supplier>[] = [
    { key: "name", header: "Supplier", render: (s) => <span className="font-medium text-ink">{s.name}</span> },
    { key: "balance", header: "Balance", align: "right", render: (s) => <span className="tabular-nums">{supplierDisplayBalance(s)}</span> },
    {
      key: "checked",
      header: "Last checked",
      render: (s) => (
        <>
          {formatChecked(s.last_tested_at)}
          {s.last_test_result?.startsWith("failed") && <p className="mt-0.5 max-w-xs text-theme-xs text-danger-ink">{s.last_test_result}</p>}
        </>
      ),
    },
    { key: "status", header: "Status", render: (s) => <Tag severity={s.is_active ? "success" : "secondary"}>{s.is_active ? "active" : "inactive"}</Tag> },
    {
      key: "action",
      header: "",
      align: "right",
      // ADR-125: a manual supplier has no API balance to refresh.
      render: (s) =>
        s.is_api_backed ? (
          <Button size="small" variant="outlined" disabled={refreshingId === s.id} onClick={() => handleRefreshSupplier(s.id)}>
            {refreshingId === s.id ? "Checking…" : "Refresh"}
          </Button>
        ) : (
          <span className="text-theme-xs text-ink-subtle">Funding ledger</span>
        ),
    },
  ];

  const totalWalletSen = (resellers ?? []).reduce((sum, r) => sum + r.wallet_balance_sen, 0);
  const resellerColumns: ReportColumn<ResellerRow>[] = [
    { key: "name", header: "Reseller", render: (r) => <span className="font-medium text-ink">{r.business_name}</span>, total: "Total" },
    { key: "tier", header: "Tier", render: (r) => r.tier_name ?? "—" },
    { key: "wallet", header: "Wallet balance", align: "right", render: (r) => <span className="tabular-nums">{formatRm(r.wallet_balance_sen)}</span>, total: <span className="tabular-nums">{formatRm(totalWalletSen)}</span> },
    { key: "status", header: "Status", render: (r) => <Tag severity={r.is_active ? "success" : "secondary"}>{r.is_active ? "active" : "inactive"}</Tag> },
  ];

  return (
    <div>
      <div className="mb-5 flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-page-title font-semibold tracking-tight text-ink">Balance</h1>
          <p className="mt-1 text-theme-sm text-ink-muted">
            What we hold at each supplier, whether it lasts until a top-up would land, and what Resellers have prepaid.
          </p>
        </div>
        <p className="text-theme-xs text-ink-muted">Malaysia time (GMT+8)</p>
      </div>

      {error && <p className="mb-4 rounded-lg bg-danger-surface px-4 py-3 text-sm text-danger-ink">{error}</p>}

      {suppliers === null || buffers === null || resellers === null ? (
        <Pending error={error} />
      ) : (
        <div className="space-y-5">
          {buffers.map((b) => (
            <BufferCards key={b.supplier_id} buffer={b} />
          ))}

          <ReportCard title="Supplier balances" subtitle="In each supplier's own currency." flush>
            <ReportTable columns={supplierColumns} rows={suppliers} rowKey={(s) => s.id} empty="No suppliers configured yet." />
          </ReportCard>

          <ReportCard
            title="Reseller wallets"
            subtitle={
              <>
                Purchase Balance, in ringgit. To credit a wallet or manage a reseller, use{" "}
                <Link href="/admin/resellers" className="underline">
                  Resellers
                </Link>
                .
              </>
            }
            flush
          >
            <ReportTable
              columns={resellerColumns}
              rows={[...resellers].sort((a, b) => b.wallet_balance_sen - a.wallet_balance_sen)}
              rowKey={(r) => r.id}
              empty="No resellers yet."
            />
          </ReportCard>
        </div>
      )}
    </div>
  );
}
