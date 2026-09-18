"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import Image from "next/image";
import { useRouter } from "next/navigation";
import { ApiError } from "@/lib/api-client";
import {
  validatePlayer,
  submitCheckout,
  extractCheckoutRedirectUrl,
  previewCheckoutTotal,
  CheckoutContactSchema,
  type ValidatePlayerResult,
  type CheckoutTotalPreview,
} from "@/lib/checkout";
import { getGamePackages, type GameDetail, type GamePackage } from "@/lib/catalog";
import { getMembershipToken } from "@/lib/membership-session";
import { useMembershipToken } from "@/hooks/useMembershipToken";
import { getMe, type MembershipPlan } from "@/lib/membership";
import type { PaymentChannel } from "@/lib/payment-methods";
import Button from "@/components/ui/Button";
import Stepper, { type StepInfo } from "@/components/order/Stepper";
import StepCard from "@/components/order/StepCard";
import Step1AccountInfo from "@/components/order/Step1AccountInfo";
import PackageGrid from "@/components/order/PackageGrid";
import OrderSummarySidebar from "@/components/order/OrderSummarySidebar";
import MembershipPromoCard from "@/components/order/MembershipPromoCard";
import ReviewModal from "@/components/order/ReviewModal";
import { PaymentChannelIcon } from "@/components/icons/PaymentIcons";

const CHANNEL_GROUPS: { key: string; label: string }[] = [
  { key: "fpx", label: "Online Banking (FPX)" },
  { key: "duitnow_qr", label: "DuitNow QR" },
  { key: "ewallet", label: "e-Wallet" },
  { key: "card", label: "Debit & Credit Card" },
];

interface OrderFormProps {
  /** ADR-097 decision 9 — needs GameDetail (not the narrower Game), for zoneOptions. */
  game: GameDetail;
  /** SSR-fetched, anonymous "best tier" anchor pricing. */
  packages: GamePackage[];
  paymentChannels: PaymentChannel[];
  /** ADR-055 decision 3: both tiers (`name`/`fee_sen`/`discount_percent`), `[]` when the membership kill switch is off. */
  membershipPlans: MembershipPlan[];
  /**
   * ADR-071 PR2b (ADR-027 addendum) — when the membership session cookie
   * was present at SSR (`ssrMembershipToken`), `MemberAwareOrderForm`
   * resolved these server-side (personalized packages + member info), so
   * a member who verified before shopping pays no client round trip.
   * `null` for a guest.
   */
  memberPackages?: GamePackage[] | null;
  memberSession?: { tierName: string | null; email: string | null } | null;
  ssrMembershipToken?: string | null;
}

/**
 * Gated 3-step wizard (PRD §7.1): Enter ID → Choose Package → Payment
 * Method — each step locked until the one before it is done, mirrors
 * the reference design's own "decision made before building." Editing
 * the Player ID after Step 1 is done re-locks Step 2/3 (a stale
 * validation shouldn't silently carry over to a new ID). Once all 3
 * are done, the sidebar's "Review & Pay" opens the Review Modal — the
 * real last checkpoint (T&C + contact details) before submitCheckout()
 * ever fires.
 */
export default function OrderForm({
  game,
  packages: initialPackages,
  paymentChannels,
  membershipPlans,
  memberPackages = null,
  memberSession: ssrMemberSession = null,
  ssrMembershipToken = null,
}: OrderFormProps) {
  const router = useRouter();
  const membershipToken = useMembershipToken();

  // ADR-055 (bug fix, 2026-08-30) + ADR-071 PR2b: the `packages` prop is
  // the anonymous "best tier" anchor; a member sees their own tier's
  // price. Seeded from what `MemberAwareOrderForm` already resolved for
  // the SSR cookie token (`memberPackages` / `ssrMemberSession`), so a
  // member who verified before shopping pays no client round trip.
  // Keyed by token/slug so a stale value never lingers when either
  // changes.
  const [personalized, setPersonalized] = useState<{ token: string; slug: string; packages: GamePackage[] } | null>(
    ssrMembershipToken && memberPackages
      ? { token: ssrMembershipToken, slug: game.slug, packages: memberPackages }
      : null,
  );
  const packages =
    personalized && personalized.token === membershipToken && personalized.slug === game.slug
      ? personalized.packages
      : initialPackages;

  // ADR-055 decision 2/6: the visitor's own tier (+ ADR-068 decision 16:
  // their verified email, which the checkout contact field is bound to).
  const [memberSession, setMemberSession] = useState<{
    token: string;
    tierName: string | null;
    email: string | null;
  } | null>(
    ssrMembershipToken
      ? {
          token: ssrMembershipToken,
          tierName: ssrMemberSession?.tierName ?? null,
          email: ssrMemberSession?.email ?? null,
        }
      : null,
  );
  const activeSession = memberSession && memberSession.token === membershipToken ? memberSession : null;
  const activeTierName = activeSession?.tierName ?? null;
  const memberEmail = activeSession?.email ?? null;

  useEffect(() => {
    if (!membershipToken) return;
    // The server already resolved this exact token in the RSC pass
    // (MemberAwareOrderForm) — nothing to re-fetch. This effect now only
    // fires when the live cookie token has diverged from the SSR one
    // (the visitor signed in or out *while on this page*).
    if (membershipToken === ssrMembershipToken) return;

    let cancelled = false;
    getGamePackages(game.slug, membershipToken)
      .then((fetched) => {
        if (!cancelled) setPersonalized({ token: membershipToken, slug: game.slug, packages: fetched });
      })
      .catch(() => {
        // Personalization is a display nicety, not the checkout path
        // (ORD-9) — a failed re-fetch just leaves the anonymous anchor
        // pricing on screen rather than breaking the order flow.
      });
    getMe(membershipToken)
      .then((me) => {
        if (!cancelled) {
          setMemberSession({ token: membershipToken, tierName: me.membership?.tierName ?? null, email: me.email });
        }
      })
      .catch(() => {
        if (!cancelled) setMemberSession({ token: membershipToken, tierName: null, email: null });
      });
    return () => {
      cancelled = true;
    };
  }, [membershipToken, game.slug, ssrMembershipToken]);

  const [selectedPackageId, setSelectedPackageId] = useState<number | null>(null);
  const [playerId, setPlayerIdRaw] = useState("");
  const [serverId, setServerIdRaw] = useState("");
  const [step1Continued, setStep1Continued] = useState(false);
  const [verifying, setVerifying] = useState(false);
  const [verifyError, setVerifyError] = useState<string | null>(null);
  const [validationResult, setValidationResult] = useState<ValidatePlayerResult | null>(null);

  const [channelCode, setChannelCode] = useState<string | null>(null);

  const [customerEmail, setCustomerEmail] = useState("");
  const [customerName, setCustomerName] = useState("");
  const [customerPhone, setCustomerPhone] = useState("");

  // ADR-068 decision 16: a signed-in member's contact email is their
  // verified membership email, full stop — shown locked so an order can
  // never land under a mistyped address the member won't see in their
  // /membership history, and the backend enforces the same bind anyway.
  // Derived (not synced into state) to match the token-keyed read-back
  // pattern used for `personalized`/`memberSession` above. Name and
  // phone stay editable — a member legitimately tops up for others.
  const emailLocked = memberEmail !== null;
  const contactEmail = memberEmail ?? customerEmail;

  const [reviewOpen, setReviewOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  // ADR-071 PR3 — once a checkout succeeds we are leaving this page for
  // CHIP (or the order-status page). This stays true through that
  // navigation so the "Taking you to payment…" overlay covers the gap
  // rather than the page appearing to hang.
  const [redirecting, setRedirecting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  // One key per Review Modal open, reused across every resubmit within
  // that same open (double-click, retry-after-error) — a fresh open
  // gets a fresh key, so an unrelated later purchase is never
  // collapsed into an earlier one.
  const idempotencyKeyRef = useRef<string | null>(null);

  // ADR-024 — set by ReviewModal's own Apply button, read back here so
  // the final POST /api/checkout can include it. Reset on every fresh
  // Review Modal open, same reasoning as the idempotency key: an
  // unrelated later purchase must never inherit an earlier attempt's
  // applied voucher.
  const [voucherCode, setVoucherCode] = useState<string | null>(null);

  // useCallback so the memoized OrderSummarySidebar isn't re-rendered
  // just because OrderForm re-rendered (ADR-071 PR2).
  const openReview = useCallback(() => {
    idempotencyKeyRef.current = crypto.randomUUID();
    setVoucherCode(null);
    try {
      const saved = localStorage.getItem("pg_guest_contact");
      if (saved) {
        const parsed = JSON.parse(saved);
        if (parsed.email && typeof parsed.email === "string") setCustomerEmail((prev) => prev || parsed.email);
        if (parsed.name && typeof parsed.name === "string") setCustomerName((prev) => prev || parsed.name);
        if (parsed.phone && typeof parsed.phone === "string") setCustomerPhone((prev) => prev || parsed.phone);
      }
    } catch {
      // safe fallback
    }
    setReviewOpen(true);
  }, []);

  // Stable identity so ReviewModal's focus-trap effect (keyed on `open`)
  // isn't handed a fresh `onClose` on every keystroke in its contact
  // fields — see the effect's own comment in ReviewModal.
  const closeReview = useCallback(() => setReviewOpen(false), []);

  const selectedPackage = packages.find((p) => p.id === selectedPackageId) ?? null;
  const selectedChannel = paymentChannels.find((c) => c.channelCode === channelCode) ?? null;

  // ADR-055: the upsell card always promotes Tier 2 (the top tier). For
  // a member, `packages` above is personalized to their own tier, so the
  // card's Tier 2 number must come from the anonymous SSR anchor instead —
  // `initialPackages` carries the "best tier" (highest discount) member
  // price on every package. null when membership is off / no anchor.
  const selectedTier2MemberPriceRm = selectedPackage
    ? initialPackages.find((p) => p.id === selectedPackage.id)?.memberPriceRm ?? null
    : null;

  // ADR-055 decision 2/5: the promo card lives as its own card below the
  // Order Summary card (separate rounded card, not a section inside it).
  // It promotes the top tier specifically (highest discount — robust
  // against admin renaming a tier's `name`), appears only once a package
  // is selected, and hides entirely when the visitor is already on that
  // top tier (nothing left to upsell) or when membership is off (no
  // plans, no Tier 2 anchor price).
  const topTier =
    membershipPlans.length > 0 ? membershipPlans.reduce((a, b) => (b.discountPercent > a.discountPercent ? b : a)) : null;
  const showPromo =
    selectedPackage !== null && topTier !== null && selectedTier2MemberPriceRm !== null && activeTierName !== topTier.name;

  // Bug fix, 2026-08-30: Order Summary/Review Modal used to compute
  // "Total" as just `package price - voucher discount`, silently
  // omitting the transaction fee the real charge always includes.
  // Fetches the real breakdown (CheckoutTotalService, via
  // previewCheckoutTotal()) once a package AND channel are both chosen
  // — a channel's fee config is per-channel, so there's nothing to
  // preview before then. Keyed by every input that affects the result
  // (rather than resetting state synchronously in the effect) so a
  // stale preview is never shown mid-refetch or after a selection
  // changes back.
  const totalPreviewKey =
    selectedPackage && channelCode
      ? JSON.stringify([selectedPackage.id, channelCode, voucherCode, membershipToken, contactEmail, customerPhone])
      : null;
  const [totalPreview, setTotalPreview] = useState<{ key: string; result: CheckoutTotalPreview } | null>(null);
  const preview = totalPreview && totalPreview.key === totalPreviewKey ? totalPreview.result : null;

  useEffect(() => {
    if (!totalPreviewKey || !selectedPackage || !channelCode) return;

    let cancelled = false;
    // ADR-071 PR2: 150ms debounce — browsing packages, or typing in the
    // Review Modal's email/phone (a voucher makes those part of the
    // key), shouldn't fire a `previewCheckoutTotal` round trip per
    // keystroke. A dep change within the window clears the pending call.
    const timer = setTimeout(() => {
      previewCheckoutTotal(
        {
          game_id: game.id,
          package_id: selectedPackage.id,
          channel_code: channelCode,
          voucher_code: voucherCode ?? undefined,
          customer_email: voucherCode ? contactEmail : undefined,
          customer_phone: voucherCode ? customerPhone : undefined,
        },
        membershipToken ?? undefined,
      )
        .then((result) => {
          if (!cancelled) setTotalPreview({ key: totalPreviewKey, result });
        })
        .catch(() => {
          // A failed preview just leaves the sidebar/modal without a fee
          // breakdown (falls back to package-price-only display) rather
          // than blocking the order flow — the real charge is still
          // computed correctly server-side at checkout regardless (ORD-9).
        });
    }, 150);

    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [totalPreviewKey, game.id, selectedPackage, channelCode, voucherCode, membershipToken, contactEmail, customerPhone]);

  // Editing the ID after Step 1 was completed invalidates that
  // completion — re-lock downstream steps rather than trust stale state.
  function updatePlayerId(value: string) {
    setPlayerIdRaw(value);
    if (step1Continued) {
      setStep1Continued(false);
      setValidationResult(null);
    }
  }
  function updateServerId(value: string) {
    setServerIdRaw(value);
    if (step1Continued) {
      setStep1Continued(false);
      setValidationResult(null);
    }
  }

  async function handleVerify() {
    setVerifying(true);
    setVerifyError(null);
    setValidationResult(null);
    try {
      const result = await validatePlayer(game.id, playerId, game.extraField ? serverId : undefined);
      setValidationResult(result);
      if (result.status === "valid") setStep1Continued(true);
    } catch (err) {
      setVerifyError(
        err instanceof ApiError
          ? err.message
          : "Couldn't reach the validation service. Please try again in a moment.",
      );
    } finally {
      setVerifying(false);
    }
  }

  const step2Complete = selectedPackage !== null;
  const step3Complete = channelCode !== null;
  const readyForReview = step1Continued && step2Complete && step3Complete;

  const steps: StepInfo[] = [
    { label: "Enter ID", state: step1Continued ? "done" : "active" },
    { label: "Choose Package", state: step2Complete ? "done" : step1Continued ? "active" : "pending" },
    { label: "Payment Method", state: step3Complete ? "done" : step2Complete ? "active" : "pending" },
  ];

  async function handleConfirmPayment() {
    if (!selectedPackage || !channelCode) return;

    // ADR-044 decision 4 — client-side UX only, never a security
    // boundary; CreateCheckoutRequest is still sole authority server-side.
    // Catches a malformed email/name/phone before the round trip instead
    // of after, using the same rules as the backend FormRequest.
    const contact = CheckoutContactSchema.safeParse({
      customer_email: contactEmail,
      customer_name: customerName,
      customer_phone: customerPhone,
    });
    if (!contact.success) {
      setSubmitError(contact.error.issues[0]?.message ?? "Check your contact details and try again.");
      return;
    }

    setSubmitting(true);
    setSubmitError(null);
    try {
      const result = await submitCheckout({
        game_id: game.id,
        package_id: selectedPackage.id,
        customer_email: contact.data.customer_email,
        customer_name: contact.data.customer_name,
        customer_phone: contact.data.customer_phone,
        player_id: playerId,
        server_id: game.extraField ? serverId : undefined,
        channel_code: channelCode,
        idempotency_key: idempotencyKeyRef.current ?? crypto.randomUUID(),
        voucher_code: voucherCode ?? undefined,
        // CHIP needs these for its hosted checkout page (FPX, DuitNow QR)
        // — where it sends the customer back to after they pay. We do not
        // have the order_number yet at this point (only the backend does,
        // right before it calls CHIP) — this generic URL is only a fallback:
        // CheckoutService::requestPayment() overwrites it server-side
        // with the real /order/status/{order_number} once the Order
        // exists, so the customer lands straight on their own order
        // instead of the general lookup page.
        channel_properties: {
          success_return_url: `${window.location.origin}/track-order`,
          failure_return_url: `${window.location.origin}/track-order`,
        },
      }, getMembershipToken() ?? undefined);

      const redirectUrl = extractCheckoutRedirectUrl(result.payment_actions);
      setRedirecting(true);
      if (redirectUrl) {
        window.location.assign(redirectUrl);
      } else {
        router.push(`/order/status/${encodeURIComponent(result.order_number)}`);
      }
    } catch (err) {
      setSubmitError(
        err instanceof ApiError
          ? err.message
          : "Couldn't reach checkout. Please try again in a moment.",
      );
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="grid gap-5 lg:grid-cols-[1fr_360px] lg:items-start">
      <div className="flex flex-col gap-5">
        <Stepper steps={steps} />

        <StepCard number={1} title="Enter Your Account Info" locked={false}>
          <Step1AccountInfo
            game={game}
            playerId={playerId}
            setPlayerId={updatePlayerId}
            serverId={serverId}
            setServerId={updateServerId}
            onVerify={handleVerify}
            onContinue={() => setStep1Continued(true)}
            verifying={verifying}
            verifyError={verifyError}
            result={validationResult}
          />
        </StepCard>

        <StepCard number={2} title="Choose Package" locked={!step1Continued} lockHint="Complete Step 1 first">
          <PackageGrid packages={packages} selectedId={selectedPackageId} onSelect={setSelectedPackageId} />
          {packages.length === 0 && <p className="text-sm text-on-surface-variant">No packages available for this game yet.</p>}
        </StepCard>

        <StepCard number={3} title="Choose Payment Method" locked={!step2Complete} lockHint="Choose a package first">
          {CHANNEL_GROUPS.map((group) => {
            const channels = paymentChannels.filter(
              (c) =>
                c.category.toLowerCase() === group.key ||
                (group.key === "duitnow_qr" &&
                  (c.category.toLowerCase() === "duitnow_qr" || c.channelCode.toLowerCase().includes("duitnow"))),
            );
            if (channels.length === 0) return null;
            // One channel in the group → the tile is its brand mark
            // alone (the group heading above already names the method,
            // and the mark carries the wordmark). Several channels →
            // keep the text so they stay tellable apart.
            const soloChannel = channels.length === 1;
            return (
              <div key={group.key} className="mb-3.5 last:mb-0">
                <p className="mb-2 font-display text-[11px] font-bold tracking-wide text-on-surface-variant uppercase">{group.label}</p>
                <div className="flex flex-wrap gap-2">
                  {channels.map((channel) => (
                    <button
                      key={channel.channelCode}
                      type="button"
                      onClick={() => setChannelCode(channel.channelCode)}
                      className={`inline-flex min-h-11 items-center gap-2.5 rounded-md border-2 px-3.5 py-2 text-[13px] font-semibold transition-all ${
                        channelCode === channel.channelCode
                          ? "border-primary bg-primary-fixed neo-sm"
                          : "border-ink bg-surface-container-lowest hover:bg-surface-container-low"
                      }`}
                    >
                      <PaymentChannelIcon
                        channelCode={channel.channelCode}
                        category={channel.category}
                        className={soloChannel ? "h-7 w-auto shrink-0" : "h-4.5 w-auto shrink-0"}
                      />
                      <span className={soloChannel ? "sr-only" : undefined}>{channel.label}</span>
                    </button>
                  ))}
                </div>
              </div>
            );
          })}

          <div className="mt-4 flex flex-wrap items-center justify-between gap-2 rounded-md border-2 border-ink/15 bg-surface-container p-2.5 text-xs text-on-surface-variant">
            <div className="flex items-center gap-2">
              <span className="font-semibold text-on-surface">Secured &amp; Powered by</span>
              <Image
                src="/images/chip/powered-by-chip-long.svg"
                alt="Powered by CHIP"
                width={130}
                height={18}
                className="h-4 w-auto object-contain"
              />
            </div>
            <span className="text-[11px] text-on-surface-variant">BNM Compliant • 256-bit SSL</span>
          </div>
        </StepCard>
      </div>

      <div className="flex flex-col gap-5 lg:sticky lg:top-20">
        {/* The desktop summary. On mobile it's replaced by the sticky
          * bottom bar below — the customer never has to scroll past 60
          * packages to find the total and the CTA (ADR-071 PR3). */}
        <div className="hidden lg:block">
          <OrderSummarySidebar
            game={game}
            selectedPackage={selectedPackage}
            preview={preview}
            playerId={playerId}
            serverId={game.extraField ? serverId : ""}
            ready={readyForReview}
            onReview={openReview}
          />
        </div>
        {showPromo && topTier && selectedPackage && selectedTier2MemberPriceRm !== null && (
          <MembershipPromoCard
            plan={topTier}
            packageName={selectedPackage.name}
            sellingPriceRm={selectedPackage.priceRm}
            memberPriceRm={selectedTier2MemberPriceRm}
            activeTierName={activeTierName}
          />
        )}
      </div>

      {/* Sticky mobile summary bar — always in reach, no scrolling to
        * the bottom of the package list to find the total or the CTA
        * (ADR-071 PR3). Replaces the bottom nav on this route. */}
      <div className="fixed inset-x-0 bottom-0 z-50 border-t-2 border-ink bg-surface-container-lowest px-4 pt-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] neo-lg lg:hidden">
        <div className="mx-auto flex max-w-[560px] items-center gap-3">
          <div className="min-w-0 flex-1">
            <p className="font-display text-[10px] font-bold uppercase tracking-wide text-on-surface-variant">Total</p>
            <p className="font-mono text-lg font-bold text-primary">
              RM{(preview ? preview.final_amount_sen / 100 : selectedPackage?.priceRm ?? 0).toFixed(2)}
            </p>
          </div>
          <Button onClick={openReview} disabled={!readyForReview} size="sm" className="shrink-0">
            {readyForReview ? "Review & Pay" : "Complete steps"}
          </Button>
        </div>
      </div>

      {selectedPackage && selectedChannel && (
        <ReviewModal
          open={reviewOpen}
          onClose={closeReview}
          game={game}
          pkg={selectedPackage}
          preview={preview}
          playerId={playerId}
          serverId={game.extraField ? serverId : ""}
          channelLabel={selectedChannel.label}
          customerEmail={contactEmail}
          setCustomerEmail={setCustomerEmail}
          emailLocked={emailLocked}
          customerName={customerName}
          setCustomerName={setCustomerName}
          customerPhone={customerPhone}
          setCustomerPhone={setCustomerPhone}
          submitting={submitting}
          submitError={submitError}
          onConfirm={handleConfirmPayment}
          onVoucherChange={setVoucherCode}
        />
      )}

      {redirecting && (
        <div
          className="fixed inset-0 z-[70] flex flex-col items-center justify-center gap-4 bg-surface/95 backdrop-blur-sm"
          role="status"
          aria-live="polite"
        >
          <span
            className="h-10 w-10 animate-spin rounded-full border-[3px] border-ink border-t-transparent"
            aria-hidden="true"
          />
          <p className="font-display text-lg font-bold uppercase tracking-tight">Taking you to payment…</p>
          <p className="text-sm text-on-surface-variant">Please don&apos;t close this window.</p>
        </div>
      )}
    </div>
  );
}
