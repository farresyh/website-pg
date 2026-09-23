"use client";

import { useEffect, useRef, useState, type ReactNode } from "react";
import { Check, Copy, WhatsappLogo, GameController, User, CreditCard } from "@phosphor-icons/react/dist/ssr";
import { ApiError } from "@/lib/api-client";
import { getEcho } from "@/lib/echo";
import { trackOrder, TrackedOrderSchema, type TrackedOrder } from "@/lib/track-order";
import { useSiteConfig } from "@/context/SiteConfigContext";
import Button from "@/components/ui/Button";
import StatusBadge from "@/components/order/StatusBadge";
import RateOrderModal from "@/components/order/RateOrderModal";

// ADR-047 decision 1/4: Reverb push (subscribed below) is the primary
// path once it's deployed; this poll is the fallback for when the
// WebSocket never connects (misconfigured env, a blocking network) or a
// push is missed.
//
// ADR-071 PR3 — adaptive cadence, since Reverb is still deferred in
// production (ADR-071 PR4): a fresh delivery usually resolves in the
// first minute, so poll fast then, then back off. The first-minute
// cadence stays below the track-order endpoint's 20/minute throttle.
const MAX_WATCH_MS = 6 * 60_000; // ~6 minutes total, unchanged
function nextPollDelay(elapsedMs: number): number {
  if (elapsedMs < 60_000) return 4_000; // first minute — the common case
  if (elapsedMs < 180_000) return 10_000; // 1–3 minutes
  return 20_000; // 3–6 minutes
}

function retryAfterDelay(value: string | null): number {
  if (value !== null) {
    const seconds = Number(value);
    const delay = Number.isFinite(seconds) ? seconds * 1_000 : Date.parse(value) - Date.now();
    if (Number.isFinite(delay)) return Math.max(4_000, delay);
  }
  // A missing/unreadable header must not recreate a tight 429 loop.
  return 60_000;
}

type StageState = "done" | "active" | "pending" | "failed";

interface Stage {
  label: string;
  sub: string;
  state: StageState;
}

/**
 * Two independent state machines (ORD-11) collapsed into one
 * customer-facing 3-stage view for the happy path, but a payment or
 * delivery failure gets its own distinct failed stage rather than
 * being silently absorbed — the reference mockup only ever showed the
 * happy path.
 */
function deriveStages(order: TrackedOrder): Stage[] {
  if (order.payment_status === "failed") {
    return [
      { label: "Payment Received", sub: "Failed", state: "failed" },
      { label: "Processing", sub: "Waiting", state: "pending" },
      { label: "Order Complete", sub: "Waiting", state: "pending" },
    ];
  }

  const paid = order.payment_status === "paid";

  if (order.delivery_status === "failed") {
    return [
      { label: "Payment Received", sub: "Paid", state: "done" },
      { label: "Processing", sub: "Delivery failed", state: "failed" },
      { label: "Order Complete", sub: "Waiting", state: "pending" },
    ];
  }

  // ADR-032: Pending (an async supplier accepted the order but hasn't
  // confirmed the final outcome) reads identically to Processing here
  // — to a customer both mean "topup sedang diproses", the mechanism
  // resolving it (webhook/poll vs. a synchronous call) isn't their
  // concern.
  const delivering = order.delivery_status === "processing" || order.delivery_status === "pending";

  return [
    { label: "Payment Received", sub: paid ? "Paid" : "Waiting", state: paid ? "done" : "active" },
    {
      label: "Processing",
      // "Complete" not "Delivered" here — the delivery StatusBadge above
      // is the one canonical "Delivered" label; a second identical string
      // in the tracker breaks Playwright's strict getByText (E2E golden
      // path) and reads as repetition.
      sub: order.delivery_status === "delivered" ? "Complete" : delivering ? "Delivering…" : "Waiting",
      state: order.delivery_status === "delivered" ? "done" : paid && delivering ? "active" : "pending",
    },
    { label: "Order Complete", sub: order.delivery_status === "delivered" ? "Done" : "Waiting", state: order.delivery_status === "delivered" ? "done" : "pending" },
  ];
}

function isTerminal(order: TrackedOrder): boolean {
  return order.payment_status === "failed" || order.delivery_status === "delivered" || order.delivery_status === "failed";
}

export default function OrderStatusTracker({ orderNumber }: { orderNumber: string }) {
  const { whatsappHref, branding } = useSiteConfig();
  const storeName = branding.storeName;
  const [order, setOrder] = useState<TrackedOrder | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const watchStartedAt = useRef(0);
  const [rateModalDismissed, setRateModalDismissed] = useState(false);
  const [copied, setCopied] = useState(false);

  function handleCopy() {
    if (!order?.order_number) return;
    void navigator.clipboard?.writeText(order.order_number);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  }

  useEffect(() => {
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout>;
    watchStartedAt.current = Date.now();

    async function poll() {
      try {
        const result = await trackOrder(orderNumber);
        if (cancelled) return;
        setOrder(result);
        setError(null);
        setLoading(false);

        const elapsed = Date.now() - watchStartedAt.current;
        if (!isTerminal(result) && elapsed < MAX_WATCH_MS) {
          timer = setTimeout(poll, nextPollDelay(elapsed));
        }
      } catch (err) {
        if (cancelled) return;
        if (err instanceof ApiError && err.status === 429) {
          // Other tabs or visitors behind the same IP can still hit the
          // shared throttle. Keep the last good status (or loading state)
          // and retry after the server's window instead of showing a
          // permanent error that requires a page refresh.
          timer = setTimeout(poll, retryAfterDelay(err.retryAfter));
          return;
        }
        setError(err instanceof ApiError ? err.message : "Something went wrong. Try again in a moment.");
        setLoading(false);
      }
    }

    poll();
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [orderNumber]);

  // ADR-047 decisions 1/2 — public channel keyed by the order's own
  // order_number (guest checkout, ADR-011, no auth possible or needed —
  // see OrderStatusUpdated.php's own doc comment for why this is safe).
  // Skipped entirely when Reverb isn't configured for this environment
  // (E2E's throwaway backend, a preview deploy that hasn't set the
  // NEXT_PUBLIC_REVERB_* vars yet) — the poll loop above is a complete
  // fallback on its own, this is purely an enhancement on top of it.
  useEffect(() => {
    if (!process.env.NEXT_PUBLIC_REVERB_APP_KEY) return;

    const channelName = `order.${orderNumber}`;
    const channel = getEcho().channel(channelName);

    channel.listen(".order.status.updated", (payload: unknown) => {
      const parsed = TrackedOrderSchema.safeParse(payload);
      if (parsed.success) {
        setOrder(parsed.data);
        setError(null);
        setLoading(false);
      }
    });

    return () => {
      getEcho().leave(channelName);
    };
  }, [orderNumber]);

  if (loading) return <p className="text-sm text-on-surface-variant">Loading order…</p>;
  if (error) return <p className="rounded-md border-2 border-ink bg-surface-container p-4 text-sm text-on-surface-variant">{error}</p>;
  if (!order) return null;

  const stages = deriveStages(order);
  const hasFailure = order.payment_status === "failed" || order.delivery_status === "failed";
  const showRateModal = order.delivery_status === "delivered" && !order.has_review && !rateModalDismissed;

  const rm = (sen: number) => `RM${(sen / 100).toFixed(2)}`;
  const hasContact =
    order.customer_name_masked || order.customer_email_masked || order.customer_phone_masked;

  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_360px] lg:items-start">
      {showRateModal && (
        <RateOrderModal
          orderNumber={order.order_number}
          onClose={() => setRateModalDismissed(true)}
          onSubmitted={() => {
            setOrder((prev) => (prev ? { ...prev, has_review: true } : prev));
          }}
        />
      )}

      <div className="flex flex-col gap-4">
        {/* ADR-071 PR3 — the "delivered" moment: the payoff of the whole
          * masuk → pilih → bayar → dapat diamond flow. */}
        {order.delivery_status === "delivered" && (
          <div className="neo-delivered flex items-center gap-3.5 rounded-lg border-2 border-ink bg-success p-4 text-on-success neo">
            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border-2 border-on-success">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path
                  d="M5 13l4 4L19 7"
                  stroke="currentColor"
                  strokeWidth="3"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                />
              </svg>
            </span>
            <div>
              {/* Not the word "Delivered" — the delivery StatusBadge is
                * the one canonical "Delivered" label (same reason as
                * deriveStages() uses "Complete"); a second copy breaks
                * the E2E golden path's strict getByText. */}
              <p className="font-display text-base font-bold uppercase tracking-tight">You&apos;re all set</p>
              <p className="text-[13px] leading-snug">Your top-up is in your game account. Enjoy!</p>
            </div>
          </div>
        )}

        {/* Reference + status + stage tracker */}
        <div className="flex flex-col gap-5 rounded-lg border-2 border-ink bg-surface-container-lowest p-6 neo">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p className="text-[11px] font-display font-bold uppercase tracking-wide text-on-surface-variant">
                Order Reference Number
              </p>
              <div className="mt-0.5 flex items-center gap-2">
                <p className="font-mono text-base font-bold text-primary">{order.order_number}</p>
                <button
                  type="button"
                  onClick={handleCopy}
                  className="inline-flex items-center gap-1 rounded border border-ink/40 bg-surface-container px-2 py-0.5 font-display text-[11px] font-bold text-on-surface hover:bg-surface-container-high transition-colors cursor-pointer"
                  aria-label="Copy order reference number"
                >
                  {copied ? (
                    <>
                      <Check size={12} weight="bold" className="text-success" />
                      <span>Copied!</span>
                    </>
                  ) : (
                    <>
                      <Copy size={12} weight="bold" />
                      <span>Copy</span>
                    </>
                  )}
                </button>
              </div>
            </div>
            <div className="flex gap-2">
              <StatusBadge type="payment" status={order.payment_status} />
              <StatusBadge type="delivery" status={order.delivery_status} />
            </div>
          </div>

          <hr className="border-ink/25" />

          {/* Vertical on mobile (each stage on its own row), horizontal
            * timeline from lg up — a 3-4 stage nowrap row does not fit a
            * phone. */}
          <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:gap-2">
            {stages.map((stage, i) => (
              <div key={stage.label} className="flex items-center gap-2.5 lg:flex-1 lg:last:flex-none">
                <StageCircle state={stage.state} index={i + 1} />
                <div>
                  <p className="font-display text-[13px] font-bold lg:whitespace-nowrap">{stage.label}</p>
                  <p className="text-[11px] text-on-surface-variant lg:whitespace-nowrap">{stage.sub}</p>
                </div>
                {i < stages.length - 1 && <div className="hidden h-0.5 min-w-5 flex-1 bg-ink/25 lg:block" />}
              </div>
            ))}
          </div>
        </div>

        {/* Bento: Game & Package / Customer Info / Payment Details */}
        <div className="grid gap-4 md:grid-cols-2">
          <DetailCard icon={<GameController size={18} weight="fill" />} title="Game & Package">
            <DetailRow k="Game" v={order.game?.name ?? "-"} />
            <DetailRow k="Package" v={order.package_name ?? "-"} />
            <DetailRow k="Player ID" v={order.player_id} mono />
            {order.server_id && <DetailRow k="Server ID" v={order.server_id} mono last />}
          </DetailCard>

          {hasContact && (
            <DetailCard icon={<User size={18} weight="fill" />} title="Customer Info">
              {order.customer_name_masked && <DetailRow k="Name" v={order.customer_name_masked} />}
              {order.customer_email_masked && <DetailRow k="Email" v={order.customer_email_masked} />}
              {order.customer_phone_masked && <DetailRow k="Phone" v={order.customer_phone_masked} last />}
            </DetailCard>
          )}

          <div className={`rounded-lg border-2 border-ink bg-surface-container-lowest p-6 neo ${hasContact ? "md:col-span-2" : ""}`}>
            <div className="mb-4 flex items-center gap-2 text-primary">
              <CreditCard size={18} weight="fill" />
              <h2 className="font-display text-headline-sm">Payment Details</h2>
            </div>
            <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
              <div className="flex-1">
                {order.payment_method && <DetailRow k="Method" v={order.payment_method} />}
                <DetailRow k="Package Price" v={rm(order.selling_price)} mono />
                <DetailRow k="Transaction Fee" v={`+ ${rm(order.transaction_fee)}`} mono />
                {order.voucher_discount > 0 && (
                  <DetailRow k="Voucher Deduction" v={`− ${rm(order.voucher_discount)}`} mono />
                )}
              </div>
              <div className="border-2 border-ink bg-surface-container-high p-4 text-center md:min-w-[150px] md:text-right">
                <span className="block text-[11px] font-display font-bold uppercase tracking-wide text-on-surface-variant">
                  Amount Paid
                </span>
                <span className="block font-mono text-headline-md font-bold text-primary">{rm(order.final_amount)}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div className="flex flex-col gap-3.5 rounded-lg border-2 border-ink bg-primary-fixed p-6 neo lg:sticky lg:top-24">
        <h3 className="font-display text-[15px] font-bold">
          {hasFailure ? "Need Help With This Order?" : "Having an Issue with Your Order?"}
        </h3>
        <p className="text-[13px] leading-relaxed text-on-surface-variant">
          Contact our Customer Support team directly via WhatsApp for a manual check
          {hasFailure ? "" : " if your order status is delayed beyond 10 minutes"}.
        </p>
        {whatsappHref && (() => {
          const supportText = encodeURIComponent(
            `Salam support ${storeName}, saya perlukan bantuan untuk order ${order.order_number} (${order.game?.name ?? "Top Up"}).`
          );
          const contextualWhatsappHref = whatsappHref.includes("?")
            ? `${whatsappHref}&text=${supportText}`
            : `${whatsappHref}?text=${supportText}`;
          return (
            <Button href={contextualWhatsappHref} className="justify-center">
              <WhatsappLogo size={16} weight="fill" /> Contact {storeName} Support
            </Button>
          );
        })()}
        {order.game && (
          <Button href={`/order/${order.game.slug}`} variant="outline" className="justify-center">
            Buy Again
          </Button>
        )}
      </div>
    </div>
  );
}

function DetailCard({ icon, title, children }: { icon: ReactNode; title: string; children: ReactNode }) {
  return (
    <div className="rounded-lg border-2 border-ink bg-surface-container-lowest p-6 neo">
      <div className="mb-4 flex items-center gap-2 text-primary">
        {icon}
        <h2 className="font-display text-headline-sm">{title}</h2>
      </div>
      <div>{children}</div>
    </div>
  );
}

function DetailRow({ k, v, mono, last }: { k: string; v: string; mono?: boolean; last?: boolean }) {
  return (
    <div className={`flex justify-between gap-4 py-2 ${last ? "" : "border-b border-ink/15"}`}>
      <span className="text-[13px] text-on-surface-variant">{k}</span>
      <span className={`text-right text-[13px] font-bold ${mono ? "font-mono" : ""}`}>{v}</span>
    </div>
  );
}

function StageCircle({ state, index }: { state: StageState; index: number }) {
  if (state === "done") {
    return (
      <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 border-ink bg-primary text-on-primary">
        <Check size={14} weight="bold" />
      </div>
    );
  }
  if (state === "failed") {
    return (
      <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 border-ink bg-danger text-on-danger text-xs font-bold">
        !
      </div>
    );
  }
  if (state === "active") {
    return (
      <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 border-ink bg-secondary-container">
        <span className="h-2 w-2 animate-pulse rounded-full bg-ink" />
      </div>
    );
  }
  return (
    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 border-ink bg-surface-container-lowest text-[13px] text-on-surface-variant">
      {index}
    </div>
  );
}
