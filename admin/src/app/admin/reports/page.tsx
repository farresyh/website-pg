"use client";

/**
 * RPT-1..3 (docs/prd.md §6.9), expanded 2026-08-27 into a tabbed
 * analytics layout (Overview/Sales Analysis/Profit Analysis/Orders/
 * Games/Payment Methods/Resellers). Every figure mirrors
 * backend/app/Services/Report/ReportService.php's pinned definitions
 * (grilled 2026-08-26) — sales/orders/latest-order are paid_at-scoped
 * Paid orders, profit is ledger-sourced (not the cached Order column),
 * margin% = platform profit / sales, all day-bucketing in
 * Asia/Kuala_Lumpur. This page only renders what the backend returns.
 *
 * ADR-038: new screen, PrimeReact-Tailwind only (Button/Select/Tabs),
 * no old TailAdmin primitives.
 */

import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
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
import { type ReportReseller, type ReportFilters, listReportResellers, exportReport } from "@/lib/reports";
import { OverviewTab } from "@/components/reports/tabs/OverviewTab";
import { SalesAnalysisTab } from "@/components/reports/tabs/SalesAnalysisTab";
import { ProfitAnalysisTab } from "@/components/reports/tabs/ProfitAnalysisTab";
import { OrdersTab } from "@/components/reports/tabs/OrdersTab";
import { GamesTab } from "@/components/reports/tabs/GamesTab";
import { PaymentMethodsTab } from "@/components/reports/tabs/PaymentMethodsTab";
import { ResellersTab } from "@/components/reports/tabs/ResellersTab";

const RESELLER_ALL = "all";
const YEAR_ALL = "all";

const currentYear = new Date().getFullYear();
const yearOptions = [YEAR_ALL, ...Array.from({ length: 5 }, (_, i) => String(currentYear - i))];
const monthOptions = [YEAR_ALL, ...Array.from({ length: 12 }, (_, i) => String(i + 1))];
const monthLabels: Record<string, string> = {
  all: "All months",
  "1": "January", "2": "February", "3": "March", "4": "April",
  "5": "May", "6": "June", "7": "July", "8": "August",
  "9": "September", "10": "October", "11": "November", "12": "December",
};

const TABS = [
  { value: "overview", label: "Overview" },
  { value: "sales", label: "Sales Analysis" },
  { value: "profit", label: "Profit Analysis" },
  { value: "orders", label: "Orders" },
  { value: "games", label: "Games" },
  { value: "payment-methods", label: "Payment Methods" },
  { value: "resellers", label: "Resellers" },
] as const;

export default function ReportsPage() {
  const router = useRouter();
  const session = useClientSession();

  const [resellers, setResellers] = useState<ReportReseller[]>([]);
  const [resellerId, setResellerId] = useState<string>(RESELLER_ALL);
  const [year, setYear] = useState<string>(YEAR_ALL);
  const [month, setMonth] = useState<string>(YEAR_ALL);
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

  const filters: ReportFilters = {
    year: year === YEAR_ALL ? undefined : Number(year),
    month: year === YEAR_ALL || month === YEAR_ALL ? undefined : Number(month),
    resellerId: resellerId === RESELLER_ALL ? undefined : Number(resellerId),
  };

  const refresh = useCallback((token: string) => {
    listReportResellers(token).then(setResellers).catch(() => undefined);
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

  const resellerOptions = [
    { label: "All Resellers", value: RESELLER_ALL },
    ...resellers.map((r) => ({ label: r.business_name, value: String(r.id) })),
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
        <FilterSelect label="Reseller" value={resellerId} onChange={setResellerId} options={resellerOptions} />
        <FilterSelect
          label="Year"
          value={year}
          onChange={(v) => {
            setYear(v);
            if (v === YEAR_ALL) setMonth(YEAR_ALL);
          }}
          options={yearOptions.map((y) => ({ label: y === YEAR_ALL ? "All time" : y, value: y }))}
        />
        <FilterSelect
          label="Month"
          value={month}
          onChange={setMonth}
          disabled={year === YEAR_ALL}
          options={monthOptions.map((m) => ({ label: monthLabels[m], value: m }))}
        />
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
              <TabsPanel value="resellers">
                {activeTab === "resellers" && <ResellersTab token={session.token} filters={filters} />}
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
