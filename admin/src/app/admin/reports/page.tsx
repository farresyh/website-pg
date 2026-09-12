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
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { type ReportAffiliate, type ReportFilters, listReportAffiliates, exportReport } from "@/lib/reports";
import { DATE_RANGE_PRESETS, type DateRangePreset, resolveDateRange } from "@/lib/date-range";
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
        <div className="flex gap-2">
          {/* ADR-087 decision 6/9 — super_admin only, own route (not a tab here). */}
          {session?.role === "super_admin" && (
            <Link href="/admin/reports/assistant">
              <Button variant="outlined" size="small">
                Ask Assistant
              </Button>
            </Link>
          )}
          <Button variant="outlined" size="small" disabled={exporting !== null} onClick={() => handleExport("csv")}>
            {exporting === "csv" ? "Exporting…" : "Export CSV"}
          </Button>
          <Button variant="outlined" size="small" disabled={exporting !== null} onClick={() => handleExport("pdf")}>
            {exporting === "pdf" ? "Exporting…" : "Export PDF"}
          </Button>
        </div>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      {/* Filter row — one row, above the charts (dataviz interaction.md) */}
      <div className="mb-6 flex flex-wrap items-center gap-3">
        <FilterSelect label="Affiliate" value={affiliateId} onChange={setAffiliateId} options={affiliateOptions} />
        <FilterSelect
          label="Date Range"
          value={rangePreset}
          onChange={(v) => setRangePreset(v as DateRangePreset)}
          options={DATE_RANGE_PRESETS}
        />
        {rangePreset === "custom" && (
          <>
            <Input type="date" value={customFrom} onChange={(e) => setCustomFrom(e.target.value)} className="w-[9.5rem]" />
            <span className="text-theme-xs text-gray-400 dark:text-gray-500">to</span>
            <Input type="date" value={customTo} onChange={(e) => setCustomTo(e.target.value)} className="w-[9.5rem]" />
          </>
        )}
      </div>

      <Tabs value={activeTab} onValueChange={(e) => setActiveTab(e.value as string)}>
        <TabsList>
          {TABS.map((tab) => (
            <TabsTab key={tab.value} value={tab.value}>
              {tab.label}
            </TabsTab>
          ))}
          <TabsIndicator />
        </TabsList>
        <TabsPanels>
          {session && (
            <>
              <TabsPanel value="overview">
                {activeTab === "overview" && <OverviewTab token={session.token} filters={filters} />}
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
