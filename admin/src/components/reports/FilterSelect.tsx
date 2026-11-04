"use client";

/**
 * The Reports toolbar's select (date range, affiliate), shared with the
 * Campaigns page (ADR-118 PR 4) so both filter rows look the same.
 */

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

export function FilterSelect({
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
export function formatShortDate(date: string): string {
  return new Date(`${date}T00:00:00Z`).toLocaleDateString("en-MY", { day: "numeric", month: "short", year: "numeric", timeZone: "UTC" });
}
