"use client";

import { useEffect, useState } from "react";
import { Envelope, ShieldCheck } from "@phosphor-icons/react/dist/ssr";
import { ApiError } from "@/lib/api-client";
import { sendOtp, verifyOtp, getMe, type MembershipMe } from "@/lib/membership";
import { setMembershipToken, clearMembershipToken } from "@/lib/membership-session";
import { useMembershipToken } from "@/hooks/useMembershipToken";
import Button from "@/components/ui/Button";
import StatusBadge from "@/components/order/StatusBadge";
import OtpInput from "@/components/order/OtpInput";

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
  const [emailStep, setEmailStep] = useState<"email" | "otp">("email");
  const [email, setEmail] = useState("");
  const [code, setCode] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [sending, setSending] = useState(false);
  const [verifying, setVerifying] = useState(false);
  const [dashboard, setDashboard] = useState<MembershipMe | null>(null);

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
        <h1 className="font-display mb-2 text-headline-lg font-bold uppercase tracking-tight">Membership</h1>
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
        <h1 className="font-display mb-2 text-headline-lg font-bold uppercase tracking-tight">Enter Your Code</h1>
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

  // token !== null && dashboard !== null
  return (
    <div className="mx-auto max-w-[640px] px-4 py-10 lg:py-16">
      <h1 className="font-display mb-2 text-headline-lg font-bold uppercase tracking-tight">Membership</h1>

      {dashboard?.membership ? (
        <div className="mb-8 rounded-md border-2 border-ink bg-surface-container p-5">
          <div className="mb-3 flex items-center gap-2">
            <ShieldCheck size={20} className="text-secondary" weight="fill" />
            <span className="text-lg font-bold">{dashboard.membership.tierName}</span>
            <span className="rounded-full border-2 border-ink bg-secondary-container px-2.5 py-1 text-[12px] font-bold text-primary">
              {dashboard.membership.status}
            </span>
          </div>
          <div className="grid grid-cols-2 gap-3 text-sm">
            <div>
              <p className="text-on-surface-variant">Quota remaining this cycle</p>
              <p className="font-bold">RM{dashboard.membership.quotaRemainingRm.toFixed(2)}</p>
            </div>
            <div>
              <p className="text-on-surface-variant">Renews / expires</p>
              <p className="font-bold">{new Date(dashboard.membership.expiresAt).toLocaleDateString()}</p>
            </div>
          </div>
        </div>
      ) : (
        <div className="mb-8 rounded-md border-2 border-ink bg-surface-container p-5 text-sm text-on-surface-variant">
          You&apos;re verified — no active membership yet. Subscription plans are coming soon.
        </div>
      )}

      <h2 className="mb-3 text-lg font-bold">Order History</h2>
      {dashboard?.orderHistory.length ? (
        <div className="flex flex-col gap-2.5">
          {dashboard.orderHistory.map((order) => (
            <div key={order.orderNumber} className="flex items-center justify-between rounded-md border-2 border-ink bg-surface-container-lowest p-3.5">
              <div>
                <p className="text-sm font-semibold">{order.gameName ?? "—"}</p>
                <p className="text-xs text-on-surface-variant">{order.packageName ?? "—"}</p>
              </div>
              <div className="flex items-center gap-2">
                <StatusBadge type="delivery" status={order.deliveryStatus} />
                <span className="text-sm font-bold">RM{order.finalAmountRm.toFixed(2)}</span>
              </div>
            </div>
          ))}
        </div>
      ) : (
        <p className="text-sm text-on-surface-variant">No orders found under this email yet.</p>
      )}

      <button type="button" onClick={handleSignOut} className="mt-8 text-sm text-on-surface-variant hover:text-on-surface">
        Sign out of this session
      </button>
    </div>
  );
}
