"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { ArrowRight, Check } from "@phosphor-icons/react/dist/ssr";
import { ApiError } from "@/lib/api-client";
import { getSubscribeOptions, subscribe, type SubscribeOptions, type SubscribePlan } from "@/lib/membership";
import { listPaymentChannels, type PaymentChannel } from "@/lib/payment-methods";
import Button from "@/components/ui/Button";

const CTA_LABEL: Record<SubscribePlan["relation"], string> = {
  subscribe: "Subscribe",
  renew: "Renew",
  upgrade: "Upgrade",
  downgrade: "Downgrade",
};

/**
 * ADR-068 decisions 5/13 — the self-serve subscribe surface on
 * /membership. Verified-with-no-membership sees every tier as
 * "Subscribe"; an active member sees "Renew" on their tier and
 * "Upgrade" on a higher one. A "Downgrade" is shown but disabled with a
 * note (S5 — a paid mid-cycle downgrade has no clean meaning; let it
 * lapse instead).
 *
 * The higher-discount tier is the hero (ADR-027 decision 4's anchor
 * psychology): a "BEST VALUE" band, the loud cyan accent, the deeper
 * offset shadow, a lift, and the one filled CTA on the screen — so the
 * eye lands on it first even sitting on the right. On mobile it stacks
 * first. Picking a tier reveals the payment step, then POST
 * /api/membership/subscribe returns a CHIP checkout URL to redirect to;
 * the server sets the return URLs back to /membership?checkout=…
 * (handled by MembershipClient).
 */
export default function MembershipSubscribe({ token }: { token: string }) {
  const [options, setOptions] = useState<SubscribeOptions | null>(null);
  const [channels, setChannels] = useState<PaymentChannel[]>([]);
  const [loadError, setLoadError] = useState(false);

  const [selectedPlanId, setSelectedPlanId] = useState<number | null>(null);
  const [channelCode, setChannelCode] = useState<string | null>(null);
  const [starting, setStarting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // One key per tier selection — reused if the customer retries the
  // same "Continue to Payment" click, mirroring checkout's idempotency.
  const idemKey = useRef<string>("");

  useEffect(() => {
    let cancelled = false;
    Promise.all([getSubscribeOptions(token), listPaymentChannels()])
      .then(([opts, chans]) => {
        if (cancelled) return;
        setOptions(opts);
        setChannels(chans);
        if (chans.length === 1) setChannelCode(chans[0].channelCode);
      })
      .catch(() => {
        if (!cancelled) setLoadError(true);
      });
    return () => {
      cancelled = true;
    };
  }, [token]);

  const heroPlanId = useMemo(() => {
    if (!options || options.plans.length === 0) return null;
    return options.plans.reduce((a, b) => (b.discountPercent > a.discountPercent ? b : a)).id;
  }, [options]);

  const selectedPlan = useMemo(
    () => options?.plans.find((p) => p.id === selectedPlanId) ?? null,
    [options, selectedPlanId],
  );

  function pickPlan(plan: SubscribePlan) {
    idemKey.current = crypto.randomUUID();
    setSelectedPlanId(plan.id);
    setError(null);
    if (channels.length === 1) setChannelCode(channels[0].channelCode);
  }

  async function startPayment() {
    if (selectedPlanId === null || channelCode === null) return;
    setStarting(true);
    setError(null);
    try {
      const result = await subscribe(token, selectedPlanId, channelCode, idemKey.current);
      if (result.checkoutUrl) {
        window.location.assign(result.checkoutUrl);
        return;
      }
      setError("Could not start the payment — please try again.");
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Something went wrong — please try again.");
    } finally {
      setStarting(false);
    }
  }

  if (loadError) {
    return (
      <section className="rounded-lg border-2 border-ink bg-surface-container p-6 text-sm text-on-surface-variant neo">
        Couldn&apos;t load membership plans right now. Refresh to try again.
      </section>
    );
  }

  if (options === null) {
    return <p className="text-sm text-on-surface-variant">Loading plans…</p>;
  }

  return (
    <section className="flex flex-col gap-5">
      <h2 className="border-b-2 border-ink pb-2 font-display text-headline-md uppercase tracking-tight">
        {options.currentPlanId === null ? "Choose a Membership" : "Manage Membership"}
      </h2>

      <div className="grid gap-6 md:grid-cols-2 md:items-start">
        {options.plans.map((plan) => {
          const isHero = plan.id === heroPlanId;
          const isDowngrade = plan.relation === "downgrade";
          const isSelected = plan.id === selectedPlanId;

          return (
            <div
              key={plan.id}
              className={[
                "relative flex flex-col overflow-hidden rounded-lg border-2 border-ink neo",
                isHero
                  ? "order-first bg-secondary-container text-on-secondary-container md:order-none"
                  : "bg-surface-container-lowest",
                isSelected ? "outline outline-[3px] outline-ink outline-offset-4" : "",
              ].join(" ")}
            >
              {isHero && (
                <p className="border-b-2 border-ink bg-warning px-6 py-1.5 text-center font-display text-[11px] font-bold uppercase tracking-[0.15em] text-on-warning">
                  Best value
                </p>
              )}

              <div className="flex flex-1 flex-col gap-4 p-6">
                <div className="flex items-baseline justify-between gap-3">
                  <span className="font-display text-headline-sm font-bold">{plan.name}</span>
                  <span
                    className={
                      isHero
                        ? "shrink-0 rounded-sm border-2 border-ink bg-surface-container-lowest px-2.5 py-1 font-display text-[13px] font-bold uppercase tracking-wide text-ink"
                        : "shrink-0 rounded-sm border border-ink bg-success px-2 py-0.5 font-display text-[10px] font-bold uppercase tracking-wide text-on-success"
                    }
                  >
                    Save {plan.discountPercent}%
                  </span>
                </div>

                <div>
                  <p className="font-mono text-price font-bold">
                    RM{plan.feeRm.toFixed(2)}
                    <span
                      className={`font-display text-sm font-bold ${isHero ? "text-on-secondary-container/70" : "text-on-surface-variant"}`}
                    >
                      {" "}
                      /month
                    </span>
                  </p>
                  <p className={`mt-1 text-sm ${isHero ? "text-on-secondary-container/80" : "text-on-surface-variant"}`}>
                    Up to RM{plan.quotaRm.toFixed(0)} of member-priced top-ups each cycle.
                  </p>
                </div>

                <div className="mt-auto pt-1">
                  {isDowngrade ? (
                    <>
                      <Button variant="outline" size="sm" disabled onClick={() => {}} className="w-full opacity-60">
                        Downgrade
                      </Button>
                      <p className="mt-1.5 text-xs text-outline">Takes effect when your current membership ends.</p>
                    </>
                  ) : (
                    <Button
                      variant={isSelected ? "outline" : isHero ? "primary" : "outline"}
                      size="sm"
                      onClick={() => pickPlan(plan)}
                      className="w-full"
                    >
                      {isSelected ? (
                        <>
                          <Check size={16} weight="bold" /> Selected
                        </>
                      ) : (
                        CTA_LABEL[plan.relation]
                      )}
                    </Button>
                  )}
                </div>
              </div>
            </div>
          );
        })}
      </div>

      {selectedPlan && (
        <div className="flex flex-col gap-4 rounded-lg border-2 border-ink bg-surface-container-lowest p-6 neo">
          <p className="font-display text-sm font-bold">
            Subscribing to {selectedPlan.name} — RM{selectedPlan.feeRm.toFixed(2)}/month
          </p>

          <p className="font-display text-[11px] font-bold uppercase tracking-widest text-outline">Payment Method</p>

          {channels.length === 0 ? (
            <p className="text-sm text-on-surface-variant">No payment methods are available right now.</p>
          ) : (
            <div className="flex flex-col gap-2">
              {channels.map((channel) => (
                <label
                  key={channel.channelCode}
                  className={`flex min-h-11 cursor-pointer items-center gap-3 rounded-md border-2 border-ink px-3.5 ${
                    channelCode === channel.channelCode ? "bg-primary-fixed" : "bg-surface-container-lowest"
                  }`}
                >
                  <input
                    type="radio"
                    name="membershipChannel"
                    value={channel.channelCode}
                    checked={channelCode === channel.channelCode}
                    onChange={() => setChannelCode(channel.channelCode)}
                    className="accent-ink"
                  />
                  <span className="text-sm font-medium">{channel.label}</span>
                </label>
              ))}
            </div>
          )}

          <p className="text-xs text-outline">
            RM{selectedPlan.feeRm.toFixed(2)}/month, plus the payment provider&apos;s fee shown at checkout.
          </p>

          <Button
            onClick={startPayment}
            disabled={starting || channelCode === null || channels.length === 0}
            className="w-full justify-center"
          >
            {starting ? "Starting…" : "Continue to Payment"} <ArrowRight size={16} weight="bold" />
          </Button>

          {error && (
            <p className="rounded-md border-2 border-ink bg-surface-container p-4 text-sm text-on-surface-variant">{error}</p>
          )}
        </div>
      )}
    </section>
  );
}
