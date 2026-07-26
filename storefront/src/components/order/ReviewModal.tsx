"use client";

import { useState } from "react";
import { X } from "@phosphor-icons/react/dist/ssr";
import Button from "@/components/ui/Button";
import type { Game, GamePackage } from "@/lib/catalog";

interface ReviewModalProps {
  open: boolean;
  onClose: () => void;
  game: Game;
  pkg: GamePackage;
  playerId: string;
  serverId: string;
  channelLabel: string;
  customerEmail: string;
  setCustomerEmail: (value: string) => void;
  customerName: string;
  setCustomerName: (value: string) => void;
  customerPhone: string;
  setCustomerPhone: (value: string) => void;
  submitting: boolean;
  submitError: string | null;
  onConfirm: () => void;
}

const inputClass =
  "min-h-11 w-full rounded-lg border border-border bg-bg px-3.5 text-sm text-text placeholder:text-text-muted focus:border-brand focus:outline-none";
const labelClass = "mb-1.5 block text-[13px] font-semibold";

/**
 * Last checkpoint before money moves — recap + contact details (the
 * reference kept an editable "receipt destination" field here; this
 * makes email/name genuinely required inputs in that same spot,
 * rather than a prefilled example) + a T&C checkbox that actually
 * gates "Confirm & Pay". Desktop: centered dialog. Mobile: full-screen
 * sheet. Renders only while `open`, so its scroll position/focus
 * resets fresh every time it's reopened.
 */
export default function ReviewModal({
  open,
  onClose,
  game,
  pkg,
  playerId,
  serverId,
  channelLabel,
  customerEmail,
  setCustomerEmail,
  customerName,
  setCustomerName,
  customerPhone,
  setCustomerPhone,
  submitting,
  submitError,
  onConfirm,
}: ReviewModalProps) {
  const [tcChecked, setTcChecked] = useState(false);

  if (!open) return null;

  const canConfirm = tcChecked && customerEmail.trim().length > 0 && customerName.trim().length > 0 && !submitting;

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/60 lg:items-center" onClick={onClose}>
      <div
        className="flex max-h-[90vh] w-full flex-col overflow-y-auto rounded-t-2xl border border-border bg-surface p-6 lg:max-w-[480px] lg:rounded-2xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="mb-4 flex items-center justify-between">
          <h2 className="font-display text-xl tracking-wide">Order Review</h2>
          <button onClick={onClose} aria-label="Close" className="flex h-11 w-11 items-center justify-center rounded-full hover:bg-surface-2">
            <X size={18} />
          </button>
        </div>

        <div className="mb-5 flex flex-col gap-2.5 text-sm">
          <Row k="Product" v={game.name} />
          <Row k="Package" v={pkg.name} />
          <Row k="Player ID" v={serverId ? `${playerId} (${serverId})` : playerId} />
          <Row k="Payment Channel" v={channelLabel} />
        </div>

        <hr className="mb-5 border-border" />

        <div className="mb-5 flex flex-col gap-3">
          <div>
            <label htmlFor="reviewEmail" className={labelClass}>
              Email address
            </label>
            <input
              id="reviewEmail"
              type="email"
              value={customerEmail}
              onChange={(e) => setCustomerEmail(e.target.value)}
              placeholder="you@example.com"
              className={inputClass}
            />
          </div>
          <div>
            <label htmlFor="reviewName" className={labelClass}>
              Full name
            </label>
            <input
              id="reviewName"
              type="text"
              value={customerName}
              onChange={(e) => setCustomerName(e.target.value)}
              placeholder="Full name"
              className={inputClass}
            />
          </div>
          <div>
            <label htmlFor="reviewPhone" className={labelClass}>
              Phone number (optional)
            </label>
            <input
              id="reviewPhone"
              type="tel"
              value={customerPhone}
              onChange={(e) => setCustomerPhone(e.target.value)}
              placeholder="e.g. 012-3456789"
              className={inputClass}
            />
          </div>
        </div>

        <hr className="mb-5 border-border" />

        <div className="mb-5 flex items-center justify-between">
          <span className="text-base font-extrabold">Total</span>
          <span className="text-xl font-extrabold text-brand-light">RM{pkg.priceRm.toFixed(2)}</span>
        </div>

        <label className="mb-4 flex cursor-pointer items-start gap-2.5">
          <input
            type="checkbox"
            checked={tcChecked}
            onChange={(e) => setTcChecked(e.target.checked)}
            className="mt-0.5 h-[18px] w-[18px] shrink-0 accent-brand"
          />
          <span className="text-[12.5px] leading-relaxed text-text-muted">
            I agree to the Terms &amp; Conditions and confirm that the Player ID above is correct. Delivery to an incorrect ID cannot be
            reversed.
          </span>
        </label>

        {submitError && <p className="mb-4 rounded-lg border border-error/40 bg-error/10 p-3 text-[13px] text-error">{submitError}</p>}

        <Button onClick={onConfirm} disabled={!canConfirm} className="w-full justify-center">
          {submitting ? "Processing…" : `Confirm & Pay RM${pkg.priceRm.toFixed(2)}`}
        </Button>
      </div>
    </div>
  );
}

function Row({ k, v }: { k: string; v: string }) {
  return (
    <div className="flex items-start justify-between gap-3">
      <span className="text-text-muted">{k}</span>
      <span className="text-right font-semibold">{v}</span>
    </div>
  );
}
