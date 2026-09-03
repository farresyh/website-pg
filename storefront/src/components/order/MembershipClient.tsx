"use client";

import { useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { Envelope, ShieldCheck, SignOut, SpinnerGap, WarningCircle } from "@phosphor-icons/react/dist/ssr";
import { ApiError } from "@/lib/api-client";
import { sendOtp, verifyOtp, getMe, type MembershipMe } from "@/lib/membership";
import { setMembershipToken, clearMembershipToken } from "@/lib/membership-session";
import { useMembershipToken } from "@/hooks/useMembershipToken";
import Button from "@/components/ui/Button";
import StatusBadge from "@/components/order/StatusBadge";
import OtpInput from "@/components/order/OtpInput";
import MembershipSubscribe from "@/components/order/MembershipSubscribe";

const POST_PAYMENT_POLL_MS = 3_000;
const POST_PAYMENT_POLL_MAX = 10; // ~30s before we tell them to check their email

/**
 * ADR-027's 2026-08-29 addendum, decisions 24/25: one entry point for
 * both a new subscriber and an existing member checking status — same
 * verify gate either way. A stored, still-valid token (decision 23,
 * 30-day rolling window) skips straight to the dashboard, no OTP
 * needed. The "subscribe" side of decision 4's two-tier flow isn't
 * built yet (checkout wiring, ADR-027's own later phase) — a verified
 * visitor with no active membership sees an honest "coming soon" state
 * here, not a fake subscribe form.
 *
 * The visible step is deliberately *derived* from `token`/`dashboard`,
 * not stored as its own state machine — avoids the react-hooks/
 * set-state-in-effect trap (a synchronous setState in an effect body,
 * this codebase's own documented gotcha, AGENTS.md) that a naive
 * "check localStorage on mount, setStep()" effect would hit.
 * useMembershipToken() (useSyncExternalStore, same shape as admin's
 * useClientSession()) is what makes `token` SSR-safe in the first place.
 */
export default function MembershipClient() {
  const token = useMembershipToken();
  const router = useRouter();
  const searchParams = useSearchParams();
  const checkoutParam = searchParams.get("checkout"); // "success" | "failed" | null (ADR-068 decision 12)

  const [emailStep, setEmailStep] = useState<"email" | "otp">("email");
  const [email, setEmail] = useState("");
  const [code, setCode] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [sending, setSending] = useState(false);
  const [verifying, setVerifying] = useState(false);
  const [dashboard, setDashboard] = useState<MembershipMe | null>(null);
  const [confirmTimedOut, setConfirmTimedOut] = useState(false);

  // ADR-068 decision 12: after paying, CHIP sends the member back to
  // /membership?checkout=success. The webhook that activates the
  // membership is async, so poll /me until it lands (or ~30s passes, at
  // which point the receipt email is the fallback). Every setState is
  // inside a callback, never synchronous in the effect body.
  const awaitingActivation = checkoutParam === "success" && token !== null && (dashboard?.membership ?? null) === null;
  useEffect(() => {
    if (!awaitingActivation) return;

    let tries = 0;
    const id = setInterval(() => {
      tries += 1;
      getMe(token!)
        .then((me) => {
          if (me.membership !== null) {
            setDashboard(me);
            router.replace("/membership");
          } else if (tries >= POST_PAYMENT_POLL_MAX) {
            clearInterval(id);
            setConfirmTimedOut(true);
          }
        })
        .catch(() => {
          /* transient — keep polling until the try cap */
        });
    }, POST_PAYMENT_POLL_MS);

    return () => clearInterval(id);
  }, [awaitingActivation, token, router]);

  // Fetches the dashboard whenever a valid token appears (fresh verify,
  // or one already sitting in localStorage from a prior visit) — every
  // setState here happens inside the promise chain, never synchronously
  // in the effect body itself, matching TrackOrderClient's own
  // established pattern for this exact lint rule.
  useEffect(() => {
    if (token === null) return;
    getMe(token)
      .then(setDashboard)
      .catch(() => clearMembershipToken());
  }, [token]);

  async function handleSendCode(e: React.FormEvent) {
    e.preventDefault();
    if (!email.trim()) return;
    setSending(true);
    setError(null);
    try {
      await sendOtp(email.trim());
      setEmailStep("otp");
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not send a code — try again in a moment.");
    } finally {
      setSending(false);
    }
  }

  async function handleVerify(e: React.FormEvent) {
    e.preventDefault();
    if (code.length !== 6) return;
    setVerifying(true);
    setError(null);
    try {
      const newToken = await verifyOtp(email.trim(), code);
      setMembershipToken(newToken); // triggers the effect above via useMembershipToken()'s subscription
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "That code didn't work — check it and try again.");
    } finally {
      setVerifying(false);
    }
  }

  function handleSignOut() {
    clearMembershipToken();
    setDashboard(null);
    setEmailStep("email");
    setEmail("");
    setCode("");
  }

  if (token !== null && dashboard === null) {
    return <p className="mx-auto max-w-[560px] px-4 py-10 text-sm text-on-surface-variant">Loading…</p>;
  }

  if (token === null && emailStep === "email") {
    return (
      <div className="mx-auto max-w-[420px] px-4 py-10 lg:py-16">
        <h1 className="font-display mb-2 text-3xl font-bold uppercase lg:text-headline-lg tracking-tight">Membership</h1>
        <p className="mb-6 text-sm text-on-surface-variant">Enter your email — we&apos;ll send a code to verify it&apos;s you.</p>
        <form onSubmit={handleSendCode} className="flex flex-col gap-3">
          <div className="flex min-h-11 items-center gap-2 rounded-md border-2 border-ink bg-surface-container-lowest px-3.5">
            <Envelope size={16} className="shrink-0 text-on-surface-variant" />
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="you@example.com"
              className="w-full bg-transparent text-sm  text-on-surface placeholder:text-on-surface-variant focus:outline-none"
            />
          </div>
          <Button type="submit" disabled={sending || !email.trim()} className="justify-center">
            {sending ? "Sending…" : "Send Code"}
          </Button>
        </form>
        {error && <p className="mt-4 rounded-md border-2 border-ink bg-surface-container p-4 text-sm text-on-surface-variant">{error}</p>}
      </div>
    );
  }

  if (token === null && emailStep === "otp") {
    return (
      <div className="mx-auto max-w-[420px] px-4 py-10 lg:py-16">
        <h1 className="font-display mb-2 text-3xl font-bold uppercase lg:text-headline-lg tracking-tight">Enter Your Code</h1>
        <p className="mb-6 text-sm text-on-surface-variant">We sent a 6-digit code to {email}. It expires in 10 minutes.</p>
        <form onSubmit={handleVerify} className="flex flex-col items-center gap-4">
          <OtpInput value={code} onChange={setCode} disabled={verifying} />
          <Button type="submit" disabled={verifying || code.length !== 6} className="w-full justify-center">
            {verifying ? "Verifying…" : "Verify"}
          </Button>
        </form>
        {error && <p className="mt-4 rounded-md border-2 border-ink bg-surface-container p-4 text-sm text-on-surface-variant">{error}</p>}
        <button
          type="button"
          onClick={() => {
            setEmailStep("email");
            setCode("");
            setError(null);
          }}
          className="mt-4 block w-full text-center text-sm text-on-surface-variant hover:text-on-surface"
        >
          Use a different email
        </button>
      </div>
    );
  }

  // Unreachable — every `token === null` path returned above; this
  // narrows `token` to `string` for the rest of the render.
  if (token === null) return null;

  // token !== null && dashboard !== null
  const orders = dashboard?.orderHistory ?? [];

  return (
    <div className="mx-auto flex max-w-[1000px] flex-col gap-gutter px-4 py-10 lg:py-16">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <h1 className="font-display text-3xl font-bold uppercase lg:text-headline-lg tracking-tight">Membership &amp; Account</h1>
        <Button variant="outline" size="sm" onClick={handleSignOut}>
          <SignOut size={16} weight="bold" /> Sign Out
        </Button>
      </div>

      {checkoutParam === "failed" && (
        <section className="flex items-center gap-3 rounded-lg border-2 border-ink bg-warning p-4 text-sm text-on-warning neo">
          <WarningCircle size={20} weight="fill" className="shrink-0" />
          Payment wasn&apos;t completed. Nothing was charged — pick a plan below to try again.
        </section>
      )}

      {/* Membership status */}
      {dashboard?.membership && (
        <section className="flex flex-col items-start justify-between gap-6 rounded-lg border-2 border-ink bg-surface-container-lowest p-6 neo md:flex-row md:items-center md:p-8">
          <div>
            <div className="mb-2 flex items-center gap-3">
              <ShieldCheck size={26} weight="fill" className="text-success" />
              <span className="font-display text-headline-md">{dashboard.membership.tierName}</span>
              <span className="rounded-sm border border-ink bg-success px-2 py-0.5 font-display text-[10px] font-bold uppercase tracking-wide text-on-success">
                {dashboard.membership.status}
              </span>
            </div>
            <p className="text-sm text-on-surface-variant">Your current membership level.</p>
          </div>
          <div className="w-full border-t-2 border-ink pt-4 md:w-auto md:border-l-2 md:border-t-0 md:pl-6 md:pt-0 md:text-right">
            <p className="font-display text-[11px] font-bold uppercase tracking-widest text-outline">Quota Remaining</p>
            <p className="font-mono text-price font-bold">RM{dashboard.membership.quotaRemainingRm.toFixed(2)}</p>
            <p className="mt-2 font-display text-[11px] font-bold uppercase tracking-widest text-outline">Renews / Expires</p>
            <p className="text-sm font-semibold">{new Date(dashboard.membership.expiresAt).toLocaleDateString()}</p>
          </div>
        </section>
      )}

      {/* Post-payment activation (ADR-068 decision 12) */}
      {awaitingActivation && !confirmTimedOut && (
        <section className="flex items-center gap-3 rounded-lg border-2 border-ink bg-surface-container-lowest p-6 text-sm neo">
          <SpinnerGap size={20} weight="bold" className="shrink-0 animate-spin" />
          Confirming your payment… this usually takes a few seconds.
        </section>
      )}
      {awaitingActivation && confirmTimedOut && (
        <section className="flex flex-col gap-3 rounded-lg border-2 border-ink bg-surface-container p-6 text-sm neo">
          <span className="flex items-center gap-3">
            <WarningCircle size={20} weight="fill" className="shrink-0" />
            This is taking longer than usual. If you completed payment, your membership will activate shortly and a receipt is on its way to your email.
          </span>
          <Button
            variant="outline"
            size="sm"
            onClick={() => window.location.assign("/membership")}
            className="self-start"
          >
            Check again
          </Button>
        </section>
      )}

      {/* Subscribe / renew / upgrade (ADR-068 decision 13) */}
      {!awaitingActivation && <MembershipSubscribe token={token} />}

      {/* Order history */}
      <section className="flex flex-col gap-4">
        <h2 className="border-b-2 border-ink pb-2 font-display text-headline-md uppercase tracking-tight">Order History</h2>
        {orders.length ? (
          <div className="overflow-x-auto rounded-lg border-2 border-ink bg-surface-container-lowest neo">
            <table className="w-full border-collapse text-left text-sm">
              <thead>
                <tr className="border-b-2 border-ink bg-surface-container-high">
                  <th className="whitespace-nowrap p-4 font-display text-[11px] font-bold uppercase tracking-wide">Date</th>
                  <th className="p-4 font-display text-[11px] font-bold uppercase tracking-wide">Game / Package</th>
                  <th className="whitespace-nowrap p-4 font-display text-[11px] font-bold uppercase tracking-wide">Order #</th>
                  <th className="p-4 text-right font-display text-[11px] font-bold uppercase tracking-wide">Price</th>
                  <th className="p-4 text-center font-display text-[11px] font-bold uppercase tracking-wide">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-ink/15">
                {orders.map((order) => (
                  <tr key={order.orderNumber} className="hover:bg-surface-container-low">
                    <td className="whitespace-nowrap p-4 font-mono text-[13px] text-on-surface-variant">
                      {order.createdAt ? new Date(order.createdAt).toLocaleDateString() : "—"}
                    </td>
                    <td className="p-4 font-medium">
                      {order.gameName ?? "—"}
                      {order.packageName ? <span className="text-on-surface-variant"> · {order.packageName}</span> : null}
                    </td>
                    <td className="whitespace-nowrap p-4 font-mono text-[12px] text-on-surface-variant">{order.orderNumber}</td>
                    <td className="p-4 text-right font-mono font-bold">RM{order.finalAmountRm.toFixed(2)}</td>
                    <td className="p-4 text-center">
                      <StatusBadge
                        type={order.paymentStatus === "paid" ? "delivery" : "payment"}
                        status={order.paymentStatus === "paid" ? order.deliveryStatus : order.paymentStatus}
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <p className="rounded-lg border-2 border-ink bg-surface-container p-6 text-sm text-on-surface-variant">
            No orders found under this email yet.
          </p>
        )}
      </section>
    </div>
  );
}
