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
import { Button } from "@/components/ui/button";
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
import { Switch } from "@/components/ui/switch";
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
    { label: "All Affiliates", value: AFFILIATE_ALL },
    ...affiliates.map((r) => ({ label: r.business_name, value: String(r.id) })),
  ];

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Reports</h1>
          <p className="mt-1 max-w-2xl text-sm text-gray-500 dark:text-gray-400">
            Sales and profit are recognized only for orders that were actually paid and — for profit — actually
            delivered, sourced from the ledger, not a cached balance.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-theme-xs text-ink-muted" aria-live="polite">
            {updatedLabel}
          </span>
          <Button variant="outlined" size="small" onClick={refetched(() => setRefreshKey((k) => k + 1))}>
            Refresh
          </Button>
          {/* ADR-087 decision 6/9 — super_admin only, own route (not a tab here). */}
          {session?.role === "super_admin" && (
            <Link href="/admin/reports/assistant">
              <Button variant="outlined" size="small">
                Ask Assistant
              </Button>
            </Link>
          )}
          {/* ADR-104 R5 — one Export menu; export content unchanged. Manual
              anchor pattern (UserDropdown.tsx): Button isn't forwardRef. */}
          <span ref={setExportTriggerEl}>
            <Button variant="outlined" size="small" disabled={exporting !== null} onClick={() => setExportMenuOpen((v) => !v)}>
              {exporting ? "Exporting…" : "Export"}
            </Button>
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
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      {/* Filter row — one row, above the charts (dataviz interaction.md) */}
      <div className="mb-6 flex flex-wrap items-center gap-3">
        <FilterSelect label="Affiliate" value={affiliateId} onChange={refetched(setAffiliateId)} options={affiliateOptions} />
        <FilterSelect
          label="Date Range"
          value={rangePreset}
          onChange={refetched((v: string) => setRangePreset(v as DateRangePreset))}
          options={DATE_RANGE_PRESETS}
        />
        {rangePreset === "custom" && (
          <>
            <Input type="date" value={customFrom} onChange={refetched((e: React.ChangeEvent<HTMLInputElement>) => setCustomFrom(e.target.value))} className="w-[9.5rem]" />
            <span className="text-theme-xs text-gray-400 dark:text-gray-500">to</span>
            <Input type="date" value={customTo} onChange={refetched((e: React.ChangeEvent<HTMLInputElement>) => setCustomTo(e.target.value))} className="w-[9.5rem]" />
          </>
        )}
        {/* ADR-104 R12 — All time has no previous period, so Compare says why it's off. */}
        <label className="flex items-center gap-2 text-theme-xs text-ink-muted">
          <Switch
            checked={compareOn && compareMode !== null}
            disabled={compareMode === null}
            onChange={refetched(setCompareOn)}
            ariaLabel="Compare with the previous period"
          />
          {compareMode === null
            ? "Compare (not available for All time)"
            : compareMode === "month_to_date"
              ? "Compare with same days last month"
              : "Compare with previous period"}
        </label>
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
  value,
  onChange,
  options,
  disabled,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  options: { label: string; value: string }[];
  disabled?: boolean;
}) {
  return (
    <div className="flex items-center gap-2">
      <span className="text-theme-xs text-gray-500 dark:text-gray-400">{label}</span>
      <Select
        value={value}
        options={options}
        optionLabel="label"
        optionValue="value"
        disabled={disabled}
        onValueChange={(e) => onChange(e.value as string)}
      >
        <SelectTrigger className="min-w-[9rem]">
          <SelectValue />
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
    </div>
  );
}
