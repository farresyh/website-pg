"use client";

/**
 * ADR-083 decision 2 (PR-1): "Record Supplier Transfer" + the funding
 * ledger's own balance/history for one supplier. Mirrors
 * `ResellerWalletModal`'s shape (manual-credit form + ledger table), but
 * this ledger is foreign-currency, never MYR sen — `effective_rate` is
 * shown so the founder can see the FX rate a transfer actually landed at
 * against `Supplier.balance`'s API-polled figure, without either being
 * auto-reconciled here (that's the drift check, ADR-083 decision 6).
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
import {
  getSupplierTransfers,
  recordSupplierTransfer,
  downloadSupplierTransferReceipt,
  adjustSupplierTransfer,
  voidSupplierTransfer,
  type SupplierFundingLedger,
  type SupplierTransfer,
} from "@/lib/supplier-transfers";
import type { Supplier } from "@/lib/suppliers";
import { Tag } from "@/components/ui/tag";

interface Props {
  isOpen: boolean;
  onClose: () => void;
  token: string;
  supplier: Supplier;
}

function formatForeign(amount: string, currency: string): string {
  const n = Number(amount);
  const decimals = currency === "IDR" ? 0 : 2;

  return `${currency} ${n.toLocaleString("en-MY", { minimumFractionDigits: decimals, maximumFractionDigits: decimals })}`;
}

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleString("en-MY", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
}

/** 2026-09-15 addendum: amount_foreign_received stays gross (receipt-verifiable) — this is the same net figure the ledger actually credited. */
function netForeign(transfer: SupplierTransfer): number {
  return Number(transfer.amount_foreign_received) - Number(transfer.supplier_fee ?? 0);
}

const sourceChannelLabel: Record<string, string> = {
  wise: "Wise",
  airwallex: "Airwallex",
  bank: "Bank transfer",
};

function Content({ token, supplier }: Omit<Props, "isOpen" | "onClose">) {
  const [ledger, setLedger] = useState<SupplierFundingLedger | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [sourceChannel, setSourceChannel] = useState<"wise" | "airwallex" | "bank">("wise");
  const [amountMyr, setAmountMyr] = useState("");
  const [feeMyr, setFeeMyr] = useState("");
  const [amountForeign, setAmountForeign] = useState("");
  const [supplierFee, setSupplierFee] = useState("");
  const [referenceNo, setReferenceNo] = useState("");
  const [receipt, setReceipt] = useState<File | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [downloading, setDownloading] = useState<number | null>(null);

  // ADR-083 2026-09-15 addendum — Adjust / Void, expanded inline under the
  // row being corrected rather than a nested dialog.
  const [correctingId, setCorrectingId] = useState<number | null>(null);
  const [correctionMode, setCorrectionMode] = useState<"adjust" | "void">("adjust");
  const [correctionAmount, setCorrectionAmount] = useState("");
  const [correctionReason, setCorrectionReason] = useState("");
  const [correcting, setCorrecting] = useState(false);
  const [correctionError, setCorrectionError] = useState<string | null>(null);

  function refresh() {
    return getSupplierTransfers(token, supplier.id)
      .then(setLedger)
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load the funding ledger."));
  }

  useEffect(() => {
    refresh();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [supplier.id]);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);

    const amountSen = Math.round(parseFloat(amountMyr) * 100);
    const feeSen = feeMyr ? Math.round(parseFloat(feeMyr) * 100) : 0;
    if (!Number.isFinite(amountSen) || amountSen < 1) {
      setError("Enter a valid amount sent (RM).");
      return;
    }
    if (!amountForeign || Number(amountForeign) <= 0) {
      setError(`Enter a valid amount received (${supplier.currency}).`);
      return;
    }

    setSubmitting(true);
    try {
      await recordSupplierTransfer(token, supplier.id, {
        source_channel: sourceChannel,
        amount_myr_sent: amountSen,
        fee_myr: feeSen,
        currency: supplier.currency,
        amount_foreign_received: amountForeign,
        supplier_fee: supplierFee || null,
        reference_no: referenceNo || null,
        receipt,
      });
      setAmountMyr("");
      setFeeMyr("");
      setAmountForeign("");
      setSupplierFee("");
      setReferenceNo("");
      setReceipt(null);
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  function openCorrection(transfer: SupplierTransfer) {
    setCorrectingId((current) => (current === transfer.id ? null : transfer.id));
    setCorrectionMode("adjust");
    setCorrectionAmount("");
    setCorrectionReason("");
    setCorrectionError(null);
  }

  async function handleCorrectionSubmit(e: React.FormEvent, transfer: SupplierTransfer) {
    e.preventDefault();
    setCorrectionError(null);

    if (!correctionReason.trim()) {
      setCorrectionError("Enter a reason — required for every correction.");
      return;
    }
    if (correctionMode === "adjust" && (!correctionAmount || Number(correctionAmount) === 0)) {
      setCorrectionError("Enter a non-zero adjustment amount.");
      return;
    }

    setCorrecting(true);
    try {
      if (correctionMode === "void") {
        await voidSupplierTransfer(token, transfer.id, correctionReason.trim());
      } else {
        await adjustSupplierTransfer(token, transfer.id, correctionAmount, correctionReason.trim());
      }
      setCorrectingId(null);
      await refresh();
    } catch (err) {
      setCorrectionError(err instanceof ApiError ? err.message : "Something went wrong.");
    } finally {
      setCorrecting(false);
    }
  }

  async function handleDownload(transfer: SupplierTransfer) {
    setDownloading(transfer.id);
    try {
      await downloadSupplierTransferReceipt(token, transfer.id, `supplier-transfer-${transfer.id}-receipt`);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Download failed.");
    } finally {
      setDownloading(null);
    }
  }

  if (!ledger) {
    return <p className="text-sm text-gray-500 dark:text-gray-400">{error ?? "Loading…"}</p>;
  }

  return (
    <div className="space-y-6">
      <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-white/[0.02]">
        <p className="text-theme-xs text-gray-500 dark:text-gray-400">Funding ledger balance ({ledger.currency})</p>
        <p className="text-2xl font-semibold text-gray-800 dark:text-white/90">{formatForeign(ledger.ledger_balance, ledger.currency)}</p>
        <p className="mt-1 text-theme-xs text-gray-400 dark:text-gray-500">
          API-polled balance: {supplier.balance ?? "—"} {supplier.currency} — for reference only, not auto-reconciled here.
        </p>
      </div>

      {error && (
        <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <form onSubmit={handleSubmit} className="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-800">
        <p className="text-theme-xs font-medium text-gray-600 dark:text-gray-400">
          Record a capital transfer into this supplier&apos;s account — the actual amounts off the transfer receipt, not an estimate.
        </p>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <Label htmlFor="transfer_channel">Sent via</Label>
            <select
              id="transfer_channel"
              value={sourceChannel}
              onChange={(e) => setSourceChannel(e.target.value as "wise" | "airwallex" | "bank")}
              className="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-theme-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:text-white/90"
            >
              <option value="wise">Wise</option>
              <option value="airwallex">Airwallex</option>
              <option value="bank">Bank transfer</option>
            </select>
          </div>
          <div>
            <Label htmlFor="transfer_reference">Reference no. (optional)</Label>
            <Input id="transfer_reference" value={referenceNo} onChange={(e) => setReferenceNo(e.target.value)} placeholder="WISE-REF-123" />
          </div>
          <div>
            <Label htmlFor="transfer_amount_myr">Amount sent (RM)</Label>
            <Input id="transfer_amount_myr" value={amountMyr} onChange={(e) => setAmountMyr(e.target.value)} placeholder="1000.00" required />
          </div>
          <div>
            <Label htmlFor="transfer_fee_myr">Transfer fee (RM, optional)</Label>
            <Input id="transfer_fee_myr" value={feeMyr} onChange={(e) => setFeeMyr(e.target.value)} placeholder="2.50" />
          </div>
          <div>
            <Label htmlFor="transfer_amount_foreign">Amount received ({supplier.currency})</Label>
            <Input
              id="transfer_amount_foreign"
              value={amountForeign}
              onChange={(e) => setAmountForeign(e.target.value)}
              placeholder={supplier.currency === "IDR" ? "3700000" : "1000.00"}
              required
            />
            <p className="mt-1 text-theme-xs text-gray-400">Gross — the literal &quot;total to supplier&quot; figure on the receipt, before the supplier&apos;s own fee below.</p>
          </div>
          <div>
            <Label htmlFor="transfer_supplier_fee">Supplier&apos;s own fee ({supplier.currency}, optional)</Label>
            <Input
              id="transfer_supplier_fee"
              value={supplierFee}
              onChange={(e) => setSupplierFee(e.target.value)}
              placeholder={supplier.currency === "IDR" ? "15000" : "0.00"}
            />
            <p className="mt-1 text-theme-xs text-gray-400">e.g. Digiflazz&apos;s own deposit-side cut — the ledger credits net (received − this fee), not the gross figure above.</p>
          </div>
          <div>
            <Label htmlFor="transfer_receipt">Receipt (optional)</Label>
            <input
              id="transfer_receipt"
              type="file"
              accept=".jpg,.jpeg,.png,.pdf"
              onChange={(e) => setReceipt(e.target.files?.[0] ?? null)}
              className="block w-full text-theme-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-theme-xs dark:text-gray-400 dark:file:bg-gray-800"
            />
          </div>
        </div>
        <div className="flex justify-end">
          <Button type="submit" size="small" disabled={submitting}>
            {submitting ? "Recording…" : "Record Transfer"}
          </Button>
        </div>
      </form>

      <div>
        <p className="mb-2 text-theme-xs font-medium text-gray-600 dark:text-gray-400">Transfer history</p>
        <div className="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
          <div className="max-h-72 overflow-y-auto">
            <table className="w-full text-left text-theme-sm">
              <thead className="sticky top-0 bg-gray-50 dark:bg-gray-900">
                <tr>
                  <th className="px-4 py-2 text-theme-xs font-medium text-gray-500 dark:text-gray-400">Date</th>
                  <th className="px-4 py-2 text-theme-xs font-medium text-gray-500 dark:text-gray-400">Via</th>
                  <th className="px-4 py-2 text-theme-xs font-medium text-gray-500 dark:text-gray-400">Sent (RM)</th>
                  <th className="px-4 py-2 text-theme-xs font-medium text-gray-500 dark:text-gray-400">Received (net)</th>
                  <th className="px-4 py-2 text-theme-xs font-medium text-gray-500 dark:text-gray-400">Rate</th>
                  <th className="px-4 py-2 text-theme-xs font-medium text-gray-500 dark:text-gray-400">Reference / Receipt</th>
                  <th className="px-4 py-2 text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                {ledger.transfers.data.map((transfer) => {
                  const isVoided = transfer.voided_at !== null;
                  const hasFee = transfer.supplier_fee !== null && Number(transfer.supplier_fee) > 0;

                  return (
                    <React.Fragment key={transfer.id}>
                      <tr className={isVoided ? "opacity-60" : undefined}>
                        <td className="px-4 py-2 text-gray-500 dark:text-gray-400">{formatDate(transfer.created_at)}</td>
                        <td className="px-4 py-2 text-gray-700 dark:text-gray-300">{sourceChannelLabel[transfer.source_channel] ?? transfer.source_channel}</td>
                        <td className={`px-4 py-2 text-gray-700 dark:text-gray-300 ${isVoided ? "line-through" : ""}`}>{formatRm(transfer.amount_myr_sent)}</td>
                        <td className="px-4 py-2">
                          <span className={`font-medium ${isVoided ? "text-gray-400 line-through" : "text-success-600"}`}>
                            {formatForeign(String(netForeign(transfer)), transfer.currency)}
                          </span>
                          {hasFee && !isVoided && (
                            <div className="text-theme-xs text-gray-400">
                              gross {formatForeign(transfer.amount_foreign_received, transfer.currency)} − fee {formatForeign(transfer.supplier_fee ?? "0", transfer.currency)}
                            </div>
                          )}
                          {isVoided && <Tag severity="danger">Voided</Tag>}
                        </td>
                        <td className="px-4 py-2 text-gray-500 dark:text-gray-400">{transfer.effective_rate ?? "—"}</td>
                        <td className="px-4 py-2 text-gray-500 dark:text-gray-400">
                          {transfer.reference_no ?? "—"}
                          {transfer.receipt_path && (
                            <Button
                              type="button"
                              size="small"
                              variant="outlined"
                              className="ml-2"
                              disabled={downloading === transfer.id}
                              onClick={() => handleDownload(transfer)}
                            >
                              {downloading === transfer.id ? "…" : "Receipt"}
                            </Button>
                          )}
                        </td>
                        <td className="px-4 py-2">
                          {!isVoided && (
                            <Button type="button" size="small" variant="outlined" onClick={() => openCorrection(transfer)}>
                              {correctingId === transfer.id ? "Cancel" : "Correct…"}
                            </Button>
                          )}
                        </td>
                      </tr>

                      {isVoided && transfer.void_reason && (
                        <tr>
                          <td colSpan={7} className="px-4 pb-2 text-theme-xs text-error-600 dark:text-error-400">
                            Voided: {transfer.void_reason}
                          </td>
                        </tr>
                      )}

                      {transfer.adjustments.map((adjustment) => (
                        <tr key={`adj-${adjustment.id}`} className="bg-gray-50/50 dark:bg-white/[0.02]">
                          <td colSpan={3} className="px-4 py-1.5 pl-8 text-theme-xs text-gray-400">
                            ↳ {formatDate(adjustment.created_at)}
                          </td>
                          <td colSpan={4} className="px-4 py-1.5 text-theme-xs">
                            <span className={Number(adjustment.amount) < 0 ? "text-error-600 dark:text-error-400" : "text-success-600 dark:text-success-400"}>
                              {Number(adjustment.amount) > 0 ? "+" : ""}
                              {formatForeign(adjustment.amount, adjustment.currency)}
                            </span>
                            <span className="ml-2 text-gray-500 dark:text-gray-400">{adjustment.reason}</span>
                          </td>
                        </tr>
                      ))}

                      {correctingId === transfer.id && (
                        <tr>
                          <td colSpan={7} className="bg-gray-50 px-4 py-3 dark:bg-white/[0.02]">
                            <form onSubmit={(e) => handleCorrectionSubmit(e, transfer)} className="space-y-2">
                              <div className="flex gap-2">
                                {(["adjust", "void"] as const).map((mode) => (
                                  <button
                                    key={mode}
                                    type="button"
                                    onClick={() => setCorrectionMode(mode)}
                                    className={`rounded-lg px-3 py-1.5 text-theme-xs capitalize ${correctionMode === mode ? "bg-brand-500 text-white" : "bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400"}`}
                                  >
                                    {mode === "adjust" ? "Adjust" : "Void Entirely"}
                                  </button>
                                ))}
                              </div>

                              {correctionError && (
                                <p className="rounded-lg bg-error-50 px-3 py-2 text-theme-xs text-error-600 dark:bg-error-500/15 dark:text-error-400">{correctionError}</p>
                              )}

                              {correctionMode === "adjust" ? (
                                <div>
                                  <Label htmlFor={`adjust_amount_${transfer.id}`}>Adjustment ({transfer.currency}, signed)</Label>
                                  <Input
                                    id={`adjust_amount_${transfer.id}`}
                                    value={correctionAmount}
                                    onChange={(e) => setCorrectionAmount(e.target.value)}
                                    placeholder="-15000"
                                  />
                                </div>
                              ) : (
                                <p className="text-theme-xs text-gray-500 dark:text-gray-400">
                                  Reverses this transfer&apos;s full net credit ({formatForeign(String(netForeign(transfer)), transfer.currency)}) — the money never reached {supplier.name} at all.
                                </p>
                              )}

                              <div>
                                <Label htmlFor={`correction_reason_${transfer.id}`}>Reason (required)</Label>
                                <Input
                                  id={`correction_reason_${transfer.id}`}
                                  value={correctionReason}
                                  onChange={(e) => setCorrectionReason(e.target.value)}
                                  placeholder={correctionMode === "void" ? "Wrong account number — confirmed with Wise support" : "Digiflazz deposit fee missed at entry time"}
                                />
                              </div>

                              <div className="flex justify-end gap-2">
                                <Button type="button" size="small" variant="outlined" onClick={() => setCorrectingId(null)} disabled={correcting}>
                                  Cancel
                                </Button>
                                <Button type="submit" size="small" severity={correctionMode === "void" ? "danger" : undefined} disabled={correcting}>
                                  {correcting ? "Saving…" : correctionMode === "void" ? "Void Entirely" : "Save Adjustment"}
                                </Button>
                              </div>
                            </form>
                          </td>
                        </tr>
                      )}
                    </React.Fragment>
                  );
                })}
                {ledger.transfers.data.length === 0 && (
                  <tr>
                    <td colSpan={7} className="px-4 py-6 text-center text-gray-400">No transfers recorded yet.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  );
}

export default function SupplierTransferModal({ isOpen, onClose, token, supplier }: Props) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-3xl">
            <DialogHeader>
              <DialogTitle>{supplier.name} — Funding Ledger</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && <Content key={supplier.id} token={token} supplier={supplier} />}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
