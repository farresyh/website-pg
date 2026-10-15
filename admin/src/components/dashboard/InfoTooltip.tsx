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
 *
 * The popover renders in a portal with `position: fixed`, placed from the
 * icon's own rect. Inside the card it was clipped by any ancestor with
 * `overflow-hidden` (the Monthly Summary table's last rows, 2026-10-11).
 * It opens below the icon, flips above when the viewport has no room, and
 * is clamped to the viewport's left/right edges. It closes on scroll or
 * resize rather than tracking the icon.
 */

import { useLayoutEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";

const WIDTH = 224; // w-56
const GAP = 6;
const MARGIN = 8;

export function InfoTooltip({ definition }: { definition: string }) {
  const [open, setOpen] = useState(false);
  const [pos, setPos] = useState<{ left: number; top: number } | null>(null);
  const button = useRef<HTMLButtonElement>(null);
  const popover = useRef<HTMLSpanElement>(null);

  useLayoutEffect(() => {
    if (!open || !button.current || !popover.current) return;
    const icon = button.current.getBoundingClientRect();
    const height = popover.current.offsetHeight;
    const below = icon.bottom + GAP;
    const fitsBelow = below + height <= window.innerHeight - MARGIN;
    const top = fitsBelow ? below : Math.max(MARGIN, icon.top - GAP - height);
    const left = Math.min(Math.max(icon.left, MARGIN), window.innerWidth - WIDTH - MARGIN);
    setPos({ left, top });
  }, [open, definition]);

  useLayoutEffect(() => {
    if (!open) return;
    const close = () => setOpen(false);
    window.addEventListener("scroll", close, true);
    window.addEventListener("resize", close);
    return () => {
      window.removeEventListener("scroll", close, true);
      window.removeEventListener("resize", close);
    };
  }, [open]);

  return (
    <span className="inline-flex">
      <button
        ref={button}
        type="button"
        onClick={() => {
          setPos(null);
          setOpen((v) => !v);
        }}
        onBlur={() => setOpen(false)}
        aria-label="What does this metric mean?"
        className="flex h-4 w-4 items-center justify-center rounded-full text-[10px] font-bold text-ink-subtle ring-1 ring-inset ring-gray-300 hover:text-gray-600 dark:ring-gray-600 dark:hover:text-gray-300"
      >
        i
      </button>
      {open &&
        createPortal(
          <span
            ref={popover}
            role="tooltip"
            style={{ position: "fixed", width: WIDTH, left: pos?.left ?? 0, top: pos?.top ?? 0, visibility: pos ? "visible" : "hidden" }}
            className="z-[100000] rounded-lg border border-gray-200 bg-white p-2.5 text-[11.5px] leading-snug text-gray-600 shadow-lg dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
          >
            {definition}
          </span>,
          document.body,
        )}
    </span>
  );
}
