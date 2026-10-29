"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { ApiError } from "@/lib/api-client";
import {
  CheckoutContactSchema,
  extractCheckoutRedirectUrl,
  previewCheckoutTotal,
  submitCheckout,
  type CheckoutTotalPreview,
} from "@/lib/checkout";
import type { Game, GamePackage } from "@/lib/catalog";
import { quoteJoki, type JokiCatalog, type JokiQuote, type JokiRank, type JokiSelection } from "@/lib/joki";
import type { PaymentChannel } from "@/lib/payment-methods";
import Button from "@/components/ui/Button";
import Stepper, { type StepInfo } from "@/components/order/Stepper";
import StepCard from "@/components/order/StepCard";
import LoginAccountFields, { loginFormReady, loginIdentifier } from "@/components/order/LoginAccountFields";
import PaymentChannelPicker from "@/components/order/PaymentChannelPicker";
import OrderSummarySidebar from "@/components/order/OrderSummarySidebar";
import ReviewModal from "@/components/order/ReviewModal";
import { idInputClass, idLabelClass } from "@/components/order/idFields";
import JokiRankStepper, { jokiStops, rankAt } from "@/components/joki/JokiRankStepper";
import { CaretDoubleRight } from "@phosphor-icons/react/dist/ssr";

type Mode = "calculator" | "route";

/**
 * ADR-126 decisions 3-8, 12, 13: the `/joki` order, the same gated
 * three-step shape as the top-up order page (OrderForm) and the same
 * checkout: Choose your climb → Account & requests → Payment, then the
 * shared Review Modal. The climb is priced only by the backend
 * (`/api/joki/quote`, then `/api/checkout/preview-totals` with the fee),
 * the same JokiPricingService checkout charges with — never here (ORD-9).
 */
export default function JokiOrderForm({ catalog, paymentChannels, whatsappHref }: {
  catalog: JokiCatalog;
  paymentChannels: PaymentChannel[];
  whatsappHref: string | null;
}) {
  const router = useRouter();
  const { tiers, routes, settings, credentialFields } = catalog;
  const sellableRoutes = routes.filter((r) => !r.is_quotation);
  const quotationRoutes = routes.filter((r) => r.is_quotation);

  // The joki game as the shared order components read it.
  const game: Game = {
    id: catalog.game.id,
    slug: catalog.game.slug,
    name: catalog.game.name,
    category: "Joki",
    imageUrl: catalog.game.image_url,
    priceFromRm: null,
    extraField: null,
    playerValidatorEnabled: false,
    addedAt: "",
  };

  // Step 1: the climb.
  const [mode, setMode] = useState<Mode>("calculator");
  // Start where most customers are, like the reference calculators: the
  // second-highest divisional tier's lowest division, one step to go.
  // The price shows at once.
  const fromStops = jokiStops(tiers, true);
  const toStops = jokiStops(tiers, false);
  const divisional = tiers.filter((t) => t.kind === "divisional");
  const startTier = divisional[Math.max(0, divisional.length - 2)];
  const startIndex = Math.max(0, fromStops.findIndex((s) => s.tier.id === startTier?.id));
  const [from, setFromRaw] = useState<JokiRank | null>(() => (fromStops[startIndex] ? rankAt(fromStops[startIndex]) : null));
  const [to, setTo] = useState<JokiRank | null>(() => (toStops[startIndex + 1] ? rankAt(toStops[startIndex + 1]) : null));

  // Where a rank sits on the target's ladder (Grading = the first Mythic band).
  function toIndexOf(rank: JokiRank): number {
    return toStops.findIndex((s) => s.tier.id === rank.tier_id && (rank.stars === null || s.division === rank.division));
  }
  // The target never sits below the start; moving the start up pushes it along.
  const targetMinStop = from ? Math.max(0, toIndexOf(from)) : 0;
  function setFrom(rank: JokiRank) {
    setFromRaw(rank);
    const startAt = toIndexOf(rank);
    if (to && startAt >= 0 && toIndexOf(to) < startAt) {
      const next = toStops[rank.stars === null ? startAt : startAt + 1] ?? toStops[startAt];
      if (next) setTo(rankAt(next));
    }
  }
  const [isExpress, setIsExpress] = useState(false);
  const [routeId, setRouteId] = useState<number | null>(null);
  const selectedRoute = sellableRoutes.find((r) => r.package_id === routeId) ?? null;

  const quoteKey = mode === "calculator" && from && to ? JSON.stringify([from, to, isExpress]) : null;
  const [quoteState, setQuoteState] = useState<{ key: string; quote: JokiQuote | null; error: string | null } | null>(null);
  const quote = quoteState && quoteState.key === quoteKey ? quoteState.quote : null;
  const quoteError = quoteState && quoteState.key === quoteKey ? quoteState.error : null;

  useEffect(() => {
    if (!quoteKey || !from || !to) return;
    let cancelled = false;
    const timer = setTimeout(() => {
      quoteJoki(catalog.calculator_package_id, { from, to, is_express: isExpress })
        .then((q) => !cancelled && setQuoteState({ key: quoteKey, quote: q, error: null }))
        .catch((err: unknown) => {
          if (!cancelled) setQuoteState({ key: quoteKey, quote: null, error: err instanceof ApiError ? err.message : "Couldn't price this climb. Please try again." });
        });
    }, 250);
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [quoteKey, from, to, isExpress, catalog.calculator_package_id]);

  // What is being bought: the calculator package with its climb, or a route package.
  const purchase: { packageId: number; name: string; priceRm: number } | null =
    mode === "route"
      ? selectedRoute && selectedRoute.price_rm !== null
        ? { packageId: selectedRoute.package_id, name: selectedRoute.name, priceRm: selectedRoute.price_rm }
        : null
      : quote
        ? { packageId: catalog.calculator_package_id, name: `${quote.from.label} → ${quote.to.label}${quote.is_express ? " (Express)" : ""}`, priceRm: quote.total_rm }
        : null;

  // Step 2: the login and the customer's requests. React state only, never storage (ADR-124 decision 8).
  const [credentials, setCredentials] = useState<Record<string, string>>({});
  const [credentialConsent, setCredentialConsent] = useState(false);
  const [role, setRole] = useState("");
  const [heroRequest, setHeroRequest] = useState("");
  const [playWindow, setPlayWindow] = useState("");

  // The joki block every backend call takes; the price is never in it.
  const selection: JokiSelection = {
    ...(mode === "calculator" && from && to ? { from, to, is_express: isExpress } : {}),
    role: role || undefined,
    hero_request: heroRequest.trim() || undefined,
    play_window: playWindow.trim() || undefined,
  };

  // Step 3 and the review.
  const [channelCode, setChannelCode] = useState<string | null>(null);
  const [bankCode, setBankCode] = useState<string | null>(null);
  const selectedChannel = paymentChannels.find((c) => c.channelCode === channelCode) ?? null;
  const [customerEmail, setCustomerEmail] = useState("");
  const [customerName, setCustomerName] = useState("");
  const [customerPhone, setCustomerPhone] = useState("");
  const [reviewOpen, setReviewOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [redirecting, setRedirecting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [voucherCode, setVoucherCode] = useState<string | null>(null);
  const [checkoutAttempt, setCheckoutAttempt] = useState(0);
  const idempotencyKeyRef = useRef<string | null>(null);

  const step1Complete = purchase !== null;
  const step2Complete = loginFormReady(credentialFields, credentials, credentialConsent);
  const step3Complete = channelCode !== null;
  const readyForReview = step1Complete && step2Complete && step3Complete;

  const steps: StepInfo[] = [
    { label: "Choose Climb", state: step1Complete ? "done" : "active" },
    { label: "Account", state: step2Complete && step1Complete ? "done" : step1Complete ? "active" : "pending" },
    { label: "Payment Method", state: step3Complete && readyForReview ? "done" : step1Complete && step2Complete ? "active" : "pending" },
  ];

  // The fee-inclusive total, from the same endpoint (and pricing) checkout uses.
  const contactForVoucher = voucherCode ? [customerEmail, customerPhone] : [null, null];
  const totalKey = purchase && channelCode
    ? JSON.stringify([purchase.packageId, mode === "calculator" ? [from, to, isExpress] : null, channelCode, voucherCode, ...contactForVoucher, checkoutAttempt])
    : null;
  const [totalState, setTotalState] = useState<{ key: string; result: CheckoutTotalPreview } | null>(null);
  const preview = totalState && totalState.key === totalKey ? totalState.result : null;

  const purchasePackageId = purchase?.packageId ?? null;
  const gameId = catalog.game.id;
  useEffect(() => {
    if (!totalKey || purchasePackageId === null || !channelCode) return;
    let cancelled = false;
    const timer = setTimeout(() => {
      previewCheckoutTotal({
        game_id: gameId,
        package_id: purchasePackageId,
        channel_code: channelCode,
        voucher_code: voucherCode ?? undefined,
        customer_email: voucherCode ? customerEmail : undefined,
        customer_phone: voucherCode ? customerPhone : undefined,
        joki: mode === "calculator" && from && to ? { from, to, is_express: isExpress } : undefined,
      })
        .then((result) => !cancelled && setTotalState({ key: totalKey, result }))
        .catch(() => {
          // No breakdown shown; checkout still prices server-side (ORD-9).
        });
    }, 150);
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [totalKey, purchasePackageId, gameId, channelCode, voucherCode, customerEmail, customerPhone, mode, from, to, isExpress]);

  // React Compiler memoizes these; no manual useCallback.
  function changeVoucher(code: string | null) {
    idempotencyKeyRef.current = crypto.randomUUID();
    setVoucherCode(code);
  }

  function openReview() {
    idempotencyKeyRef.current = crypto.randomUUID();
    setVoucherCode(null);
    try {
      const saved = localStorage.getItem("pg_guest_contact");
      if (saved) {
        const parsed = JSON.parse(saved);
        if (typeof parsed.email === "string") setCustomerEmail((prev) => prev || parsed.email);
        if (typeof parsed.name === "string") setCustomerName((prev) => prev || parsed.name);
        if (typeof parsed.phone === "string") setCustomerPhone((prev) => prev || parsed.phone);
      }
    } catch {
      // per-visitor convenience only
    }
    setReviewOpen(true);
  }
  function closeReview() {
    setReviewOpen(false);
  }

  async function handleConfirmPayment() {
    if (!purchase || !channelCode) return;
    const contact = CheckoutContactSchema.safeParse({ customer_email: customerEmail, customer_name: customerName, customer_phone: customerPhone });
    if (!contact.success) {
      setSubmitError(contact.error.issues[0]?.message ?? "Check your contact details and try again.");
      return;
    }

    setSubmitting(true);
    setSubmitError(null);
    try {
      const result = await submitCheckout({
        game_id: game.id,
        package_id: purchase.packageId,
        ...contact.data,
        credentials,
        credential_consent: credentialConsent,
        joki: selection,
        channel_code: channelCode,
        fpx_bank_code: bankCode ?? undefined,
        idempotency_key: idempotencyKeyRef.current ?? crypto.randomUUID(),
        voucher_code: voucherCode ?? undefined,
        // Overwritten server-side with the real order's status page (see OrderForm).
        channel_properties: {
          success_return_url: `${window.location.origin}/track-order`,
          failure_return_url: `${window.location.origin}/track-order`,
        },
      });
      const redirectUrl = extractCheckoutRedirectUrl(result.payment_actions);
      setRedirecting(true);
      if (redirectUrl) window.location.assign(redirectUrl);
      else router.push(`/order/status/${encodeURIComponent(result.order_number)}`);
    } catch (err) {
      if (err instanceof ApiError && err.code === "checkout_closed") {
        idempotencyKeyRef.current = crypto.randomUUID();
        setVoucherCode(null);
        setCheckoutAttempt((n) => n + 1);
      }
      setSubmitError(err instanceof ApiError ? err.message : "Couldn't reach checkout. Please try again in a moment.");
    } finally {
      setSubmitting(false);
    }
  }

  const summaryPackage: GamePackage | null = purchase ? { id: purchase.packageId, name: purchase.name, priceRm: purchase.priceRm } : null;
  const accountLabel = credentialFields.find((f) => f.isIdentifier)?.label ?? "Login";
  const accountValue = loginIdentifier(credentialFields, credentials);
  const tabClass = (active: boolean) =>
    `min-h-9 rounded-md border-2 px-3 py-1 font-display text-[12px] font-bold uppercase tracking-wide transition-all ${
      active ? "border-primary bg-primary text-on-primary neo-sm" : "border-ink bg-surface-container-lowest text-on-surface hover:bg-surface-container-low"
    }`;

  return (
    <div className="grid gap-5 lg:grid-cols-[1fr_360px] lg:items-start">
      <div className="flex flex-col gap-5">
        <Stepper steps={steps} />

        <StepCard number={1} title="Choose Your Climb" locked={false}>
          {sellableRoutes.length > 0 && (
            <div className="mb-4 flex flex-wrap gap-2">
              <button type="button" className={tabClass(mode === "calculator")} aria-pressed={mode === "calculator"} onClick={() => setMode("calculator")}>
                Any rank
              </button>
              <button type="button" className={tabClass(mode === "route")} aria-pressed={mode === "route"} onClick={() => setMode("route")}>
                Packages <span className="opacity-75">({sellableRoutes.length})</span>
              </button>
            </div>
          )}

          {mode === "calculator" ? (
            <div className="flex flex-col gap-5">
              <div className="rounded-lg border-2 border-ink bg-surface-container-lowest p-3 neo sm:p-4">
                <div className="grid grid-cols-[1fr_auto_1fr] items-start gap-1 sm:gap-6">
                  {from && (
                    <JokiRankStepper id="joki-from" label="Your rank now" tiers={tiers} value={from} onChange={setFrom} allowGrading maxMythicStars={settings.max_mythic_stars} />
                  )}
                  <CaretDoubleRight size={22} weight="bold" className="mt-[72px] text-primary-on-surface sm:mt-24" aria-hidden="true" />
                  {to && (
                    <JokiRankStepper id="joki-to" label="Target rank" tiers={tiers} value={to} onChange={setTo} minStop={targetMinStop} maxMythicStars={settings.max_mythic_stars} />
                  )}
                </div>
                <div className="mt-5 border-t-2 border-ink/15 pt-4 text-center" aria-live="polite">
                  {quote ? (
                    <p className="font-mono text-3xl font-bold text-primary-on-surface" data-testid="joki-climb-price">RM{quote.total_rm.toFixed(2)}</p>
                  ) : quoteError ? null : (
                    <p className="font-mono text-3xl font-bold text-on-surface-variant/50">RM—</p>
                  )}
                  <p className="mt-1 text-xs text-on-surface-variant">Minimum {settings.min_stars} stars per order</p>
                </div>
              </div>

              <label className="flex items-start gap-3 rounded-md border-2 border-ink bg-surface-container-lowest p-3">
                <input type="checkbox" checked={isExpress} onChange={(e) => setIsExpress(e.target.checked)} className="mt-0.5 h-4 w-4 shrink-0" />
                <span>
                  <span className="font-display text-[13px] font-bold uppercase">Express</span>
                  <span className="block text-xs text-on-surface-variant">{settings.express_description || "Played first, at the Express rate."}</span>
                </span>
              </label>

              {quoteError && <p className="rounded-md border-2 border-danger bg-danger/10 px-3 py-2 text-sm text-danger">{quoteError}</p>}
              {quote && (
                <div className="rounded-md border-2 border-ink bg-surface-container p-3">
                  <ul className="flex flex-col gap-1 text-sm">
                    {quote.lines.map((line, i) => (
                      <li key={i} className="flex justify-between gap-3">
                        <span className="text-on-surface-variant">
                          {line.label}
                          {line.stars !== null && line.rate_rm !== null && ` · ${line.stars}★ × RM${line.rate_rm}`}
                        </span>
                        <span className="font-mono font-semibold">RM{line.amount_rm.toFixed(2)}</span>
                      </li>
                    ))}
                  </ul>
                  <div className="mt-2 flex justify-between border-t-2 border-ink/15 pt-2 font-display text-sm font-bold uppercase">
                    <span>Total</span>
                    <span className="font-mono text-primary-on-surface">RM{quote.total_rm.toFixed(2)}</span>
                  </div>
                  {quote.cheaper_route && (
                    <button
                      type="button"
                      onClick={() => {
                        setMode("route");
                        setRouteId(quote.cheaper_route!.package_id);
                      }}
                      className="mt-3 w-full rounded-md border-2 border-ink bg-tertiary px-3 py-2 text-left text-sm font-semibold text-on-tertiary"
                    >
                      Cheaper: the {quote.cheaper_route.name} package is RM{quote.cheaper_route.price_rm.toFixed(2)}. Switch →
                    </button>
                  )}
                </div>
              )}
              {settings.grading_price_rm > 0 && (
                <p className="text-xs text-on-surface-variant">
                  Climbing into Mythic includes Mythic Grading (RM{settings.grading_price_rm}), which guarantees Mythic {settings.grading_lands_at_stars}★.
                </p>
              )}
            </div>
          ) : (
            <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
              {sellableRoutes.map((route) => {
                const selected = route.package_id === routeId;
                return (
                  <button
                    key={route.package_id}
                    type="button"
                    aria-pressed={selected}
                    onClick={() => setRouteId(route.package_id)}
                    className={`flex min-h-11 flex-col items-start gap-1 rounded-md border-2 p-3 text-left transition-all ${
                      selected ? "border-primary bg-primary-fixed neo" : "border-ink bg-surface-container-lowest neo-hover hover:bg-surface-container-low"
                    }`}
                  >
                    <span className="font-display text-[13px] font-bold">{route.name}</span>
                    <span className="text-xs text-on-surface-variant">{route.from.label} → {route.to.label}</span>
                    <span className="font-mono text-[15px] font-bold">RM{(route.price_rm ?? 0).toFixed(2)}</span>
                  </button>
                );
              })}
            </div>
          )}

          {quotationRoutes.length > 0 && whatsappHref && (
            <p className="mt-4 text-xs text-on-surface-variant">
              Need {quotationRoutes.map((r) => r.name).join(", ")}?{" "}
              <a href={whatsappHref} target="_blank" rel="noopener noreferrer" className="font-semibold text-primary-on-surface underline">
                Ask us on WhatsApp for a quote
              </a>
              .
            </p>
          )}
        </StepCard>

        <StepCard number={2} title="Your Account & Requests" locked={!step1Complete} lockHint="Choose your climb first">
          <LoginAccountFields
            fields={credentialFields}
            values={credentials}
            setValue={(key: string, value: string) => setCredentials((prev) => ({ ...prev, [key]: value }))}
            consentText={catalog.credential_consent_text}
            consent={credentialConsent}
            setConsent={setCredentialConsent}
          />
          <div className="mt-5 grid gap-4 border-t-2 border-ink/10 pt-5 sm:grid-cols-2">
            <div>
              <label htmlFor="joki-role" className={idLabelClass}>Role <span className="font-normal text-on-surface-variant">(optional)</span></label>
              <select id="joki-role" className={`${idInputClass} w-full`} value={role} onChange={(e) => setRole(e.target.value)}>
                <option value="">Any role</option>
                {settings.roles.map((r) => (
                  <option key={r} value={r}>{r}</option>
                ))}
              </select>
            </div>
            <div>
              <label htmlFor="joki-hero" className={idLabelClass}>Hero requests <span className="font-normal text-on-surface-variant">(optional)</span></label>
              <input id="joki-hero" maxLength={255} placeholder="e.g. Ling, Fanny" className={`${idInputClass} w-full`} value={heroRequest} onChange={(e) => setHeroRequest(e.target.value)} />
            </div>
            <div className="sm:col-span-2">
              <label htmlFor="joki-window" className={idLabelClass}>When can we play? <span className="font-normal text-on-surface-variant">(optional)</span></label>
              <input id="joki-window" maxLength={255} placeholder="e.g. weekdays 9am – 6pm, so you're not logged in" className={`${idInputClass} w-full`} value={playWindow} onChange={(e) => setPlayWindow(e.target.value)} />
            </div>
          </div>
        </StepCard>

        <StepCard number={3} title="Choose Payment Method" locked={!(step1Complete && step2Complete)} lockHint="Fill in your account first">
          <PaymentChannelPicker
            channels={paymentChannels}
            selected={channelCode}
            bankCode={bankCode}
            onSelect={(code, bank) => {
              setChannelCode(code);
              setBankCode(bank ?? null);
            }}
          />
        </StepCard>
      </div>

      <div className="flex flex-col gap-5 lg:sticky lg:top-20">
        <div className="hidden lg:block">
          <OrderSummarySidebar
            game={game}
            selectedPackage={summaryPackage}
            preview={preview}
            playerId={accountValue}
            playerLabel={accountLabel}
            serverId=""
            ready={readyForReview}
            onReview={openReview}
          />
        </div>
      </div>

      <div className="fixed inset-x-0 bottom-0 z-50 border-t-2 border-ink bg-surface-container-lowest px-4 pt-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] neo-lg lg:hidden">
        <div className="mx-auto flex max-w-[560px] items-center gap-3">
          <div className="min-w-0 flex-1">
            <p className="font-display text-[10px] font-bold uppercase tracking-wide text-on-surface-variant">Total</p>
            <p className="font-mono text-lg font-bold text-primary-on-surface">RM{(preview ? preview.final_amount_sen / 100 : purchase?.priceRm ?? 0).toFixed(2)}</p>
          </div>
          <Button onClick={openReview} disabled={!readyForReview} size="sm" className="shrink-0">
            {readyForReview ? "Review & Pay" : "Complete steps"}
          </Button>
        </div>
      </div>

      {summaryPackage && selectedChannel && (
        <ReviewModal
          key={checkoutAttempt}
          open={reviewOpen}
          onClose={closeReview}
          game={game}
          pkg={summaryPackage}
          preview={preview}
          playerId={accountValue}
          playerLabel={accountLabel}
          loginGame
          serverId=""
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
          onVoucherChange={changeVoucher}
          joki={selection}
        />
      )}

      {redirecting && (
        <div className="fixed inset-0 z-[70] flex flex-col items-center justify-center gap-4 bg-surface/95 backdrop-blur-sm" role="status" aria-live="polite">
          <span className="h-10 w-10 animate-spin rounded-full border-[3px] border-ink border-t-transparent" aria-hidden="true" />
          <p className="font-display text-lg font-bold uppercase tracking-tight">Taking you to payment…</p>
          <p className="text-sm text-on-surface-variant">Please don&apos;t close this window.</p>
        </div>
      )}
    </div>
  );
}
