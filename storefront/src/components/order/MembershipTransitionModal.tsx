"use client";

/**
 * Founder-requested guided note (2026-09-24): Renew/Upgrade both extend
 * `expires_at` by 30 days from the CURRENT expiry (not reset from today)
 * and never touch `quota_remaining_sen` — quota only refills at the
 * rolling 30-day cycle boundary (`ResetMembershipCyclesCommand`), a
 * deliberate anti-abuse call (ADR-027 addendum Q2/Q5, backend/
 * MembershipFeeService::applyTransition()). Neither mechanic is obvious
 * from the button label alone, so this confirms understanding before the
 * customer pays. Bilingual (EN+BM) per the founder's request — this
 * storefront has no i18n locale switcher, so both languages render
 * together rather than picking one.
 *
 * Same modal chrome as GameInfoModal.tsx (centered at every breakpoint,
 * bordered neo-brutalist card) for visual consistency.
 */

import { Info, X } from "@phosphor-icons/react/dist/ssr";
import Button from "@/components/ui/Button";

const COPY: Record<
  "renew" | "upgrade",
  { title: string; en: string; bm: string }
> = {
  renew: {
    title: "Before you renew",
    en:
      "Renewing extends your membership by 30 days from your CURRENT expiry date — it doesn't reset early. Your remaining quota stays as-is; it only refills at your next billing cycle.",
    bm:
      "Renew akan sambung tempoh membership anda 30 hari dari tarikh luput SEDIA ADA — bukan reset awal. Baki quota anda kekal sama; ia hanya refill bila cycle akan datang bermula.",
  },
  upgrade: {
    title: "Before you upgrade",
    en:
      "Upgrading switches you to the new tier's price right away and extends your expiry by 30 days. But your quota stays at your CURRENT tier's remaining amount until your next billing cycle — the new tier's higher quota only kicks in then.",
    bm:
      "Upgrade akan tukar anda ke harga tier baru serta-merta dan tambah tempoh 30 hari. Tapi quota anda kekal ikut baki tier SEKARANG sehingga cycle akan datang — quota tier baru yang lebih tinggi hanya bermula lepas tu.",
  },
};

export default function MembershipTransitionModal({
  relation,
  onConfirm,
  onClose,
}: {
  relation: "renew" | "upgrade";
  onConfirm: () => void;
  onClose: () => void;
}) {
  const copy = COPY[relation];

  return (
    <div className="fixed inset-0 z-[60] flex items-center justify-center bg-ink/50 p-4" onClick={onClose}>
      <div
        className="flex max-h-[85vh] w-full max-w-[460px] flex-col overflow-y-auto rounded-lg border-2 border-ink bg-surface neo-lg p-6"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="mb-4 flex items-center justify-between border-b-2 border-ink pb-3">
          <h2 className="font-display text-lg font-bold uppercase tracking-tight">{copy.title}</h2>
          <button
            onClick={onClose}
            aria-label="Close"
            className="flex h-11 w-11 shrink-0 items-center justify-center rounded-md border-2 border-ink hover:bg-surface-container"
          >
            <X size={18} />
          </button>
        </div>

        <div className="mb-4 flex flex-col gap-3">
          <div className="rounded-md border-2 border-ink bg-surface-container p-4">
            <div className="mb-2 flex items-center gap-2">
              <Info size={16} weight="fill" className="shrink-0 text-primary-on-surface" />
              <span className="font-display text-[11px] font-bold uppercase tracking-wide text-on-surface-variant">
                English
              </span>
            </div>
            <p className="text-sm leading-relaxed text-on-surface">{copy.en}</p>
          </div>

          <div className="rounded-md border-2 border-ink bg-surface-container p-4">
            <div className="mb-2 flex items-center gap-2">
              <Info size={16} weight="fill" className="shrink-0 text-primary-on-surface" />
              <span className="font-display text-[11px] font-bold uppercase tracking-wide text-on-surface-variant">
                Bahasa Melayu
              </span>
            </div>
            <p className="text-sm leading-relaxed text-on-surface">{copy.bm}</p>
          </div>
        </div>

        <div className="flex gap-3">
          <Button variant="outline" size="sm" onClick={onClose} className="flex-1">
            Cancel
          </Button>
          <Button variant="primary" size="sm" onClick={onConfirm} className="flex-1">
            I Understand, Continue
          </Button>
        </div>
      </div>
    </div>
  );
}
