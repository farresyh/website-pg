"use client";

import { useState } from "react";
import Link from "next/link";
import { X } from "@phosphor-icons/react/dist/ssr";
import Button from "@/components/ui/Button";
import { ApiError } from "@/lib/api-client";
import { previewVoucher, type VoucherPreviewResult } from "@/lib/vouchers";
import type { CheckoutTotalPreview } from "@/lib/checkout";
import type { Game, GamePackage } from "@/lib/catalog";

interface ReviewModalProps {
  open: boolean;
  onClose: () => void;
  game: Game;
  pkg: GamePackage;
  /** Real breakdown from CheckoutTotalService (bug fix, 2026-08-30) — null while a fresh preview is in flight. */
  preview: CheckoutTotalPreview | null;
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
  /** ADR-024 — the applied voucher's code, or null once cleared/removed. */
  onVoucherChange: (code: string | null) => void;
}

const inputClass =
  "min-h-11 w-full rounded-md border-2 border-ink bg-surface-container-lowest px-3.5 text-sm text-on-surface placeholder:text-outline focus:border-secondary focus:outline-none";
const labelClass = "mb-1.5 block font-display text-[13px] font-bold";

/**
 * Last checkpoint before money moves — recap + contact details (the
 * reference kept an editable "receipt destination" field here; this
 * makes email/name/phone genuinely required inputs in that same spot,
 * rather than a prefilled example) + a T&C checkbox that actually
 * gates "Confirm & Pay". Desktop: centered dialog. Mobile: full-screen
 * sheet. Renders only while `open`, so its scroll position/focus
 * resets fresh every time it's reopened.
 *
 * Phone is required (not just email/name), 2026-07-30: Gamevion's
 * order endpoint rejects delivery with no phone number even though our
 * own checkout previously treated it as optional — see
 * CreateCheckoutRequest.php's doc comment and docs/adr.md's ADR-006
 * addendum for the real production order that surfaced this.
 */
export default function ReviewModal({
  open,
  onClose,
  game,
  pkg,
  preview,
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
  onVoucherChange,
}: ReviewModalProps) {
  const [tcChecked, setTcChecked] = useState(false);

  const [voucherCode, setVoucherCode] = useState("");
  const [applying, setApplying] = useState(false);
  const [voucherError, setVoucherError] = useState<string | null>(null);
  const [appliedVoucher, setAppliedVoucher] = useState<{ code: string; result: VoucherPreviewResult } | null>(null);

  if (!open) return null;

  const canConfirm =
    tcChecked &&
    customerEmail.trim().length > 0 &&
    customerName.trim().length > 0 &&
    customerPhone.trim().length > 0 &&
    !submitting;

  async function handleApplyVoucher() {
    if (voucherCode.trim().length === 0) return;
    if (customerEmail.trim().length === 0) {
      setVoucherError("Enter your email address above first.");
      return;
    }

    setApplying(true);
    setVoucherError(null);
    try {
      const result = await previewVoucher(game.id, pkg.id, voucherCode.trim(), customerEmail, customerPhone);
      setAppliedVoucher({ code: voucherCode.trim(), result });
      onVoucherChange(voucherCode.trim());
    } catch (err) {
      setAppliedVoucher(null);
      onVoucherChange(null);
      setVoucherError(err instanceof ApiError ? err.message : "Couldn't check that voucher — please try again.");
    } finally {
      setApplying(false);
    }
  }

  function handleRemoveVoucher() {
    setAppliedVoucher(null);
    setVoucherCode("");
    setVoucherError(null);
    onVoucherChange(null);
  }

  // Bug fix, 2026-08-30: only trust `memberPriceRm` as the real payable
  // total when the backend flagged it as personalized to this logged-in
  // member's own tier (CatalogController::publicPackage()) — otherwise
  // it's the anonymous "best tier" anchor and would misstate what a
  // guest (or an unresolved/lapsed member session) actually gets charged.
  // Only used as a fallback below while `preview` (the real
  // CheckoutTotalService breakdown, including the transaction fee) is
  // still loading — `preview.selling_price_sen` is already correctly
  // member-aware once it lands, no need to re-derive it here.
  const isMemberPrice = pkg.memberPricePersonalized === true && pkg.memberPriceRm != null;
  const fallbackBaseRm = isMemberPrice ? pkg.memberPriceRm! : pkg.priceRm;
  const fallbackDiscountRm = (appliedVoucher?.result.discount ?? 0) / 100;
  const fallbackPayableRm = Math.max(0, fallbackBaseRm - fallbackDiscountRm);

  // Bug fix, 2026-08-30: the old "Total" here was `package price -
  // voucher discount`, silently missing the transaction fee the real
  // charge always adds — see CheckoutService::previewTotal()'s doc
  // comment. `preview` carries the real, fee-inclusive breakdown;
  // falls back to the old package-only math only while a fresh preview
  // hasn't landed yet (e.g. right after applying a voucher).
  const packagePriceRm = preview ? preview.selling_price_sen / 100 : fallbackBaseRm;
  const transactionFeeRm = preview ? preview.transaction_fee_sen / 100 : null;
  const voucherDiscountRm = preview ? preview.voucher_discount_sen / 100 : fallbackDiscountRm;
  const payableRm = preview ? preview.final_amount_sen / 100 : fallbackPayableRm;

  return (
    <div
      className="fixed inset-0 z-50 flex items-end justify-center bg-ink/50 backdrop-blur-sm lg:items-center"
      onClick={onClose}
    >
      <div
        className="flex max-h-[90vh] w-full flex-col overflow-y-auto rounded-t-lg border-2 border-ink bg-surface p-6 neo-lg lg:max-w-[480px] lg:rounded-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="mb-4 flex items-center justify-between border-b-2 border-ink pb-3">
          <h2 className="font-display text-xl font-bold uppercase tracking-tight">Order Review</h2>
          <button
            onClick={onClose}
            aria-label="Close"
            className="flex h-11 w-11 items-center justify-center rounded-md border-2 border-ink hover:bg-surface-container"
          >
            <X size={18} weight="bold" />
          </button>
        </div>

        <div className="mb-5 flex flex-col gap-2.5 text-sm">
          <Row k="Product" v={game.name} />
          <Row k="Package" v={pkg.name} />
          <Row k="Player ID" v={serverId ? `${playerId} (${serverId})` : playerId} />
          <Row k="Payment Channel" v={channelLabel} />
        </div>

        <hr className="mb-5 border-t-2 border-ink" />

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
              Phone number
            </label>
            <input
              id="reviewPhone"
              type="tel"
              value={customerPhone}
              onChange={(e) => setCustomerPhone(e.target.value)}
              placeholder="e.g. 012-3456789"
              className={inputClass}
              required
            />
          </div>
        </div>

        <hr className="mb-5 border-t-2 border-ink" />

        <div className="mb-5">
          <label htmlFor="reviewVoucher" className={labelClass}>
            Voucher Code
          </label>
          {appliedVoucher ? (
            <div className="flex items-center justify-between rounded-lg border-2 border-primary bg-primary-fixed px-3.5 py-2.5">
              <span className="text-sm font-semibold">{appliedVoucher.code}</span>
              <button type="button" onClick={handleRemoveVoucher} className="text-[13px] font-semibold text-on-surface-variant hover:text-danger">
                Remove
              </button>
            </div>
          ) : (
            <div className="flex gap-2">
              <input
                id="reviewVoucher"
                type="text"
                value={voucherCode}
                onChange={(e) => setVoucherCode(e.target.value)}
                placeholder="Enter voucher code"
                className={inputClass}
              />
              <Button onClick={handleApplyVoucher} disabled={applying || voucherCode.trim().length === 0} className="shrink-0 px-4">
                {applying ? "Checking…" : "Apply"}
              </Button>
            </div>
          )}
          {voucherError && <p className="mt-1.5 text-[12.5px] text-danger">{voucherError}</p>}
        </div>

        <hr className="mb-5 border-t-2 border-ink" />

        <div className="mb-5 flex flex-col gap-1.5">
          {isMemberPrice && (
            <div className="flex items-center justify-between text-sm text-on-surface-variant">
              <span>Standard price</span>
              <span className="line-through">RM{pkg.priceRm.toFixed(2)}</span>
            </div>
          )}
          <Row k={isMemberPrice ? "Member Price" : "Package Price"} v={`RM${packagePriceRm.toFixed(2)}`} />
          {transactionFeeRm != null && <Row k="Transaction Fee" v={`RM${transactionFeeRm.toFixed(2)}`} />}
          {appliedVoucher && (
            <div className="flex items-center justify-between text-sm text-primary">
              <span>Voucher Discount</span>
              <span>-RM{voucherDiscountRm.toFixed(2)}</span>
            </div>
          )}
          <div className="flex items-center justify-between pt-1">
            <span className="text-base font-extrabold">Total</span>
            <span className="font-mono text-xl font-bold text-primary">RM{payableRm.toFixed(2)}</span>
          </div>
        </div>

        <label className="mb-4 flex cursor-pointer items-start gap-2.5">
          <input
            type="checkbox"
            checked={tcChecked}
            onChange={(e) => setTcChecked(e.target.checked)}
            className="mt-0.5 h-[18px] w-[18px] shrink-0 accent-primary"
          />
          <span className="text-[12.5px] leading-relaxed text-on-surface-variant">
            I agree to the{" "}
            <Link href="/terms" target="_blank" className="text-primary underline" onClick={(e) => e.stopPropagation()}>
              Terms &amp; Conditions
            </Link>{" "}
            and confirm that the Player ID above is correct. Delivery to an incorrect ID cannot be reversed.
          </span>
        </label>

        {submitError && (
          <p className="mb-4 rounded-md border-2 border-danger bg-danger-container p-3 text-[13px] text-on-danger-container">
            {submitError}
          </p>
        )}

        <Button onClick={onConfirm} disabled={!canConfirm} className="w-full justify-center">
          {submitting ? "Processing…" : payableRm === 0 ? "Confirm — Fully Covered by Voucher" : `Confirm & Pay RM${payableRm.toFixed(2)}`}
        </Button>
      </div>
    </div>
  );
}

function Row({ k, v }: { k: string; v: string }) {
  return (
    <div className="flex items-start justify-between gap-3">
      <span className="text-on-surface-variant">{k}</span>
      <span className="text-right font-semibold">{v}</span>
    </div>
  );
}
