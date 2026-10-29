"use client";

/**
 * The Reseller Purchase Balance (ADR-073, named by ADR-131): the balance, a
 * self-serve CHIP "Add Balance" (decision 3(a)) and every movement. ADR-128
 * makes it the portal's trust benchmark: it says who processes the payment
 * and what happens after the click, and never estimates the fee — CHIP shows
 * it before payment. The limits come from the server (ADR-131 decision 7);
 * the checks here are only for a clear message before the click.
 */

import { useEffect, useState } from "react";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import { notify } from "@/components/ui/toast";
import { downloadReceipt, getWallet, listPaymentChannels, topupWallet, type PaymentChannel, type WalletResponse } from "@/lib/reseller-portal";
import { dateAndTime, formatRm, toSen } from "@/lib/format";
import { BalanceHero, CardHead, ErrorNote, Facts, PageHeader, Panel, Skeleton } from "@/components/composites";
import { Button } from "@/components/ui/button";
import { PortalIcon } from "@/components/shell/PortalIcon";
import { PaymentMark, PoweredByChip } from "@/components/shell/PaymentMark";
import { AmountField, StepsCard } from "@/components/money/MoneyForm";
import LedgerTable from "@/components/money/LedgerTable";
import FpxBankGrid, { HAS_FPX_BANKS } from "@/components/money/FpxBankGrid";

const FPX_CHANNEL = "fpx";
const QUICK = ["50", "100", "200", "500", "1000"].map((v) => ({ label: `RM ${Number(v).toLocaleString("en-MY")}`, value: v }));

export default function BalancePage() {
  const [wallet, setWallet] = useState<WalletResponse | null>(null);
  const [channels, setChannels] = useState<PaymentChannel[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [topupError, setTopupError] = useState<string | null>(null);
  const [amount, setAmount] = useState("100");
  const [channelCode, setChannelCode] = useState("");
  // ADR-130: the FPX bank picked on our page; null for "Other bank" or another channel.
  const [bankCode, setBankCode] = useState<string | null>(null);
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
      .catch((err: unknown) => !cancelled && setError(err instanceof ApiError ? err.message : "Could not load your Purchase Balance."));
    return () => {
      cancelled = true;
    };
  }, [page]);

  useEffect(() => {
    listPaymentChannels()
      .then((result) => {
        setChannels(result);
        // Nothing is pre-selected on the bank grid: the reseller picks a bank (or "Other bank").
        setChannelCode(result.find((c) => !(c.channel_code === FPX_CHANNEL && HAS_FPX_BANKS))?.channel_code ?? "");
      })
      .catch(() => setChannels([]));
  }, []);

  async function handleReceipt(receiptNumber: string) {
    const session = getClientSession();
    if (!session) return;
    try {
      await downloadReceipt(session.token, receiptNumber);
    } catch (err) {
      notify.stickyError("Could not download the receipt", err instanceof ApiError ? err.message : "Please try again.");
    }
  }

  async function handleTopup(event: React.FormEvent) {
    event.preventDefault();
    setTopupError(null);
    const session = getClientSession();
    if (!session || !channelCode) return;

    const amountSen = toSen(amount);
    if (amountSen === null) {
      setTopupError("Enter an amount in ringgit with at most 2 decimals.");
      return;
    }
    if (amountMessage) {
      setTopupError(amountMessage);
      return;
    }

    setSubmitting(true);
    try {
      const attempt = await topupWallet(session.token, amountSen, channelCode, bankCode);
      if (attempt.checkout_url) {
        window.location.href = attempt.checkout_url;
        return;
      }
      notify.stickyError("Could not add balance", "The payment provider did not return a checkout link. Please try again.");
    } catch (err) {
      notify.stickyError("Could not add balance", err instanceof ApiError ? err.message : "Could not start the payment.");
    } finally {
      setSubmitting(false);
    }
  }

  // The latest top-up and order are read from the newest page of history only.
  const newest = page === 1 ? (wallet?.entries.data ?? []) : [];
  const lastTopup = newest.find((e) => e.type === "wallet_topup");
  const lastOrder = newest.find((e) => e.type === "wallet_debit");
  const amountSen = toSen(amount);
  const limits = wallet?.limits ?? null;
  // ponytail: UX only, the server re-checks under the account lock; an amount that is fine here can still be refused there.
  const amountMessage =
    limits === null || amountSen === null
      ? null
      : amountSen < limits.min_topup_sen
        ? `The minimum is ${formatRm(limits.min_topup_sen)}.`
        : amountSen > limits.max_topup_sen
          ? `The maximum per payment is ${formatRm(limits.max_topup_sen)}.`
          : amountSen > limits.headroom_sen
            ? limits.headroom_sen === 0
              ? `Your Purchase Balance is at its limit of ${formatRm(limits.balance_cap_sen)}.`
              : `You can add up to ${formatRm(limits.headroom_sen)} now (balance limit ${formatRm(limits.balance_cap_sen)}).`
            : null;
  const noMethods = channels !== null && channels.length === 0;
  const bankGrid = HAS_FPX_BANKS && (channels ?? []).some((c) => c.channel_code === FPX_CHANNEL);

  return (
    <div>
      <PageHeader title="Purchase Balance" subtitle="Your prepaid balance for PekanGame orders. Every API and bot order is paid from it, and a refund for a failed order comes back to it." />
      {error && <ErrorNote message={error} />}

      {/* Phone order: balance, top-up, then how it works. From xl the top-up sits beside both. */}
      <div className="mb-4 grid items-start gap-4 xl:grid-cols-2">
        <div className="xl:col-start-1">
          {wallet ? (
            <BalanceHero icon="wallet" label="Purchase Balance" amount={formatRm(wallet.balance)} note="Updated as soon as a payment or order settles.">
              {(lastTopup || lastOrder) && (
                <Facts
                  columns={2}
                  items={[
                    ...(lastTopup ? [{ label: "Last added", value: formatRm(lastTopup.amount), sub: dateAndTime(lastTopup.created_at).date }] : []),
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
          <CardHead
            title="Add Balance"
            description={
              limits
                ? `Minimum ${formatRm(limits.min_topup_sen)} · Maximum ${formatRm(limits.max_topup_sen)} per payment. You can hold up to ${formatRm(limits.balance_cap_sen)}; you can add up to ${formatRm(limits.headroom_sen)} now.`
                : "Loading your limits…"
            }
            divided
          />
          <form onSubmit={handleTopup} className="flex flex-col gap-4 p-5">
            {topupError && <ErrorNote message={topupError} />}
            {!topupError && amountMessage && <p role="alert" className="text-[13px] font-medium text-warning-ink">{amountMessage}</p>}
            <AmountField id="topup-amount" value={amount} onChange={setAmount} quick={QUICK} />

            <fieldset className="flex flex-col gap-2">
              <legend className="mb-2 text-[13px] font-semibold text-ink">Pay with</legend>
              {channels === null ? (
                <Skeleton className="h-16" />
              ) : noMethods ? (
                <p className="rounded-md border border-warning-ink/30 bg-warning-surface px-3.5 py-3 text-[13px] text-warning-ink">
                  Adding balance online is paused right now. Contact support to add balance by bank transfer.
                </p>
              ) : (
                <>
                  {bankGrid && (
                    <div className="mb-1">
                      <p className="mb-2 text-xs font-semibold text-ink-muted">Online Banking (FPX)</p>
                      <FpxBankGrid
                        selected={channelCode === FPX_CHANNEL}
                        bankCode={bankCode}
                        onSelect={(code) => {
                          setChannelCode(FPX_CHANNEL);
                          setBankCode(code);
                        }}
                      />
                    </div>
                  )}
                  <div className="grid gap-2 sm:grid-cols-2">
                    {channels
                      .filter((c) => !(bankGrid && c.channel_code === FPX_CHANNEL))
                      .map((c) => {
                        const checked = channelCode === c.channel_code;
                        return (
                          <label
                            key={c.channel_code}
                            className={`flex min-h-14 cursor-pointer items-center gap-2.5 rounded-md border bg-surface px-3 py-2.5 ${
                              checked ? "border-cyan-600 shadow-[inset_0_0_0_1px_var(--color-cyan-600)]" : "border-border-control hover:bg-subtle"
                            }`}
                          >
                            <input
                              type="radio"
                              name="channel"
                              value={c.channel_code}
                              checked={checked}
                              onChange={() => {
                                setChannelCode(c.channel_code);
                                setBankCode(null);
                              }}
                              className="h-4 w-4 accent-cyan-600"
                            />
                            <PaymentMark channelCode={c.channel_code} category={c.category} />
                            <span className="min-w-0 text-[13px] font-semibold text-ink">{c.label}</span>
                          </label>
                        );
                      })}
                  </div>
                </>
              )}
            </fieldset>

            <div className="flex flex-col gap-1.5 rounded-md bg-subtle px-3.5 py-3 text-[13px]">
              <span className="flex justify-between gap-3">
                <span className="text-ink-muted">Added to your Purchase Balance</span>
                <b className="text-[15px] text-ink">{amountSen ? formatRm(amountSen) : "—"}</b>
              </span>
              <span className="flex justify-between gap-3 text-xs text-ink-subtle">
                <span>Payment fee</span>
                <span className="text-right">Shown on the payment page before you pay</span>
              </span>
            </div>

            <Button type="submit" disabled={submitting || !channelCode || !limits || amountMessage !== null} className="h-11 w-full text-sm">
              <PortalIcon name="shield" className="h-4 w-4" />
              {submitting ? "Opening secure payment…" : "Continue to secure payment"}
            </Button>
            <p className="flex flex-wrap items-center justify-center gap-2 text-center text-xs text-ink-subtle">
              Payments processed by <PoweredByChip />
            </p>
          </form>
        </Panel>
        <div className="flex flex-col gap-4 xl:col-start-1">
          <StepsCard
            title="How adding balance works"
            steps={[
              { title: "Pay on a secure payment page", body: "You leave PekanGame to pay with your bank. PekanGame never sees your banking login." },
              { title: "Your balance updates by itself", body: "As soon as CHIP confirms the payment. A Payment Receipt then appears in your balance history." },
              { title: "One payment at a time", body: "An unpaid payment closes after 30 minutes, then you can start another." },
            ]}
          />
          <StepsCard
            title="Purchase Balance terms"
            steps={[
              { title: "Only for PekanGame", body: "The balance can only be used to buy on PekanGame. It cannot be withdrawn, cashed out or transferred to anyone." },
              {
                title: "Limits",
                body: limits
                  ? `Up to ${formatRm(limits.max_topup_sen)} per payment, and a balance of up to ${formatRm(limits.balance_cap_sen)}. Payments above these are not accepted.`
                  : "Limits apply to each payment and to your total balance.",
              },
              { title: "Failed orders", body: "If an order cannot be delivered, the amount is returned to your Purchase Balance." },
              { title: "Closing your account", body: "If your account is closed, any unused balance is refunded to the original payer." },
            ]}
          />
        </div>
      </div>

      <section id="history">
        <Panel>
          <CardHead title="Balance history" description="Every movement in and out of your balance, newest first." divided />
          <LedgerTable entries={wallet?.entries ?? null} onPage={setPage} empty="No Purchase Balance activity yet." onReceipt={handleReceipt} />
        </Panel>
      </section>
    </div>
  );
}
