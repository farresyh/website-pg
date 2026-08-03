"use client";

import { useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { ApiError } from "@/lib/api-client";
import { validatePlayer, submitCheckout, extractCheckoutRedirectUrl, type ValidatePlayerResult } from "@/lib/checkout";
import type { Game, GamePackage } from "@/lib/catalog";
import type { PaymentChannel } from "@/lib/payment-methods";
import Stepper, { type StepInfo } from "@/components/order/Stepper";
import StepCard from "@/components/order/StepCard";
import Step1AccountInfo from "@/components/order/Step1AccountInfo";
import PackageGrid from "@/components/order/PackageGrid";
import OrderSummarySidebar from "@/components/order/OrderSummarySidebar";
import ReviewModal from "@/components/order/ReviewModal";

const CHANNEL_GROUPS: { key: "fpx" | "ewallet" | "card"; label: string }[] = [
  { key: "fpx", label: "Online Banking (FPX)" },
  { key: "ewallet", label: "e-Wallet" },
  { key: "card", label: "Card" },
];

interface OrderFormProps {
  game: Game;
  packages: GamePackage[];
  paymentChannels: PaymentChannel[];
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
export default function OrderForm({ game, packages, paymentChannels }: OrderFormProps) {
  const router = useRouter();

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

  function openReview() {
    idempotencyKeyRef.current = crypto.randomUUID();
    setReviewOpen(true);
  }

  const selectedPackage = packages.find((p) => p.id === selectedPackageId) ?? null;
  const selectedChannel = paymentChannels.find((c) => c.channelCode === channelCode) ?? null;

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
    setSubmitting(true);
    setSubmitError(null);
    try {
      const result = await submitCheckout({
        game_id: game.id,
        package_id: selectedPackage.id,
        customer_email: customerEmail,
        customer_name: customerName,
        customer_phone: customerPhone,
        player_id: playerId,
        server_id: game.extraField ? serverId : undefined,
        channel_code: channelCode,
        idempotency_key: idempotencyKeyRef.current ?? crypto.randomUUID(),
        // Xendit requires these for redirect-based channels (FPX, some
        // e-wallets) — where it sends the customer back to after they
        // complete payment on its own hosted page. We don't have the
        // order_number yet at this point (only the backend does, right
        // before it calls Xendit) — this generic URL is only a fallback:
        // CheckoutService::requestPayment() overwrites it server-side
        // with the real /order/status/{order_number} once the Order
        // exists, so the customer lands straight on their own order
        // instead of the general lookup page.
        channel_properties: {
          success_return_url: `${window.location.origin}/track-order`,
          failure_return_url: `${window.location.origin}/track-order`,
        },
      });

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
          {packages.length === 0 && <p className="text-sm text-text-muted">No packages available for this game yet.</p>}
        </StepCard>

        <StepCard number={3} title="Choose Payment Method" locked={!step2Complete} lockHint="Choose a package first">
          {CHANNEL_GROUPS.map((group) => {
            const channels = paymentChannels.filter((c) => c.category === group.key);
            if (channels.length === 0) return null;
            return (
              <div key={group.key} className="mb-3.5 last:mb-0">
                <p className="mb-1.5 text-[11.5px] font-bold tracking-wide text-text-muted uppercase">{group.label}</p>
                <div className="flex flex-wrap gap-2">
                  {channels.map((channel) => (
                    <button
                      key={channel.channelCode}
                      type="button"
                      onClick={() => setChannelCode(channel.channelCode)}
                      className={`min-h-11 rounded-lg border px-3.5 text-[13px] font-semibold transition-colors ${
                        channelCode === channel.channelCode ? "border-brand bg-brand/10" : "border-border bg-bg hover:border-brand-light"
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

      <OrderSummarySidebar
        game={game}
        selectedPackage={selectedPackage}
        playerId={playerId}
        serverId={game.extraField ? serverId : ""}
        ready={readyForReview}
        onReview={openReview}
      />

      {selectedPackage && selectedChannel && (
        <ReviewModal
          open={reviewOpen}
          onClose={() => setReviewOpen(false)}
          game={game}
          pkg={selectedPackage}
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
        />
      )}
    </div>
  );
}
