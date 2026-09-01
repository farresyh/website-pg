"use client";

import { useEffect, useRef, useState } from "react";
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
import { getGamePackages, type Game, type GamePackage } from "@/lib/catalog";
import { getMembershipToken } from "@/lib/membership-session";
import { useMembershipToken } from "@/hooks/useMembershipToken";
import { getMe, type MembershipPlan } from "@/lib/membership";
import type { PaymentChannel } from "@/lib/payment-methods";
import Stepper, { type StepInfo } from "@/components/order/Stepper";
import StepCard from "@/components/order/StepCard";
import Step1AccountInfo from "@/components/order/Step1AccountInfo";
import PackageGrid from "@/components/order/PackageGrid";
import OrderSummarySidebar from "@/components/order/OrderSummarySidebar";
import MembershipPromoCard from "@/components/order/MembershipPromoCard";
import ReviewModal from "@/components/order/ReviewModal";

const CHANNEL_GROUPS: { key: "fpx" | "ewallet" | "card"; label: string }[] = [
  { key: "fpx", label: "Online Banking (FPX)" },
  { key: "ewallet", label: "e-Wallet" },
  { key: "card", label: "Card" },
];

interface OrderFormProps {
  game: Game;
  /** SSR-fetched, anonymous "best tier" anchor pricing — swapped for the member's real tier pricing client-side once a membership token is known. */
  packages: GamePackage[];
  paymentChannels: PaymentChannel[];
  /** ADR-055 decision 3: both tiers (`name`/`fee_sen`/`discount_percent`), `[]` when the membership kill switch is off. */
  membershipPlans: MembershipPlan[];
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
export default function OrderForm({ game, packages: initialPackages, paymentChannels, membershipPlans }: OrderFormProps) {
  const router = useRouter();
  const membershipToken = useMembershipToken();

  // Bug fix, 2026-08-30: the SSR-fetched `packages` prop is always the
  // anonymous "best tier" anchor (no localStorage access at render
  // time on the server) — a logged-in member otherwise saw Tier 2's
  // price throughout Step 2/Review even when their own tier is Tier 1.
  // Re-fetch once a membership token is available, personalized to the
  // caller's own tier (CatalogController::resolveMemberPlan()). Keyed
  // by which token/slug it was fetched for (rather than resetting
  // state synchronously in the effect) so a stale fetch never lingers
  // if the token or game changes, and falls back to the anonymous
  // `initialPackages` for every other case, including no token.
  const [personalized, setPersonalized] = useState<{ token: string; slug: string; packages: GamePackage[] } | null>(
    null,
  );
  const packages =
    personalized && personalized.token === membershipToken && personalized.slug === game.slug
      ? personalized.packages
      : initialPackages;

  // ADR-055 decision 2/6: the visitor's own tier, resolved from getMe()
  // once a membership token appears — keyed by token so a lapsed/signed-
  // out session (or an SSR render, token === null) reads back null (guest)
  // without a synchronous setState-in-effect, mirroring the `personalized`
  // pattern above.
  const [memberTier, setMemberTier] = useState<{ token: string; tierName: string | null } | null>(null);
  const activeTierName = memberTier && memberTier.token === membershipToken ? memberTier.tierName : null;

  useEffect(() => {
    if (!membershipToken) return;

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
    // ADR-055 decision 2/6: the upsell card needs the visitor's own
    // tier to know which audience it's addressing (guest vs Tier 1
    // member) and when to hide entirely (already on Tier 2). The
    // /membership page's own dashboard call, reused here — a failed or
    // lapsed-token fetch resolves to null (guest), never an error.
    getMe(membershipToken)
      .then((me) => {
        if (!cancelled) setMemberTier({ token: membershipToken, tierName: me.membership?.tierName ?? null });
      })
      .catch(() => {
        if (!cancelled) setMemberTier({ token: membershipToken, tierName: null });
      });
    return () => {
      cancelled = true;
    };
  }, [membershipToken, game.slug]);

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

  const [reviewOpen, setReviewOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
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

  function openReview() {
    idempotencyKeyRef.current = crypto.randomUUID();
    setVoucherCode(null);
    setReviewOpen(true);
  }

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
      ? JSON.stringify([selectedPackage.id, channelCode, voucherCode, membershipToken, customerEmail, customerPhone])
      : null;
  const [totalPreview, setTotalPreview] = useState<{ key: string; result: CheckoutTotalPreview } | null>(null);
  const preview = totalPreview && totalPreview.key === totalPreviewKey ? totalPreview.result : null;

  useEffect(() => {
    if (!totalPreviewKey || !selectedPackage || !channelCode) return;

    let cancelled = false;
    previewCheckoutTotal(
      {
        game_id: game.id,
        package_id: selectedPackage.id,
        channel_code: channelCode,
        voucher_code: voucherCode ?? undefined,
        customer_email: voucherCode ? customerEmail : undefined,
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
    return () => {
      cancelled = true;
    };
  }, [totalPreviewKey, game.id, selectedPackage, channelCode, voucherCode, membershipToken, customerEmail, customerPhone]);

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
          : "Couldn't reach the validation service — please try again in a moment.",
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
      customer_email: customerEmail,
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
      if (redirectUrl) {
        window.location.assign(redirectUrl);
      } else {
        router.push(`/order/status/${encodeURIComponent(result.order_number)}`);
      }
    } catch (err) {
      setSubmitError(
        err instanceof ApiError
          ? err.message
          : "Couldn't reach checkout — please try again in a moment.",
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
            const channels = paymentChannels.filter((c) => c.category === group.key);
            if (channels.length === 0) return null;
            return (
              <div key={group.key} className="mb-3.5 last:mb-0">
                <p className="mb-2 font-display text-[11px] font-bold tracking-wide text-on-surface-variant uppercase">{group.label}</p>
                <div className="flex flex-wrap gap-2">
                  {channels.map((channel) => (
                    <button
                      key={channel.channelCode}
                      type="button"
                      onClick={() => setChannelCode(channel.channelCode)}
                      className={`min-h-11 rounded-md border-2 px-3.5 text-[13px] font-semibold transition-all ${
                        channelCode === channel.channelCode
                          ? "border-primary bg-primary-fixed neo-sm"
                          : "border-ink bg-surface-container-lowest hover:bg-surface-container-low"
                      }`}
                    >
                      {channel.label}
                    </button>
                  ))}
                </div>
              </div>
            );
          })}
        </StepCard>
      </div>

      <div className="flex flex-col gap-5 lg:sticky lg:top-20">
        <OrderSummarySidebar
          game={game}
          selectedPackage={selectedPackage}
          preview={preview}
          playerId={playerId}
          serverId={game.extraField ? serverId : ""}
          ready={readyForReview}
          onReview={openReview}
        />
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

      {selectedPackage && selectedChannel && (
        <ReviewModal
          open={reviewOpen}
          onClose={() => setReviewOpen(false)}
          game={game}
          pkg={selectedPackage}
          preview={preview}
          playerId={playerId}
          serverId={game.extraField ? serverId : ""}
          channelLabel={selectedChannel.label}
          customerEmail={customerEmail}
          setCustomerEmail={setCustomerEmail}
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
    </div>
  );
}
