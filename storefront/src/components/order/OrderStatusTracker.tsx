"use client";

import { useEffect, useRef, useState } from "react";
import { Check, WhatsappLogo } from "@phosphor-icons/react/dist/ssr";
import { ApiError } from "@/lib/api-client";
import { getEcho } from "@/lib/echo";
import { trackOrder, TrackedOrderSchema, type TrackedOrder } from "@/lib/track-order";
import Button from "@/components/ui/Button";
import StatusBadge from "@/components/order/StatusBadge";

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

  if (loading) return <p className="text-sm text-text-muted">Loading order…</p>;
  if (error) return <p className="rounded-lg border border-border bg-surface p-4 text-sm text-text-muted">{error}</p>;
  if (!order) return null;

  const stages = deriveStages(order);
  const hasFailure = order.payment_status === "failed" || order.delivery_status === "failed";

  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_360px] lg:items-start">
      <div className="flex flex-col gap-5 rounded-2xl border border-border bg-surface p-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <p className="text-[13px] text-text-muted">Order Reference Number</p>
            <p className="font-mono text-base font-bold text-brand-light">{order.order_number}</p>
          </div>
          <div className="flex gap-2">
            <StatusBadge type="payment" status={order.payment_status} />
            <StatusBadge type="delivery" status={order.delivery_status} />
          </div>
        </div>

        <hr className="border-border" />

        <div className="flex items-center gap-2">
          {stages.map((stage, i) => (
            <div key={stage.label} className="flex flex-1 items-center gap-2 last:flex-none">
              <div className="flex items-center gap-2.5">
                <StageCircle state={stage.state} index={i + 1} />
                <div>
                  <p className="text-[13px] font-bold whitespace-nowrap">{stage.label}</p>
                  <p className="text-[11px] whitespace-nowrap text-text-muted">{stage.sub}</p>
                </div>
              </div>
              {i < stages.length - 1 && <div className="h-px min-w-5 flex-1 bg-border" />}
            </div>
          ))}
        </div>

        <hr className="border-border" />

        <div className="flex flex-wrap gap-8">
          <Meta k="Game" v={order.game?.name ?? "—"} />
          <Meta k="Package" v={order.package_name ?? "—"} />
          <Meta k="Player ID" v={order.server_id ? `${order.player_id} (${order.server_id})` : order.player_id} />
          <Meta k="Amount Paid" v={`RM${(order.final_amount / 100).toFixed(2)}`} />
        </div>
      </div>

      <div className="flex flex-col gap-3.5 rounded-2xl border border-border bg-surface p-6">
        <h3 className="text-[15px] font-bold">{hasFailure ? "Need Help With This Order?" : "Having an Issue with Your Order?"}</h3>
        <p className="text-[13px] leading-relaxed text-text-muted">
          Contact our Customer Support team directly via WhatsApp for a manual check{hasFailure ? "" : " if your order status is delayed beyond 10 minutes"}.
        </p>
        <Button href="https://wa.me/60000000000" className="justify-center">
          <WhatsappLogo size={16} weight="fill" /> Contact Soloz Support
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

function StageCircle({ state, index }: { state: StageState; index: number }) {
  if (state === "done") {
    return (
      <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-brand bg-brand text-on-brand">
        <Check size={14} weight="bold" />
      </div>
    );
  }
  if (state === "failed") {
    return (
      <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-error bg-error/15 text-error text-xs font-bold">
        !
      </div>
    );
  }
  if (state === "active") {
    return (
      <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-brand bg-surface-2">
        <span className="h-2 w-2 animate-pulse rounded-full bg-brand" />
      </div>
    );
  }
  return (
    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-border bg-bg text-[13px] text-text-muted">
      {index}
    </div>
  );
}

function Meta({ k, v }: { k: string; v: string }) {
  return (
    <div className="flex min-w-[130px] flex-1 flex-col gap-1">
      <span className="text-[12px] text-text-muted">{k}</span>
      <span className="text-sm font-bold">{v}</span>
    </div>
  );
}
