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
 */
const primereact = {
  theme: {
    options: {
      darkModeSelector: ".dark",
    },
  },
  license: process.env.NEXT_PUBLIC_PRIMEUI_LICENSE_KEY,
};

export function PrimeProvider({ children }: { children: React.ReactNode }) {
  return <PrimeReactProvider {...primereact}>{children}</PrimeReactProvider>;
}
