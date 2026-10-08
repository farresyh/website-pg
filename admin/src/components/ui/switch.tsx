'use client';

import { ToggleSwitch } from 'primereact/toggleswitch';

interface SwitchProps {
    checked: boolean;
    onChange: (checked: boolean) => void;
    disabled?: boolean;
    ariaLabel?: string;
}

/**
 * The one on/off toggle (ADR-038). Replaces seven hand-rolled copies whose
 * absolute knob had no `left`, so inside a centring `<button>` it started
 * mid-track and slid out of it. The knob here sits in a flex row, so it
 * can't leave the track. PrimeReact renders a real hidden checkbox
 * (`role="switch"`), stretched over the track to take the click.
 */
function Switch({ checked, onChange, disabled, ariaLabel }: SwitchProps) {
    return (
        <ToggleSwitch.Root
            checked={checked}
            disabled={disabled}
            ariaLabel={ariaLabel}
            onCheckedChange={(e: { checked: boolean }) => onChange(e.checked)}
            className="relative inline-flex h-6 w-11 shrink-0 data-disabled:opacity-50"
            inputClassName="peer absolute inset-0 z-10 m-0 h-full w-full cursor-pointer appearance-none opacity-0 disabled:cursor-not-allowed"
        >
            <ToggleSwitch.Control className="flex h-full w-full items-center rounded-full bg-gray-300 p-0.5 transition-colors peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/40 data-checked:bg-brand-500 dark:bg-gray-700 dark:data-checked:bg-brand-500">
                <ToggleSwitch.Handle className="h-5 w-5 rounded-full bg-white shadow-theme-xs transition-transform data-checked:translate-x-5" />
            </ToggleSwitch.Control>
        </ToggleSwitch.Root>
    );
}

export { Switch };
