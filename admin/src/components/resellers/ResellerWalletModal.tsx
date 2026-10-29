"use client";

/**
 * ADR-073 decision 3(b) (PR-C, re-scoped): admin manual-credit of a
 * Reseller's prepaid wallet + its ledger history. Self-serve CHIP
 * top-up (decision 3a) has no client-facing flow yet — deferred to
 * whichever PR first gives a Reseller its own entry point, see the
 * ADR-073 build addendum.
 */

import React, { useEffect, useState } from "react";
import {
  Dialog,
  DialogPortal,
  DialogBackdrop,
  DialogPositioner,
  DialogPopup,
  DialogHeader,
  DialogHeaderActions,
  DialogClose,
  DialogTitle,
  DialogContent,
} from "@/components/ui/dialog";
import { Times as CloseIcon } from "@primeicons/react/times";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api-client";
import { notify } from "@/components/ui/toast";
import {
  getResellerWallet,
  creditResellerWallet,
  setResellerBalanceCap,
  downloadWalletTopupReceipt,
  type ResellerRow,
  type ResellerWallet,
  type WalletLedgerEntry,
} from "@/lib/resellers";

interface Props {
  isOpen: boolean;
  onClose: () => void;
  reseller: ResellerRow;
  onCredited: () => void;
}

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleString("en-MY", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
}

const typeLabel: Record<string, string> = {
  wallet_topup: "Top-up",
  wallet_debit: "Order debit",
  wallet_refund: "Refund",
  wallet_closure_refund: "Closure refund",
};

function Content({ reseller, onCredited }: Omit<Props, "isOpen" | "onClose">) {
  const [wallet, setWallet] = useState<ResellerWallet | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [amountRm, setAmountRm] = useState("");
  const [note, setNote] = useState("");
  const [receipt, setReceipt] = useState<File | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [downloading, setDownloading] = useState<number | null>(null);
  const [page, setPage] = useState(1);
  // ADR-131 decision 4: this reseller's own balance cap (null = platform default).
  const [cap, setCap] = useState({ own: reseller.balance_cap_sen, effective: reseller.effective_balance_cap_sen });
  const [capRm, setCapRm] = useState(reseller.balance_cap_sen === null ? "" : String(reseller.balance_cap_sen / 100));
  const [savingCap, setSavingCap] = useState(false);
  // Reused across resubmits of one credit (double-click, retry after a
  // timeout); rotated only after a success, since the form stays open.
  const [idempotencyKey, setIdempotencyKey] = useState(() => crypto.randomUUID());

  function refresh(p: number = page) {
    return getResellerWallet(reseller.id, p)
      .then(setWallet)
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load wallet."));
  }

  useEffect(() => {
    refresh(page);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [reseller.id, page]);

  async function handleCredit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);

    const amountSen = Math.round(parseFloat(amountRm) * 100);
    if (!Number.isFinite(amountSen) || amountSen < 1) {
      setError("Enter a valid amount.");
      return;
    }

    setSubmitting(true);
    try {
      await creditResellerWallet(reseller.id, { amount_sen: amountSen, note: note || null, receipt, idempotency_key: idempotencyKey });
      notify.success("Wallet credited", formatRm(amountSen));
      setIdempotencyKey(crypto.randomUUID());
      setAmountRm("");
      setNote("");
      setReceipt(null);
      setPage(1);
      await refresh(1);
      onCredited();
    } catch (err) {
      notify.stickyError("Credit failed", err instanceof ApiError ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  async function saveCap(balanceCapSen: number | null) {
    setSavingCap(true);
    try {
      const row = await setResellerBalanceCap(reseller.id, balanceCapSen);
      setCap({ own: row.balance_cap_sen, effective: row.effective_balance_cap_sen });
      setCapRm(row.balance_cap_sen === null ? "" : String(row.balance_cap_sen / 100));
      notify.success("Balance cap saved", row.balance_cap_sen === null ? "Using the platform default" : formatRm(row.balance_cap_sen));
      onCredited();
    } catch (err) {
      notify.stickyError("Could not save the balance cap", err instanceof ApiError ? err.message : "Something went wrong.");
    } finally {
      setSavingCap(false);
    }
  }

  function handleSaveCap() {
    const sen = Math.round(parseFloat(capRm) * 100);
    if (!Number.isFinite(sen) || sen < 1) {
      setError("Enter a valid balance cap, or use the platform default.");
      return;
    }
    setError(null);
    return saveCap(sen);
  }

  async function handleDownload(entry: WalletLedgerEntry) {
    if (!entry.reference_id) return;
    setDownloading(entry.id);
    try {
      await downloadWalletTopupReceipt(entry.reference_id, entry.receipt_name ?? `receipt-${entry.reference_id}`);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Download failed.");
    } finally {
      setDownloading(null);
    }
  }

  if (!wallet) {
    return <p className="text-sm text-gray-500 dark:text-gray-400">{error ?? "Loading…"}</p>;
  }

  return (
    <div className="space-y-6">
      <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-white/[0.02]">
        <p className="text-theme-xs text-gray-500 dark:text-gray-400">Wallet balance</p>
        <p className="text-2xl font-semibold text-gray-800 dark:text-white/90">{formatRm(wallet.balance_sen)}</p>
      </div>

      {error && (
        <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <div className="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-800">
        <p className="text-theme-xs font-medium text-gray-600 dark:text-gray-400">
          Balance cap — a top-up or manual credit that would take the balance above it is blocked. Currently{" "}
          <span className="font-semibold text-gray-800 dark:text-white/90">{formatRm(cap.effective)}</span>
          {cap.own === null ? " (platform default)." : " (set for this reseller)."}
        </p>
        <div className="flex flex-wrap items-end gap-3">
          <div className="min-w-40 flex-1">
            <Label htmlFor="wallet_cap">Cap for this reseller (RM)</Label>
            <Input id="wallet_cap" inputMode="decimal" value={capRm} onChange={(e) => setCapRm(e.target.value)} placeholder="Platform default" />
          </div>
          <Button type="button" size="small" disabled={savingCap} onClick={handleSaveCap}>
            {savingCap ? "Saving…" : "Set Cap"}
          </Button>
          <Button type="button" size="small" variant="outlined" disabled={savingCap || cap.own === null} onClick={() => saveCap(null)}>
            Use platform default
          </Button>
        </div>
      </div>

      <form onSubmit={handleCredit} className="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-800">
        <p className="text-theme-xs font-medium text-gray-600 dark:text-gray-400">
          Manual credit — for a top-up paid outside CHIP (e.g. direct bank transfer). Attach a receipt for audit.
        </p>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <Label htmlFor="wallet_amount">Amount (RM)</Label>
            <Input id="wallet_amount" value={amountRm} onChange={(e) => setAmountRm(e.target.value)} placeholder="100.00" required />
          </div>
          <div>
            <Label htmlFor="wallet_receipt">Receipt (optional)</Label>
            <input
              id="wallet_receipt"
              type="file"
              accept=".jpg,.jpeg,.png,.pdf"
              onChange={(e) => setReceipt(e.target.files?.[0] ?? null)}
              className="block w-full text-theme-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-theme-xs dark:text-gray-400 dark:file:bg-gray-800"
            />
          </div>
        </div>
        <div>
          <Label htmlFor="wallet_note">Note</Label>
          <Input id="wallet_note" value={note} onChange={(e) => setNote(e.target.value)} placeholder="Bank transfer ref #..." />
        </div>
        <div className="flex justify-end">
          <Button type="submit" size="small" disabled={submitting}>
            {submitting ? "Crediting…" : "Credit Wallet"}
          </Button>
        </div>
      </form>

      <div>
        <p className="mb-2 text-theme-xs font-medium text-gray-600 dark:text-gray-400">Ledger history</p>
        <div className="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
          <div className="max-h-72 overflow-y-auto">
            <table className="w-full text-left text-theme-sm">
              <thead className="sticky top-0 bg-gray-50 dark:bg-gray-900">
                <tr>
                  <th className="px-4 py-2 text-theme-xs font-medium text-gray-500 dark:text-gray-400">Date</th>
                  <th className="px-4 py-2 text-theme-xs font-medium text-gray-500 dark:text-gray-400">Type</th>
                  <th className="px-4 py-2 text-theme-xs font-medium text-gray-500 dark:text-gray-400">Amount</th>
                  <th className="px-4 py-2 text-theme-xs font-medium text-gray-500 dark:text-gray-400">Note / Receipt</th>
                  <th className="px-4 py-2 text-theme-xs font-medium text-gray-500 dark:text-gray-400">Reference</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                {wallet.entries.data.map((entry) => (
                  <tr key={entry.id}>
                    <td className="px-4 py-2 text-gray-500 dark:text-gray-400">{formatDate(entry.created_at)}</td>
                    <td className="px-4 py-2 text-gray-700 dark:text-gray-300">{typeLabel[entry.type] ?? entry.type}</td>
                    <td className={`px-4 py-2 font-medium ${entry.amount >= 0 ? "text-success-600" : "text-error-600"}`}>
                      {entry.amount >= 0 ? "+" : ""}
                      {formatRm(entry.amount)}
                    </td>
                    <td className="px-4 py-2 text-gray-500 dark:text-gray-400">
                      {entry.reason ?? "—"}
                      {entry.receipt_name && (
                        <Button
                          type="button"
                          size="small"
                          variant="outlined"
                          className="ml-2"
                          disabled={downloading === entry.id}
                          onClick={() => handleDownload(entry)}
                        >
                          {downloading === entry.id ? "…" : entry.receipt_name}
                        </Button>
                      )}
                    </td>
                    <td className="px-4 py-2 text-gray-500 dark:text-gray-400">{entry.order_number ?? "—"}</td>
                  </tr>
                ))}
                {wallet.entries.data.length === 0 && (
                  <tr>
                    <td colSpan={5} className="px-4 py-6 text-center text-ink-subtle">No ledger entries yet.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
        {wallet.entries.last_page > 1 && (
          <div className="mt-3 flex items-center justify-between text-theme-xs text-gray-500 dark:text-gray-400">
            <span>
              Page {wallet.entries.current_page} of {wallet.entries.last_page}
            </span>
            <div className="flex gap-2">
              <Button
                type="button"
                size="small"
                variant="outlined"
                disabled={wallet.entries.current_page <= 1}
                onClick={() => setPage((p) => Math.max(1, p - 1))}
              >
                Previous
              </Button>
              <Button
                type="button"
                size="small"
                variant="outlined"
                disabled={wallet.entries.current_page >= wallet.entries.last_page}
                onClick={() => setPage((p) => p + 1)}
              >
                Next
              </Button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

export default function ResellerWalletModal({ isOpen, onClose, reseller, onCredited }: Props) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-2xl">
            <DialogHeader>
              <DialogTitle>{reseller.business_name} — Wallet</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && <Content key={reseller.id} reseller={reseller} onCredited={onCredited} />}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
