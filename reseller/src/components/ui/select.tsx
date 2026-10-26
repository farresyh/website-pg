'use client';

import { cn } from '@/lib/utils';
import {
    SelectIndicatorProps,
    SelectListProps,
    SelectOptionProps,
    SelectPopupProps,
    SelectPortalProps,
    SelectPositionerProps,
    SelectRootProps,
    SelectTriggerProps,
    SelectValueProps,
    Select as PRSelect
} from 'primereact/select';
import * as React from 'react';

function Select({ ...props }: SelectRootProps) {
    return <PRSelect.Root {...props} />;
}

function SelectTrigger({ className, type, ...props }: SelectTriggerProps) {
    return (
        <PRSelect.Trigger
            type={type ?? 'button'}
            className={cn(
                'flex w-full items-center justify-between gap-2 rounded-md border border-border bg-surface px-3 py-2 text-left text-theme-sm text-ink shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring/40 data-disabled:cursor-not-allowed data-disabled:opacity-50',
                className
            )}
            {...props}
        />
    );
}

function SelectValue({ className, ...props }: SelectValueProps) {
    return <PRSelect.Value className={cn('truncate', className)} {...props} />;
}

function SelectIndicator({ className, ...props }: SelectIndicatorProps) {
    return (
        <PRSelect.Indicator className={cn('shrink-0 text-ink-subtle data-open:rotate-180 transition-transform', className)} {...props}>
            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                <path d="M2.5 4.5L6 8L9.5 4.5" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
        </PRSelect.Indicator>
    );
}

function SelectPortal({ ...props }: SelectPortalProps) {
    return <PRSelect.Portal {...props} />;
}

function SelectPositioner({ className, ...props }: SelectPositionerProps) {
    return <PRSelect.Positioner className={cn('z-50', className)} {...props} />;
}

function SelectPopup({ className, ...props }: SelectPopupProps) {
    return (
        <PRSelect.Popup
            className={cn(
                'max-h-60 overflow-auto rounded-lg border border-border bg-surface py-1 shadow-md',
                className
            )}
            {...props}
        />
    );
}

function SelectList({ className, ...props }: SelectListProps) {
    return <PRSelect.List className={cn('text-theme-sm', className)} {...props} />;
}

function SelectOption({ className, ...props }: SelectOptionProps) {
    return (
        <PRSelect.Option
            className={cn(
                'mx-1 cursor-pointer rounded-md px-3 py-2 text-ink data-focused:bg-subtle data-selected:font-medium data-selected:text-cyan-ink',
                className
            )}
            {...props}
        />
    );
}

interface SimpleSelectOption {
    value: string;
    label: string;
}

interface SimpleSelectProps {
    options: SimpleSelectOption[];
    value: string;
    onChange: (value: string) => void;
    className?: string;
    id?: string;
    disabled?: boolean;
}

/**
 * The flat options/value/onChange API the old `components/form/Select`
 * (ADR-038) had, composed from the granular parts above — the same
 * shape `admin/reports/page.tsx`'s own local `FilterSelect` already
 * proved out before this was promoted to a shared component. Reaches
 * for this instead of composing the 9 parts by hand at every call
 * site; reach for the parts directly only when a screen genuinely
 * needs a shape `SimpleSelect` doesn't offer (e.g. Report's `FilterSelect`
 * itself, which pairs the trigger with an inline label).
 */
// PrimeReact's Select reads `""` as no value (`isNotEmpty`), so an option
// whose value is `""` ("All", "None", "UID only") never shows its label.
// Swapped for a sentinel inside, so callers keep using `""`.
const EMPTY = '__empty__';
const toInner = (v: string) => (v === '' ? EMPTY : v);
const toOuter = (v: string) => (v === EMPTY ? '' : v);

function SimpleSelect({ options, value, onChange, className, id, disabled }: SimpleSelectProps) {
    const innerOptions = React.useMemo(() => options.map((o) => ({ ...o, value: toInner(o.value) })), [options]);

    return (
        <Select
            value={toInner(value)}
            options={innerOptions}
            optionLabel="label"
            optionValue="value"
            disabled={disabled}
            onValueChange={(e) => onChange(toOuter(e.value as string))}
        >
            <SelectTrigger id={id} className={className}>
                <SelectValue />
                <SelectIndicator />
            </SelectTrigger>
            <SelectPortal>
                <SelectPositioner>
                    <SelectPopup>
                        <SelectList>
                            {innerOptions.map((option, index) => (
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

export {
    Select,
    SelectIndicator,
    SelectList,
    SelectOption,
    SelectPopup,
    SelectPortal,
    SelectPositioner,
    SelectTrigger,
    SelectValue,
    SimpleSelect
};
