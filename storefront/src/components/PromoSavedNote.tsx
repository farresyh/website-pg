"use client";

import { useSyncExternalStore } from "react";
import { Check, X } from "@phosphor-icons/react/dist/ssr";
import { dismissPromoNote, promoNoteCode, subscribeToPromo } from "@/lib/promo";

/**
 * ADR-118 2026-11-07 addendum: a KOL link lands on an ordinary page, so
 * this tells the visitor it worked. It promises no amount: the code is for
 * new customers, and the saving is worked out at checkout. In the page
 * flow, under the header, so it never covers anything.
 */
export default function PromoSavedNote() {
  const code = useSyncExternalStore(subscribeToPromo, promoNoteCode, () => null);
  if (code === null) return null;

  return (
    <div className="border-b-2 border-ink bg-primary-fixed text-on-primary-fixed">
      <div className="mx-auto flex max-w-[1200px] items-center gap-2 px-4 py-1 text-[13px] leading-snug">
        <Check size={16} weight="bold" className="shrink-0" />
        <p className="flex-1">
          <span className="font-semibold">Promo code {code} saved.</span> Your saving shows at checkout.
        </p>
        <button
          type="button"
          aria-label="Close"
          onClick={() => dismissPromoNote(code)}
          className="flex size-11 shrink-0 items-center justify-center"
        >
          <X size={16} weight="bold" />
        </button>
      </div>
    </div>
  );
}
