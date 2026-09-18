"use client";

/**
 * ADR-109 — the customer-facing description/important-notes popup.
 * Deliberately centered at every breakpoint, not a bottom-sheet drawer
 * like RateOrderModal.tsx — founder's own call, reviewed against a
 * real mobile screenshot. Delivery info deliberately excluded
 * (decision 11) — it already lives on ProductHeaderCard.
 */

import { Info, WarningCircle, X } from "@phosphor-icons/react/dist/ssr";

export default function GameInfoModal({
  gameName,
  description,
  importantNotes,
  onClose,
}: {
  gameName: string;
  description: string | null;
  importantNotes: string[];
  onClose: () => void;
}) {
  return (
    <div className="fixed inset-0 z-[60] flex items-center justify-center bg-ink/50 p-4" onClick={onClose}>
      <div
        className="flex max-h-[85vh] w-full max-w-[460px] flex-col overflow-y-auto rounded-lg border-2 border-ink bg-surface neo-lg p-6"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="mb-4 flex items-center justify-between border-b-2 border-ink pb-3">
          <h2 className="font-display text-lg font-bold uppercase tracking-tight">{gameName}</h2>
          <button
            onClick={onClose}
            aria-label="Close"
            className="flex h-11 w-11 shrink-0 items-center justify-center rounded-md border-2 border-ink hover:bg-surface-container"
          >
            <X size={18} />
          </button>
        </div>

        {description && <p className="mb-4 text-sm leading-relaxed text-on-surface-variant">{description}</p>}

        {importantNotes.length > 0 && (
          <div className="rounded-md border-2 border-ink bg-warning/20 p-4">
            <div className="mb-2.5 flex items-center gap-2">
              <WarningCircle size={18} weight="fill" className="shrink-0 text-warning-text" />
              <span className="font-display text-[13px] font-bold uppercase tracking-wide text-warning-text">
                Important Notes
              </span>
            </div>
            <ul className="space-y-2">
              {importantNotes.map((note, i) => (
                <li key={i} className="flex gap-2 text-[13px] leading-relaxed text-on-surface">
                  <span className="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-ink" />
                  <span>{note}</span>
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    </div>
  );
}

export function GameInfoTriggerButton({ onClick }: { onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="inline-flex min-h-11 items-center gap-1.5 rounded-md border-2 border-transparent px-2 font-display text-[13px] font-bold uppercase tracking-wide text-primary hover:bg-primary-fixed"
    >
      <Info size={16} weight="fill" />
      How to Buy
    </button>
  );
}
