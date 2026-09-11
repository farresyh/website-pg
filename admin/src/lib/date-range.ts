/**
 * ADR-086 filter-unification follow-up (2026-09-11) — the Reports
 * page's single date-range filter, replacing the old separate
 * Year/Month picker. Every preset resolves to a KL calendar-date
 * {from, to} pair ('YYYY-MM-DD', both inclusive) sent as-is to every
 * `/api/reports/*` endpoint, the trend chart included — see
 * ReportService::dateRangeFromDates()'s own doc comment on the backend
 * side for how it's turned into a UTC instant range.
 */
export type DateRangePreset = "all" | "this_month" | "7d" | "14d" | "30d" | "90d" | "custom";

export const DATE_RANGE_PRESETS: { value: DateRangePreset; label: string }[] = [
  { value: "all", label: "All time" },
  { value: "this_month", label: "This month" },
  { value: "7d", label: "Last 7 days" },
  { value: "14d", label: "Last 14 days" },
  { value: "30d", label: "Last 30 days" },
  { value: "90d", label: "Last 90 days" },
  { value: "custom", label: "Custom range" },
];

/**
 * Today's calendar date in Asia/Kuala_Lumpur ('YYYY-MM-DD') — matches
 * `ReportService::TIMEZONE`. Uses `Intl` (the `en-CA` locale formats as
 * YYYY-MM-DD) rather than a date library, since this is the only place
 * `admin/` needs KL-anchored "today" client-side.
 */
export function todayInKL(): string {
  return new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Kuala_Lumpur" }).format(new Date());
}

/**
 * Pure calendar-date arithmetic on a 'YYYY-MM-DD' string — deliberately
 * never round-trips through a real timezone (parsed/formatted as UTC
 * throughout) so DST/offset never enters into it; the string IS the KL
 * calendar date already, from `todayInKL()`.
 */
function addDaysToDateString(date: string, days: number): string {
  const d = new Date(`${date}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}

/**
 * Resolves a preset (+ custom `from`/`to` when preset is "custom") into
 * the {from, to} pair every Reports fetch sends. Both undefined means
 * "All time" — `ReportController::trendRangeFromRequest()` is the one
 * place that substitutes a bounded fallback for the trend chart alone;
 * every other endpoint stays genuinely unbounded.
 */
export function resolveDateRange(preset: DateRangePreset, customFrom?: string, customTo?: string): { from?: string; to?: string } {
  const today = todayInKL();

  switch (preset) {
    case "all":
      return {};
    case "this_month":
      return { from: `${today.slice(0, 7)}-01`, to: today };
    case "7d":
      return { from: addDaysToDateString(today, -6), to: today };
    case "14d":
      return { from: addDaysToDateString(today, -13), to: today };
    case "30d":
      return { from: addDaysToDateString(today, -29), to: today };
    case "90d":
      return { from: addDaysToDateString(today, -89), to: today };
    case "custom":
      return { from: customFrom || undefined, to: customTo || undefined };
  }
}
