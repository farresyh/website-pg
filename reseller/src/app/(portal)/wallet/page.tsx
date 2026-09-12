"use client";

/**
 * ADR-072 decision 5 / PR-G: the Reseller (wallet) portal's Wallet
 * screen — balance tile, ledger history, and the self-serve CHIP
 * top-up trigger (ADR-073 decision 3(a)). Mirrors
 * `admin/src/components/resellers/ResellerWalletModal.tsx`'s shape
 * (balance tile + manual-credit form + ledger table), adapted to this
 * app's own read-screen design system (`components/ui.tsx`) rather than
 * PrimeReact — the reseller portal never adopted PrimeReact for its
 * read screens (ADR-038's migration rule targets `admin/`'s own
 * hand-rolled TailAdmin primitives specifically).
 */

import { useEffect, useState } from "react";
import Link from "next/link";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import {
  getWallet,
  topupWallet,
  listPaymentChannels,
  type WalletResponse,
  type PaymentChannel,
} from "@/lib/reseller-portal";
import { formatRm, formatDateTime, ledgerTypeLabel } from "@/lib/format";
import { PageHeader, Panel, StatCard, ErrorNote, EmptyRow } from "@/components/ui";

const inputClass =
  "w-full rounded-lg border border-gray-300 px-3 py-2 text-theme-sm text-gray-800 outline-none focus:border-brand-400 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90";

export default function WalletPage() {
  const [wallet, setWallet] = useState<WalletResponse | null>(null);
  const [channels, setChannels] = useState<PaymentChannel[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [topupError, setTopupError] = useState<string | null>(null);
  const [amountRm, setAmountRm] = useState("50");
  const [channelCode, setChannelCode] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [page, setPage] = useState(1);

  function refresh(p: number = page) {
    const session = getClientSession();
    if (!session) return;

    return getWallet(session.token, p)
      .then((result) => {
        setWallet(result);
        setError(null);
      })
      .catch((err: unknown) =>
        setError(err instanceof ApiError ? err.message : "Could not load your wallet."),
      );
  }

  useEffect(() => {
    refresh(page);
    // eslint-disable-next-line react-hooks/exhaustive-deps
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

    const amountSen = Math.round(parseFloat(amountRm || "0") * 100);
    if (!amountSen || amountSen < 1000) {
      setTopupError("Minimum top-up is RM 10.00.");
      return;
    }

    setSubmitting(true);
    try {
      const attempt = await topupWallet(session.token, amountSen, channelCode);
      if (attempt.checkout_url) {
        window.location.href = attempt.checkout_url;
        return;
      }
      setTopupError("The payment provider did not return a checkout link. Please try again.");
    } catch (err) {
      setTopupError(err instanceof ApiError ? err.message : "Could not start the top-up.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div>
      <PageHeader title="Wallet" subtitle="Your prepaid balance, top-ups, and spend history." />

      {error && <ErrorNote message={error} />}

      <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <StatCard label="Wallet balance" value={wallet ? formatRm(wallet.balance) : "—"} />
      </div>

      <div className="mb-6 grid gap-6 lg:grid-cols-2">
        <Panel title="Top up">
          <form onSubmit={handleTopup} className="space-y-4 p-5">
            {topupError && <ErrorNote message={topupError} />}
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">
              Minimum RM 10.00, no maximum — the payment provider&apos;s own limit is the only
              ceiling. A card/bank fee may be added on top by your chosen payment method.
            </p>
            <label className="block space-y-1.5">
              <span className="text-theme-sm font-medium text-gray-700 dark:text-gray-300">
                Amount (RM)
              </span>
              <input
                type="number"
                min={10}
                step="0.01"
                value={amountRm}
                onChange={(e) => setAmountRm(e.target.value)}
                className={inputClass}
              />
            </label>
            <label className="block space-y-1.5">
              <span className="text-theme-sm font-medium text-gray-700 dark:text-gray-300">
                Payment method
              </span>
              <select
                value={channelCode}
                onChange={(e) => setChannelCode(e.target.value)}
                className={inputClass}
              >
                {(channels ?? []).map((c) => (
                  <option key={c.channel_code} value={c.channel_code}>
                    {c.label}
                  </option>
                ))}
              </select>
            </label>
            <button
              type="submit"
              disabled={submitting || !channelCode}
              className="w-full rounded-lg bg-brand-500 py-2.5 text-theme-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
            >
              {submitting ? "Starting checkout…" : "Top up now"}
            </button>
          </form>
        </Panel>

        <Panel title="How it works">
          <div className="space-y-3 p-5 text-theme-sm text-gray-600 dark:text-gray-300">
            <p>You&apos;ll be redirected to a secure payment page to complete your top-up.</p>
            <p>Only one top-up can be in progress at a time — it expires after 30 minutes.</p>
            <p>Your balance updates automatically once payment is confirmed.</p>
          </div>
        </Panel>
      </div>

      <Panel title="Ledger history">
        <div className="max-w-full overflow-x-auto">
          <table className="min-w-full text-theme-sm">
            <thead className="border-b border-gray-100 dark:border-gray-800">
              <tr className="text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                <th className="px-5 py-3">Type</th>
                <th className="px-5 py-3">Amount</th>
                <th className="px-5 py-3">Reason</th>
                <th className="px-5 py-3">Reference</th>
                <th className="px-5 py-3">Date</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
              {wallet?.entries.data.map((entry) => (
                <tr key={entry.id} className="text-gray-600 dark:text-gray-300">
                  <td className="px-5 py-4">{ledgerTypeLabel(entry.type)}</td>
                  <td
                    className={`px-5 py-4 font-medium ${
                      entry.amount < 0
                        ? "text-error-600 dark:text-error-400"
                        : "text-success-600 dark:text-success-400"
                    }`}
                  >
                    {entry.amount < 0 ? "-" : "+"}
                    {formatRm(Math.abs(entry.amount))}
                  </td>
                  <td className="px-5 py-4 text-gray-500 dark:text-gray-400">
                    {entry.reason ?? "—"}
                  </td>
                  <td className="px-5 py-4">
                    {entry.order_number ? (
                      <Link
                        href={`/orders/${entry.order_number}`}
                        className="font-medium text-brand-500 hover:underline"
                      >
                        {entry.order_number}
                      </Link>
                    ) : (
                      <span className="text-gray-400">—</span>
                    )}
                  </td>
                  <td className="px-5 py-4 text-theme-xs text-gray-400">
                    {formatDateTime(entry.created_at)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {wallet && wallet.entries.data.length === 0 && (
            <EmptyRow>No wallet activity yet.</EmptyRow>
          )}
          {!wallet && !error && <EmptyRow>Loading…</EmptyRow>}
        </div>

        {wallet && wallet.entries.last_page > 1 && (
          <div className="flex items-center justify-between border-t border-gray-100 px-5 py-3 text-theme-sm text-gray-500 dark:border-gray-800 dark:text-gray-400">
            <span>
              Page {wallet.entries.current_page} of {wallet.entries.last_page}
            </span>
            <div className="flex gap-2">
              <button
                type="button"
                disabled={wallet.entries.current_page <= 1}
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                className="rounded-lg border border-gray-200 px-3 py-1.5 text-theme-xs disabled:opacity-40 dark:border-gray-700"
              >
                Previous
              </button>
              <button
                type="button"
                disabled={wallet.entries.current_page >= wallet.entries.last_page}
                onClick={() => setPage((p) => p + 1)}
                className="rounded-lg border border-gray-200 px-3 py-1.5 text-theme-xs disabled:opacity-40 dark:border-gray-700"
              >
                Next
              </button>
            </div>
          </div>
        )}
      </Panel>
    </div>
  );
}
