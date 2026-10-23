"use client";

import {
  Select,
  SelectIndicator,
  SelectList,
  SelectOption,
  SelectPopup,
  SelectPortal,
  SelectPositioner,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

/** The PrimeReact Select for a plain list of {label, value} options (Orders' filter selects, supplier forms, Joki). */
export function OptionSelect({ id, value, options, onChange, className, ariaLabel, disabled, placeholder }: {
  id?: string;
  value: string;
  options: { label: string; value: string }[];
  onChange: (v: string) => void;
  className?: string;
  ariaLabel?: string;
  disabled?: boolean;
  placeholder?: string;
}) {
  return (
    <Select value={value} options={options} optionLabel="label" optionValue="value" onValueChange={(e) => onChange(e.value as string)} disabled={disabled}>
      <SelectTrigger id={id} className={className} aria-label={ariaLabel}>
        <SelectValue placeholder={placeholder} />
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
