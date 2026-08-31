"use client";

import { PrimeReactProvider } from "@primereact/core";

/**
 * ADR-038: required even in Tailwind mode for PrimeReact component
 * behavior. `darkModeSelector` matches this app's existing `.dark`
 * class toggle (globals.css's `@custom-variant dark`).
 *
 * `license` — PrimeReact v11's Tailwind/"primitive" line is a
 * different, newer product ("PrimeUI") than classic PrimeReact and
 * verifies a license on every mount, showing a permanent "Invalid
 * PrimeUI License" banner without one — found live-testing the
 * ADR-039 Backups screen, not part of ADR-038's original research
 * (which found classic PrimeReact's older API, genuinely free/MIT).
 * This is the founder's own free "Community" tier key from
 * primeui.dev (eligible: solo-founder, pre-revenue, no VC funding) —
 * NEXT_PUBLIC_ is required since verification runs client-side.
 *
 * `zIndex` — PrimeReact assigns each open overlay its own z-index via
 * inline style (default bases: modal/tooltip 1100, overlay/menu 1000,
 * incrementing per open instance), which beats any Tailwind class —
 * className-level z-index overrides on Dialog/Popover are silently
 * inert. This app's own AppHeader (src/layout/AppHeader.tsx) is a
 * sticky z-99999 bar, well above those defaults, so every Dialog/
 * Popover rendered its top portion (title, close button) underneath
 * it — found live via a real screenshot of an edit modal that looked
 * like it had no header at all. Fixed at the base, not per-component,
 * so every overlay type shifts together and nested overlays (e.g. a
 * Select's dropdown opened from inside a Dialog) keep their correct
 * relative stacking order.
 */
const primereact = {
  theme: {
    options: {
      darkModeSelector: ".dark",
    },
  },
  zIndex: {
    modal: 100100,
    overlay: 100000,
    menu: 100000,
    tooltip: 100100,
  },
  license: process.env.NEXT_PUBLIC_PRIMEUI_LICENSE_KEY,
};

export function PrimeProvider({ children }: { children: React.ReactNode }) {
  return <PrimeReactProvider {...primereact}>{children}</PrimeReactProvider>;
}
