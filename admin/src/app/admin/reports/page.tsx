"use client";

/**
 * RPT-1..3 (docs/prd.md §6.9), expanded 2026-08-27 into a tabbed
 * analytics layout (Overview/Sales Analysis/Profit Analysis/Orders/
 * Games/Payment Methods/Affiliates). Every figure mirrors
 * backend/app/Services/Report/ReportService.php's pinned definitions
 * (grilled 2026-08-26) — sales/orders/latest-order are paid_at-scoped
 * Paid orders, profit is ledger-sourced (not the cached Order column),
 * margin% = platform profit / sales, all day-bucketing in
 * Asia/Kuala_Lumpur. This page only renders what the backend returns.
 *
 * ADR-038: new screen, PrimeReact-Tailwind only (Button/Select/Tabs),
 * no old TailAdmin primitives.
 *
 * ADR-086 filter-unification follow-up (2026-09-11): the old separate
 * Year/Month picker is replaced by ONE date-range filter (preset +
 * custom from/to) that every tab — KPI cards, breakdown tables, and the
 * trend charts — resolves identically. Previously the trend charts had
 * their own private 7/14/30-day toggle that silently ignored this
 * filter; that's gone, see OverviewTab/ProfitAnalysisTab.
 */

import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectIndicator,
  SelectPortal,
  SelectPositioner,
  SelectPopup,
  SelectList,
  SelectOption,
} from "@/components/ui/select";
import { Tabs, TabsList, TabsTab, TabsIndicator, TabsPanels, TabsPanel } from "@/components/ui/tabs";
import { Popover, PopoverPortal, PopoverPositioner, PopoverPopup, PopoverClose } from "@/components/ui/popover";
import { ToolbarButton } from "@/components/reports/ReportKit";
import { Calendar } from "@primeicons/react/calendar";
import { ChevronDown } from "@primeicons/react/chevron-down";
import { Comment } from "@primeicons/react/comment";
import { Download } from "@primeicons/react/download";
import { Plus } from "@primeicons/react/plus";
import { Refresh } from "@primeicons/react/refresh";
import { Times } from "@primeicons/react/times";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { type ReportAffiliate, type ReportFilters, listReportAffiliates, exportReport } from "@/lib/reports";
import { DATE_RANGE_PRESETS, type DateRangePreset, compareModeFor, resolveDateRange, todayInKL } from "@/lib/date-range";
import { OverviewTab } from "@/components/reports/tabs/OverviewTab";
import { SalesAnalysisTab } from "@/components/reports/tabs/SalesAnalysisTab";
import { ProfitAnalysisTab } from "@/components/reports/tabs/ProfitAnalysisTab";
import { OrdersTab } from "@/components/reports/tabs/OrdersTab";
import { GamesTab } from "@/components/reports/tabs/GamesTab";
import { PaymentMethodsTab } from "@/components/reports/tabs/PaymentMethodsTab";
import { AffiliatesTab } from "@/components/reports/tabs/AffiliatesTab";
import { MembershipTab } from "@/components/reports/tabs/MembershipTab";

const AFFILIATE_ALL = "all";

const TABS = [
  { value: "overview", label: "Overview" },
  { value: "sales", label: "Sales Analysis" },
  { value: "profit", label: "Profit Analysis" },
  { value: "orders", label: "Orders" },
  { value: "games", label: "Games" },
  { value: "payment-methods", label: "Payment Methods" },
  { value: "affiliates", label: "Affiliates" },
  { value: "membership", label: "Membership" },
] as const;

export default function ReportsPage() {
  const router = useRouter();
  const session = useClientSession();

  const [affiliates, setAffiliates] = useState<ReportAffiliate[]>([]);
  const [affiliateId, setAffiliateId] = useState<string>(AFFILIATE_ALL);
  const [rangePreset, setRangePreset] = useState<DateRangePreset>("all");
  const [customFrom, setCustomFrom] = useState<string>("");
  const [customTo, setCustomTo] = useState<string>("");
  const [activeTab, setActiveTab] = useState<string>("overview");
  const [error, setError] = useState<string | null>(null);
  const [exporting, setExporting] = useState<"csv" | "pdf" | null>(null);
  const [exportMenuOpen, setExportMenuOpen] = useState(false);
  const [exportTriggerEl, setExportTriggerEl] = useState<HTMLElement | null>(null);
  const [compareOn, setCompareOn] = useState(false);
  // ADR-104 R5 — Refresh remounts the open tab (each tab fetches on mount);
  // `updatedAt` is when the shown data was requested.
  const [refreshKey, setRefreshKey] = useState(0);
  const [updatedAt, setUpdatedAt] = useState(() => Date.now());
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    const id = setInterval(() => setNow(Date.now()), 30_000);
    return () => clearInterval(id);
  }, []);

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const { from, to } = resolveDateRange(rangePreset, customFrom, customTo);
  const filters: ReportFilters = {
    from,
    to,
    affiliateId: affiliateId === AFFILIATE_ALL ? undefined : Number(affiliateId),
  };
  const compareMode = compareModeFor(rangePreset);
  const compare = compareOn && compareMode ? compareMode : undefined;

  // Every control that refetches stamps the time, in its own handler.
  function refetched<T extends unknown[]>(fn: (...args: T) => void) {
    return (...args: T) => {
      const t = Date.now();
      setUpdatedAt(t);
      setNow(t);
      fn(...args);
    };
  }

  const minutesAgo = Math.floor((now - updatedAt) / 60_000);
  const updatedLabel = minutesAgo < 1 ? "Updated just now" : `Updated ${minutesAgo} min ago`;

  const refresh = useCallback((token: string) => {
    listReportAffiliates(token).then(setAffiliates).catch(() => undefined);
  }, []);

  useEffect(() => {
    if (!session) return;
    refresh(session.token);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session]);

  async function handleExport(format: "csv" | "pdf") {
    if (!session) return;
    setExporting(format);
    setError(null);
    try {
      await exportReport(session.token, format, filters);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not export the report.");
    } finally {
      setExporting(null);
    }
  }

  const affiliateOptions = [
    { label: "All affiliates", value: AFFILIATE_ALL },
    ...affiliates.map((r) => ({ label: r.business_name, value: String(r.id) })),
  ];

  return (
    <div>
      {/* ADR-104 — header in the artifact mockup's shape: title + status/actions, then one filter row. */}
      <div className="mb-5 flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-page-title font-semibold tracking-tight text-ink">Reports</h1>
          <p className="mt-1 text-theme-sm text-ink-muted">Track sales, profit and operational performance across PekanGame.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <span className="mr-2 flex items-center gap-1.5 text-theme-xs text-ink-muted" aria-live="polite">
            <span className="size-1.5 rounded-full bg-success-ink" />
            {updatedLabel}
          </span>
          {/* ADR-087 decision 6/9 — super_admin only, own route (not a tab here). */}
          {session?.role === "super_admin" && (
            <Link
              href="/admin/reports/assistant"
              className="inline-flex h-9 items-center gap-2 rounded-md px-2.5 text-[13px] font-medium text-ink hover:bg-overlay focus-visible:outline-2 focus-visible:outline-focus-ring"
            >
              <Comment width={14} height={14} />
              Ask assistant
            </Link>
          )}
          <ToolbarButton aria-label="Refresh" title="Refresh" className="w-9 justify-center px-0" onClick={refetched(() => setRefreshKey((k) => k + 1))}>
            <Refresh width={14} height={14} />
          </ToolbarButton>
          {/* ADR-104 R5 — one Export menu; export content unchanged. Manual
              anchor pattern (UserDropdown.tsx): the trigger needs a ref. */}
          <span ref={setExportTriggerEl}>
            <ToolbarButton disabled={exporting !== null} onClick={() => setExportMenuOpen((v) => !v)} aria-haspopup="menu">
              <Download width={14} height={14} />
              {exporting ? "Exporting…" : "Export"}
              <ChevronDown width={12} height={12} />
            </ToolbarButton>
          </span>
          <Popover open={exportMenuOpen} onOpenChange={(e) => setExportMenuOpen(e.value ?? false)} anchor={exportTriggerEl}>
            <PopoverPortal>
              <PopoverPositioner side="bottom" align="end" sideOffset={6}>
                <PopoverPopup className="w-40 p-1">
                  {(["csv", "pdf"] as const).map((format) => (
                    <PopoverClose
                      key={format}
                      onClick={() => void handleExport(format)}
                      className="block w-full rounded-md px-3 py-2 text-left text-theme-sm text-ink hover:bg-overlay"
                    >
                      {format === "csv" ? "CSV" : "PDF"}
                    </PopoverClose>
                  ))}
                </PopoverPopup>
              </PopoverPositioner>
            </PopoverPortal>
          </Popover>
        </div>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-danger-surface px-4 py-3 text-sm text-danger-ink">
          {error}
        </p>
      )}

      {/* Filter row — one row, above the charts (dataviz interaction.md) */}
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-2">
          <FilterSelect
            icon={<Calendar width={14} height={14} />}
            value={rangePreset}
            onChange={refetched((v: string) => setRangePreset(v as DateRangePreset))}
            options={DATE_RANGE_PRESETS}
            suffix={from && to ? `${formatShortDate(from)} – ${formatShortDate(to)}` : undefined}
            ariaLabel="Date range"
          />
          {rangePreset === "custom" && (
            <>
              <Input type="date" value={customFrom} onChange={refetched((e: React.ChangeEvent<HTMLInputElement>) => setCustomFrom(e.target.value))} className="w-[9.5rem]" />
              <span className="text-theme-xs text-ink-muted">to</span>
              <Input type="date" value={customTo} onChange={refetched((e: React.ChangeEvent<HTMLInputElement>) => setCustomTo(e.target.value))} className="w-[9.5rem]" />
            </>
          )}
          <FilterSelect
            label="Affiliate"
            value={affiliateId}
            onChange={refetched(setAffiliateId)}
            options={affiliateOptions}
            ariaLabel="Affiliate"
          />
          {/* ADR-104 R12 — All time has no previous period, so Compare is off and says why. */}
          {compare ? (
            <ToolbarButton active onClick={refetched(() => setCompareOn(false))} aria-pressed="true">
              {compareMode === "month_to_date" ? "Comparing with same days last month" : "Comparing with previous period"}
              <Times width={12} height={12} aria-hidden />
            </ToolbarButton>
          ) : (
            <ToolbarButton dashed disabled={compareMode === null} onClick={refetched(() => setCompareOn(true))} aria-pressed="false">
              <Plus width={12} height={12} />
              Compare period
            </ToolbarButton>
          )}
          {compareMode === null && <span className="text-theme-xs text-ink-muted">Compare needs a bounded range, not All time.</span>}
        </div>
        <p className="text-theme-xs text-ink-muted">Paid orders only · Malaysia time (GMT+8)</p>
      </div>

      <Tabs value={activeTab} onValueChange={(e) => refetched(setActiveTab)(e.value as string)}>
        <TabsList>
          {TABS.map((tab) => (
            <TabsTab key={tab.value} value={tab.value}>
              {tab.label}
            </TabsTab>
          ))}
          <TabsIndicator />
        </TabsList>
        <TabsPanels key={refreshKey}>
          {session && (
            <>
              <TabsPanel value="overview">
                {activeTab === "overview" && (
                  <OverviewTab
                    token={session.token}
                    filters={filters}
                    compare={compare}
                    rangeIncludesToday={to === undefined || to >= todayInKL()}
                    onOpenTab={refetched(setActiveTab)}
                  />
                )}
              </TabsPanel>
              <TabsPanel value="sales">
                {activeTab === "sales" && <SalesAnalysisTab token={session.token} filters={filters} />}
              </TabsPanel>
              <TabsPanel value="profit">
                {activeTab === "profit" && <ProfitAnalysisTab token={session.token} filters={filters} />}
              </TabsPanel>
              <TabsPanel value="orders">
                {activeTab === "orders" && <OrdersTab token={session.token} filters={filters} />}
              </TabsPanel>
              <TabsPanel value="games">
                {activeTab === "games" && <GamesTab token={session.token} filters={filters} />}
              </TabsPanel>
              <TabsPanel value="payment-methods">
                {activeTab === "payment-methods" && <PaymentMethodsTab token={session.token} filters={filters} />}
              </TabsPanel>
              <TabsPanel value="affiliates">
                {activeTab === "affiliates" && <AffiliatesTab token={session.token} filters={filters} />}
              </TabsPanel>
              <TabsPanel value="membership">
                {activeTab === "membership" && <MembershipTab token={session.token} filters={filters} />}
              </TabsPanel>
            </>
          )}
        </TabsPanels>
      </Tabs>
    </div>
  );
}

function FilterSelect({
  label,
  icon,
  suffix,
  value,
  onChange,
  options,
  ariaLabel,
}: {
  label?: string;
  icon?: React.ReactNode;
  suffix?: string;
  value: string;
  onChange: (value: string) => void;
  options: { label: string; value: string }[];
  ariaLabel: string;
}) {
  return (
    <Select
      value={value}
      options={options}
      optionLabel="label"
      optionValue="value"
      onValueChange={(e) => onChange(e.value as string)}
    >
      <SelectTrigger
        aria-label={ariaLabel}
        className="h-9 w-auto gap-2 rounded-md border-border-strong bg-surface px-3 py-0 text-[13px] font-medium text-ink shadow-none dark:border-border-strong dark:bg-surface dark:text-ink"
      >
        {icon && <span className="text-ink-muted">{icon}</span>}
        {label && <span className="font-normal text-ink-muted">{label}</span>}
        <SelectValue />
        {suffix && <span className="border-l border-border pl-2 font-normal text-ink-muted">{suffix}</span>}
        <SelectIndicator />
      </SelectTrigger>
      <SelectPortal>
        <SelectPositioner>
          <SelectPopup>
            <SelectList>
              {options.map((option, index) => (
                <SelectOption key={option.value} index={index}>
                  {option.label}
                </SelectOption>
              ))}
            </SelectList>
          </SelectPopup>
        </SelectPositioner>
      </SelectPortal>
    </Select>
  );
}

/** 'YYYY-MM-DD' → "19 Aug 2026", read as the calendar date it already is. */
function formatShortDate(date: string): string {
  return new Date(`${date}T00:00:00Z`).toLocaleDateString("en-MY", { day: "numeric", month: "short", year: "numeric", timeZone: "UTC" });
}
