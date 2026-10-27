"use client";

/**
 * The Reseller wallet (ADR-073): balance, a self-serve CHIP top-up
 * (decision 3(a)) and every movement. ADR-128 makes it the portal's trust
 * benchmark: it says who processes the payment and what happens after the
 * click, and never estimates the fee — CHIP shows it before payment.
 */

import { useEffect, useState } from "react";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import { notify } from "@/components/ui/toast";
import { getWallet, listPaymentChannels, topupWallet, type PaymentChannel, type WalletResponse } from "@/lib/reseller-portal";
import { dateAndTime, formatRm, toSen } from "@/lib/format";
import { BalanceHero, CardHead, ErrorNote, Facts, PageHeader, Panel, Skeleton } from "@/components/composites";
import { Button } from "@/components/ui/button";
import { PortalIcon } from "@/components/shell/PortalIcon";
import { PaymentMark, PoweredByChip } from "@/components/shell/PaymentMark";
import { AmountField, StepsCard } from "@/components/money/MoneyForm";
import LedgerTable from "@/components/money/LedgerTable";

const MIN_TOPUP_SEN = 1000;
const QUICK = ["50", "100", "200", "500", "1000"].map((v) => ({ label: `RM ${Number(v).toLocaleString("en-MY")}`, value: v }));

export default function WalletPage() {
  const [wallet, setWallet] = useState<WalletResponse | null>(null);
  const [channels, setChannels] = useState<PaymentChannel[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [topupError, setTopupError] = useState<string | null>(null);
  const [amount, setAmount] = useState("100");
  const [channelCode, setChannelCode] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [page, setPage] = useState(1);

  useEffect(() => {
    const session = getClientSession();
    if (!session) return;
    let cancelled = false;
    getWallet(session.token, page)
      .then((result) => {
        if (cancelled) return;
        setWallet(result);
        setError(null);
      })
      .catch((err: unknown) => !cancelled && setError(err instanceof ApiError ? err.message : "Could not load your wallet."));
    return () => {
      cancelled = true;
    };
  }, [page]);

  useEffect(() => {
    listPaymentChannels()
      .then((result) => {
        setChannels(result);
        if (result.length > 0) setChannelCode(result[0].channel_code);
      })
      .catch(() => setChannels([]));
  }, []);

  async function handleTopup(event: React.FormEvent) {
    event.preventDefault();
    setTopupError(null);
    const session = getClientSession();
    if (!session || !channelCode) return;

    const amountSen = toSen(amount);
    if (amountSen === null || amountSen < MIN_TOPUP_SEN) {
      setTopupError("Enter an amount of RM 10.00 or more, with at most 2 decimals.");
      return;
    }

    setSubmitting(true);
    try {
      const attempt = await topupWallet(session.token, amountSen, channelCode);
      if (attempt.checkout_url) {
        window.location.href = attempt.checkout_url;
        return;
      }
      notify.stickyError("Top-up failed", "The payment provider did not return a checkout link. Please try again.");
    } catch (err) {
      notify.stickyError("Top-up failed", err instanceof ApiError ? err.message : "Could not start the top-up.");
    } finally {
      setSubmitting(false);
    }
  }

  // The latest top-up and order are read from the newest page of history only.
  const newest = page === 1 ? (wallet?.entries.data ?? []) : [];
  const lastTopup = newest.find((e) => e.type === "wallet_topup");
  const lastOrder = newest.find((e) => e.type === "wallet_debit");
  const amountSen = toSen(amount);
  const noMethods = channels !== null && channels.length === 0;

  return (
    <div>
      <PageHeader title="Wallet" subtitle="Your prepaid balance. Every API and bot order is paid from it, and every refund comes back to it." />
      {error && <ErrorNote message={error} />}

      {/* Phone order: balance, top-up, then how it works. From xl the top-up sits beside both. */}
      <div className="mb-4 grid items-start gap-4 xl:grid-cols-2">
        <div className="xl:col-start-1">
          {wallet ? (
            <BalanceHero icon="wallet" label="Available balance" amount={formatRm(wallet.balance)} note="Updated as soon as a payment or order settles.">
              {(lastTopup || lastOrder) && (
                <Facts
                  columns={2}
                  items={[
                    ...(lastTopup ? [{ label: "Last top-up", value: formatRm(lastTopup.amount), sub: dateAndTime(lastTopup.created_at).date }] : []),
                    ...(lastOrder ? [{ label: "Last order", value: formatRm(Math.abs(lastOrder.amount)), sub: dateAndTime(lastOrder.created_at).date }] : []),
                  ]}
                />
              )}
            </BalanceHero>
          ) : (
            <Skeleton className="h-[220px]" />
          )}
        </div>
        <Panel className="xl:col-start-2 xl:row-span-2 xl:row-start-1">
          <CardHead title="Top up wallet" description="Minimum RM 10.00. Your bank's own limit is the only maximum." divided />
          <form onSubmit={handleTopup} className="flex flex-col gap-4 p-5">
            {topupError && <ErrorNote message={topupError} />}
            <AmountField id="topup-amount" value={amount} onChange={setAmount} quick={QUICK} />

            <fieldset className="flex flex-col gap-2">
              <legend className="mb-2 text-[13px] font-semibold text-ink">Pay with</legend>
              {channels === null ? (
                <Skeleton className="h-16" />
              ) : noMethods ? (
                <p className="rounded-md border border-warning-ink/30 bg-warning-surface px-3.5 py-3 text-[13px] text-warning-ink">
                  Online top-up is paused right now. Contact support to top up by bank transfer.
                </p>
              ) : (
                <div className="grid gap-2 sm:grid-cols-2">
                  {channels.map((c) => {
                    const checked = channelCode === c.channel_code;
                    return (
                      <label
                        key={c.channel_code}
                        className={`flex min-h-14 cursor-pointer items-center gap-2.5 rounded-md border bg-surface px-3 py-2.5 ${
                          checked ? "border-cyan-600 shadow-[inset_0_0_0_1px_var(--color-cyan-600)]" : "border-border-control hover:bg-subtle"
                        }`}
                      >
                        <input type="radio" name="channel" value={c.channel_code} checked={checked} onChange={() => setChannelCode(c.channel_code)} className="h-4 w-4 accent-cyan-600" />
                        <PaymentMark channelCode={c.channel_code} category={c.category} />
                        <span className="min-w-0 text-[13px] font-semibold text-ink">{c.label}</span>
                      </label>
                    );
                  })}
                </div>
              )}
            </fieldset>

            <div className="flex flex-col gap-1.5 rounded-md bg-subtle px-3.5 py-3 text-[13px]">
              <span className="flex justify-between gap-3">
                <span className="text-ink-muted">Added to your wallet</span>
                <b className="text-[15px] text-ink">{amountSen ? formatRm(amountSen) : "—"}</b>
              </span>
              <span className="flex justify-between gap-3 text-xs text-ink-subtle">
                <span>Payment fee</span>
                <span className="text-right">Shown on CHIP&apos;s page before you pay</span>
              </span>
            </div>

            <Button type="submit" disabled={submitting || !channelCode} className="h-11 w-full text-sm">
              <PortalIcon name="shield" className="h-4 w-4" />
              {submitting ? "Opening secure payment…" : "Continue to secure payment"}
            </Button>
            <p className="flex flex-wrap items-center justify-center gap-2 text-center text-xs text-ink-subtle">
              Payments processed by <PoweredByChip />
            </p>
          </form>
        </Panel>
        <div className="xl:col-start-1">
          <StepsCard
            title="How a top-up works"
            steps={[
              { title: "Pay on CHIP's secure page", body: "You leave PekanGame to pay with your bank. PekanGame never sees your banking login." },
              { title: "Your balance updates by itself", body: "As soon as CHIP confirms the payment. No receipt to send." },
              { title: "One top-up at a time", body: "An unpaid top-up closes after 30 minutes, then you can start another." },
            ]}
          />
        </div>

      </div>

      <section id="history">
        <Panel>
          <CardHead title="Wallet history" description="Every movement in and out of your balance, newest first." divided />
          <LedgerTable entries={wallet?.entries ?? null} onPage={setPage} empty="No wallet activity yet." />
        </Panel>
      </section>
    </div>
  );
}
