"use client";

import { useEffect, useRef, useState } from "react";
import Image from "next/image";
import Link from "next/link";
import { ArrowRight, Crown, X } from "@phosphor-icons/react/dist/ssr";
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
  /** ADR-068 decision 16 — a signed-in member's email is fixed to their verified membership address. */
  emailLocked?: boolean;
  customerName: string;
  setCustomerName: (value: string) => void;
  customerPhone: string;
  setCustomerPhone: (value: string) => void;
  submitting: boolean;
  submitError: string | null;
  onConfirm: () => void;
  /** ADR-024 — the applied voucher's code, or null once cleared/removed. */
  onVoucherChange: (code: string | null) => void;
  /** ADR-055 second touchpoint (design preview) — same `showPromo` gate OrderForm already computes for the sidebar card: false/omitted hides the strip entirely (no plans, or the visitor is already on the top tier). */
  showMembershipPromo?: boolean;
  /** The top tier's member price for this package, RM — same value the sidebar card promotes. */
  topTierMemberPriceRm?: number | null;
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
  emailLocked = false,
  customerName,
  setCustomerName,
  customerPhone,
  setCustomerPhone,
  submitting,
  submitError,
  onConfirm,
  onVoucherChange,
  showMembershipPromo = false,
  topTierMemberPriceRm = null,
}: ReviewModalProps) {
  const [tcChecked, setTcChecked] = useState(false);
  const [rememberMe, setRememberMe] = useState(true);

  const [voucherCode, setVoucherCode] = useState("");
  const [applying, setApplying] = useState(false);
  const [voucherError, setVoucherError] = useState<string | null>(null);
  const [appliedVoucher, setAppliedVoucher] = useState<{ code: string; result: VoucherPreviewResult } | null>(null);

  function handleConfirmWithRemember() {
    try {
      if (rememberMe) {
        localStorage.setItem(
          "pg_guest_contact",
          JSON.stringify({
            name: customerName,
            email: customerEmail,
            phone: customerPhone,
          }),
        );
      } else {
        localStorage.removeItem("pg_guest_contact");
      }
    } catch {
      // safe fallback if storage blocked
    }
    onConfirm();
  }

  const sheetRef = useRef<HTMLDivElement>(null);

  // The focus-trap effect below must run only on open/close — never
  // because a callback prop changed identity. `onClose` is read through
  // a ref so an unmemoized parent callback (OrderForm re-renders on every
  // keystroke in the contact fields) can't retrigger the effect, which
  // would call `sheetRef.current?.focus()` again and yank focus out of
  // the input the user is typing in.
  const onCloseRef = useRef(onClose);
  useEffect(() => {
    onCloseRef.current = onClose;
  });

  // ADR-071 PR3 — this is the last checkpoint before money moves, so it
  // gets real dialog discipline: body scroll lock, Esc to close, focus
  // moved into the sheet and trapped within it, focus restored on close.
  const FOCUSABLE =
    'a[href],button:not([disabled]),input:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
  useEffect(() => {
    if (!open) return;

    const previouslyFocused = document.activeElement as HTMLElement | null;
    const prevBodyOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";

    const visibleFocusable = () =>
      Array.from(sheetRef.current?.querySelectorAll<HTMLElement>(FOCUSABLE) ?? []).filter(
        (el) => el.offsetParent !== null,
      );

    // Focus the sheet itself first — reading from the top, not dropped
    // into the middle at whatever the first input is.
    sheetRef.current?.focus();

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        onCloseRef.current();
        return;
      }
      if (event.key !== "Tab") return;
      const items = visibleFocusable();
      if (items.length === 0) return;
      const first = items[0];
      const last = items[items.length - 1];
      const active = document.activeElement;
      if (event.shiftKey && (active === first || active === sheetRef.current)) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
      }
    };
    document.addEventListener("keydown", onKeyDown);

    return () => {
      document.removeEventListener("keydown", onKeyDown);
      document.body.style.overflow = prevBodyOverflow;
      previouslyFocused?.focus?.();
    };
  }, [open]);

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
      setVoucherError(err instanceof ApiError ? err.message : "Couldn't check that voucher. Please try again.");
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
      className="fixed inset-0 z-[60] flex items-end justify-center bg-ink/50 backdrop-blur-sm lg:items-center"
      onClick={onClose}
    >
      <div
        ref={sheetRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby="review-modal-title"
        tabIndex={-1}
        // The confirm button lives in a sticky footer inside this
        // scroll box (below), so it is always visible above the mobile
        // bottom nav (ADR-071 PR3) — the sheet no longer runs off-screen.
        className="flex max-h-[92dvh] w-full flex-col overflow-y-auto rounded-t-lg border-2 border-ink bg-surface focus:outline-none lg:max-w-[480px] lg:rounded-lg lg:neo-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center justify-between border-b-2 border-ink bg-surface px-6 pt-6 pb-3">
          <h2 id="review-modal-title" className="font-display text-xl font-bold uppercase tracking-tight">
            Order Review
          </h2>
          <button
            onClick={onClose}
            aria-label="Close"
            className="flex h-11 w-11 items-center justify-center rounded-md border-2 border-ink hover:bg-surface-container"
          >
            <X size={18} weight="bold" />
          </button>
        </div>

        <div className="px-6 pt-5">
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
              readOnly={emailLocked}
              aria-readonly={emailLocked}
              className={emailLocked ? `${inputClass} cursor-not-allowed opacity-70` : inputClass}
            />
            {emailLocked && (
              <p className="mt-1.5 text-xs text-outline">
                Your member email. Sign out on the Membership page to use a different address.
              </p>
            )}
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
            <p className="mt-1.5 text-[11.5px] text-on-surface-variant">
              Required for FPX banking verification &amp; instant WhatsApp delivery receipt.
            </p>
          </div>

          <label className="flex cursor-pointer items-center gap-2 pt-1 text-[12px] text-on-surface-variant">
            <input
              type="checkbox"
              checked={rememberMe}
              onChange={(e) => setRememberMe(e.target.checked)}
              className="h-4 w-4 rounded accent-primary"
            />
            <span>Remember contact details for faster checkout</span>
          </label>
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
          {!isMemberPrice && showMembershipPromo && topTierMemberPriceRm !== null && (
            <div className="flex min-h-[60px] items-center justify-between gap-3 rounded-lg border-2 border-ink bg-secondary-container px-3.5 py-2 text-on-secondary-container">
              <div className="flex items-center gap-2">
                <Crown size={16} weight="fill" className="shrink-0 text-on-secondary-container/70" />
                <div>
                  <p className="text-[10px] font-bold uppercase tracking-wide text-on-secondary-container/70">
                    Members Pay
                  </p>
                  <p className="font-mono text-xl font-bold leading-tight">
                    RM{topTierMemberPriceRm.toFixed(2)}
                  </p>
                </div>
              </div>
              <Link
                href="/membership"
                target="_blank"
                onClick={(e) => e.stopPropagation()}
                className="flex shrink-0 items-center gap-1 text-[11.5px] font-bold underline underline-offset-2"
              >
                Become a Member
                <ArrowRight size={11} weight="bold" />
              </Link>
            </div>
          )}
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

        <label className="mb-1 flex cursor-pointer items-start gap-2.5">
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
        </div>

        {/* Sticky footer — the confirm CTA is always in view above the
          * mobile bottom nav, no matter how far the sheet scrolls
          * (ADR-071 PR3). */}
        <div className="sticky bottom-0 mt-4 border-t-2 border-ink bg-surface px-6 pt-4 pb-[calc(1.5rem+env(safe-area-inset-bottom))] lg:pb-6">
          {submitError && (
            <p className="mb-3 rounded-md border-2 border-danger bg-danger-container p-3 text-[13px] text-on-danger-container">
              {submitError}
            </p>
          )}
          {!tcChecked && (
            <p className="mb-2 text-center text-[12px] text-on-surface-variant">Tick the box above to continue.</p>
          )}
          <Button onClick={handleConfirmWithRemember} disabled={!canConfirm} className="w-full justify-center">
            {submitting
              ? "Processing…"
              : payableRm === 0
                ? "Confirm: Fully Covered by Voucher"
                : `Confirm & Pay RM${payableRm.toFixed(2)}`}
          </Button>

          <div className="mt-3 flex items-center justify-center gap-1.5 text-[11px] text-on-surface-variant">
            <span>Secured via</span>
            <Image
              src="/images/chip/powered-by-chip-long.svg"
              alt="Powered by CHIP"
              width={100}
              height={16}
              className="h-3.5 w-auto object-contain"
            />
            <span>• Bank Negara Malaysia compliant</span>
          </div>
        </div>
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
