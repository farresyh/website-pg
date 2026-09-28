"use client";

/**
 * ADR-045 decision 25 — every metric on this page carries its own
 * `definition` string from the backend, generated right next to the
 * calculation it describes. This button surfaces it on click so a
 * future definition change is self-auditable from the API response
 * alone, no need to ask an engineer/AI to re-derive and re-explain it.
 *
 * No PrimeReact OverlayPanel/Tooltip primitive exists yet anywhere in
 * this codebase (checked) — a small controlled-state popover is the
 * lowest-risk choice rather than introducing a new primitive for one
 * new screen.
 */

import { useState } from "react";

export function InfoTooltip({ definition }: { definition: string }) {
  const [open, setOpen] = useState(false);

  return (
    <span className="relative inline-flex">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        onBlur={() => setOpen(false)}
        aria-label="What does this metric mean?"
        className="flex h-4 w-4 items-center justify-center rounded-full text-[10px] font-bold text-gray-400 ring-1 ring-inset ring-gray-300 hover:text-gray-600 dark:text-gray-500 dark:ring-gray-600 dark:hover:text-gray-300"
      >
        i
      </button>
      {open && (
        // 2026-09-28 fix: was centered under the icon (`left-1/2
        // -translate-x-1/2`) — for an icon near the left edge of a
        // narrow container (found live on Monthly Summary's "COGS"
        // row, close to the table's own left edge), that pushed the
        // popover partly off-screen with its first few words clipped.
        // Anchored to the icon's own left edge instead, growing
        // rightward — safe on every page this component is used on,
        // since labels/icons sit on the left of their row/card with
        // room to the right, never the reverse.
        <span className="absolute top-5 left-0 z-20 w-56 rounded-lg border border-gray-200 bg-white p-2.5 text-[11.5px] leading-snug text-gray-600 shadow-lg dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
          {definition}
        </span>
      )}
    </span>
  );
}
