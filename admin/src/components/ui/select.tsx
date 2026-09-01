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
                'flex w-full items-center justify-between gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-left text-theme-sm text-gray-800 shadow-theme-xs transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 data-disabled:cursor-not-allowed data-disabled:opacity-50 dark:border-gray-800 dark:bg-white/[0.03] dark:text-white/90',
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
        <PRSelect.Indicator className={cn('shrink-0 text-gray-400 data-open:rotate-180 transition-transform', className)} {...props}>
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
                'max-h-60 overflow-auto rounded-lg border border-gray-200 bg-white py-1 shadow-theme-lg dark:border-gray-800 dark:bg-gray-900',
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
                'mx-1 cursor-pointer rounded-md px-3 py-2 text-gray-700 data-focused:bg-gray-100 data-selected:font-medium data-selected:text-brand-600 dark:text-gray-300 dark:data-focused:bg-white/[0.05] dark:data-selected:text-brand-400',
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
function SimpleSelect({ options, value, onChange, className, id, disabled }: SimpleSelectProps) {
    return (
        <Select
            value={value}
            options={options}
            optionLabel="label"
            optionValue="value"
            disabled={disabled}
            onValueChange={(e) => onChange(e.value as string)}
        >
            <SelectTrigger id={id} className={className}>
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
