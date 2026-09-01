"use client";

import { useEffect, useRef, useState, type ReactNode } from "react";
import { Check, WhatsappLogo, GameController, User, CreditCard } from "@phosphor-icons/react/dist/ssr";
import { ApiError } from "@/lib/api-client";
import { getEcho } from "@/lib/echo";
import { trackOrder, TrackedOrderSchema, type TrackedOrder } from "@/lib/track-order";
import Button from "@/components/ui/Button";
import StatusBadge from "@/components/order/StatusBadge";
import RateOrderModal from "@/components/order/RateOrderModal";

// ADR-047 decision 1/4: Reverb push (subscribed below) is now the primary
// path — a status change reaches this component the moment
// OrderStatusUpdated broadcasts, not on the next poll tick. This interval
// is deliberately kept, at a much slower cadence, as the one fallback
// decision 4 requires: if the WebSocket never connects (misconfigured env,
// a network that blocks it) or a push event is somehow missed, the
// customer still sees their order resolve within one polling window,
// never stuck silently on a stale state.
const POLL_INTERVAL_MS = 20000;
const MAX_POLLS = 18; // ~6 minutes of fallback-poll safety net

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
      sub: order.delivery_status === "delivered" ? "Delivered" : delivering ? "Delivering…" : "Waiting",
      state: order.delivery_status === "delivered" ? "done" : paid && delivering ? "active" : "pending",
    },
    { label: "Order Complete", sub: order.delivery_status === "delivered" ? "Done" : "Waiting", state: order.delivery_status === "delivered" ? "done" : "pending" },
  ];
}

function isTerminal(order: TrackedOrder): boolean {
  return order.payment_status === "failed" || order.delivery_status === "delivered" || order.delivery_status === "failed";
}

export default function OrderStatusTracker({ orderNumber }: { orderNumber: string }) {
  const [order, setOrder] = useState<TrackedOrder | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const pollCount = useRef(0);
  // ADR-053 decision 4 — closing without submitting only suppresses the
  // popup for the rest of THIS page view; has_review (server-truth, not
  // this flag) is what decides whether it shows again on a later visit.
  const [rateModalDismissed, setRateModalDismissed] = useState(false);

  useEffect(() => {
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout>;

    async function poll() {
      try {
        const result = await trackOrder(orderNumber);
        if (cancelled) return;
        setOrder(result);
        setError(null);
        setLoading(false);

        if (!isTerminal(result) && pollCount.current < MAX_POLLS) {
          pollCount.current += 1;
          timer = setTimeout(poll, POLL_INTERVAL_MS);
        }
      } catch (err) {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : "Something went wrong — try again in a moment.");
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
        {/* Reference + status + stage tracker */}
        <div className="flex flex-col gap-5 rounded-lg border-2 border-ink bg-surface-container-lowest p-6 neo">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p className="text-[11px] font-display font-bold uppercase tracking-wide text-on-surface-variant">
                Order Reference Number
              </p>
              <p className="font-mono text-base font-bold text-primary">{order.order_number}</p>
            </div>
            <div className="flex gap-2">
              <StatusBadge type="payment" status={order.payment_status} />
              <StatusBadge type="delivery" status={order.delivery_status} />
            </div>
          </div>

          <hr className="border-ink/25" />

          <div className="flex items-center gap-2">
            {stages.map((stage, i) => (
              <div key={stage.label} className="flex flex-1 items-center gap-2 last:flex-none">
                <div className="flex items-center gap-2.5">
                  <StageCircle state={stage.state} index={i + 1} />
                  <div>
                    <p className="font-display text-[13px] font-bold whitespace-nowrap">{stage.label}</p>
                    <p className="text-[11px] whitespace-nowrap text-on-surface-variant">{stage.sub}</p>
                  </div>
                </div>
                {i < stages.length - 1 && <div className="h-0.5 min-w-5 flex-1 bg-ink/25" />}
              </div>
            ))}
          </div>
        </div>

        {/* Bento: Game & Package / Customer Info / Payment Details */}
        <div className="grid gap-4 md:grid-cols-2">
          <DetailCard icon={<GameController size={18} weight="fill" />} title="Game & Package">
            <DetailRow k="Game" v={order.game?.name ?? "—"} />
            <DetailRow k="Package" v={order.package_name ?? "—"} />
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
        <Button href="https://wa.me/60000000000" className="justify-center">
          <WhatsappLogo size={16} weight="fill" /> Contact PekanGame Support
        </Button>
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
