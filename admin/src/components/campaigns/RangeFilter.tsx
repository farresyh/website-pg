"use client";

/** ADR-118 PR 4: the Reports date-range filter, for the Campaigns pages. */

import { useState } from "react";
import { Calendar } from "@primeicons/react/calendar";
import { Input } from "@/components/ui/input";
import { FilterSelect, formatShortDate } from "@/components/reports/FilterSelect";
import { useDebouncedValue } from "@/hooks/useDebouncedValue";
import { DATE_RANGE_PRESETS, resolveDateRange, type DateRangePreset } from "@/lib/date-range";

export function useRange(initial: DateRangePreset = "this_month") {
  const [preset, setPreset] = useState<DateRangePreset>(initial);
  const [customFrom, setCustomFrom] = useState("");
  const [customTo, setCustomTo] = useState("");
  const range = resolveDateRange(preset, useDebouncedValue(customFrom), useDebouncedValue(customTo));

  const filter = (
    <div className="flex flex-wrap items-center gap-2">
      <FilterSelect
        icon={<Calendar width={14} height={14} />}
        value={preset}
        onChange={(v) => setPreset(v as DateRangePreset)}
        options={DATE_RANGE_PRESETS}
        suffix={range.from && range.to ? `${formatShortDate(range.from)} – ${formatShortDate(range.to)}` : undefined}
        ariaLabel="Date range"
      />
      {preset === "custom" && (
        <>
          <Input type="date" value={customFrom} onChange={(e) => setCustomFrom(e.target.value)} className="w-[9.5rem]" />
          <span className="text-theme-xs text-ink-muted">to</span>
          <Input type="date" value={customTo} onChange={(e) => setCustomTo(e.target.value)} className="w-[9.5rem]" />
        </>
      )}
    </div>
  );

  return { range, filter, isAllTime: preset === "all" };
}
