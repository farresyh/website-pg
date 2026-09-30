"use client";

/**
 * ADR-083 2026-09-28 addendum — Funding History: the full, paginated,
 * filterable transfer history for one supplier, moved out of
 * `SupplierTransferModal`'s old unpaginated scroll box. The modal now
 * only records new transfers + shows the balance summary; this page is
 * where the real history (and its Adjust/Void/Edit Details corrections)
 * gets read.
 */

import React, { useEffect, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Tag } from "@/components/ui/tag";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { listSuppliers, type Supplier } from "@/lib/suppliers";
import {
  getSupplierTransfers,
  adjustSupplierTransfer,
  voidSupplierTransfer,
  correctSupplierTransfer,
  downloadSupplierTransferReceipt,
  type SupplierFundingLedger,
  type SupplierTransfer,
  type CorrectSupplierTransferValues,
} from "@/lib/supplier-transfers";

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

function netForeign(transfer: SupplierTransfer): number {
  return Number(transfer.amount_foreign_received) - Number(transfer.supplier_fee ?? 0);
}

const sourceChannelLabel: Record<string, string> = {
  wise: "Wise",
  airwallex: "Airwallex",
  bank: "Bank transfer",
};

export default function SupplierFundingHistoryPage() {
  const router = useRouter();
  const session = useClientSession();
  const token = session?.token ?? null;
  const params = useParams<{ id: string }>();
  const supplierId = Number(params.id);

  const [supplier, setSupplier] = useState<Supplier | null>(null);
  const [ledger, setLedger] = useState<SupplierFundingLedger | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [status, setStatus] = useState<"" | "active" | "voided">("");
  const [page, setPage] = useState(1);
  const [downloading, setDownloading] = useState<number | null>(null);

  const [correctingId, setCorrectingId] = useState<number | null>(null);
  const [correctionMode, setCorrectionMode] = useState<"adjust" | "void" | "edit">("adjust");
  const [correctionAmount, setCorrectionAmount] = useState("");
  const [correctionReason, setCorrectionReason] = useState("");
  const [editSourceChannel, setEditSourceChannel] = useState<"wise" | "airwallex" | "bank">("wise");
  const [editPaidBy, setEditPaidBy] = useState("");
  const [editReferenceNo, setEditReferenceNo] = useState("");
  const [editAmountMyr, setEditAmountMyr] = useState("");
  const [editFeeMyr, setEditFeeMyr] = useState("");
  const [editReceipt, setEditReceipt] = useState<File | null>(null);
  const [correcting, setCorrecting] = useState(false);
  const [correctionError, setCorrectionError] = useState<string | null>(null);

  function refresh(t: string, p: number) {
    return getSupplierTransfers(t, supplierId, {
      from: from || undefined,
      to: to || undefined,
      status: status || undefined,
      page: p,
    })
      .then(setLedger)
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load the funding ledger."));
  }

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    listSuppliers(s.token)
      .then((suppliers) => setSupplier(suppliers.find((x) => x.id === supplierId) ?? null))
      .catch(() => {});
    refresh(s.token, 1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function handleFilter() {
    if (!token) return;
    setPage(1);
    refresh(token, 1);
  }

  function handlePageChange(p: number) {
    if (!token) return;
    setPage(p);
    refresh(token, p);
  }

  function openCorrection(transfer: SupplierTransfer) {
    setCorrectingId((current) => (current === transfer.id ? null : transfer.id));
    setCorrectionMode("adjust");
    setCorrectionAmount("");
    setCorrectionReason("");
    setCorrectionError(null);
    setEditSourceChannel(transfer.source_channel);
    setEditPaidBy(transfer.paid_by ?? "");
    setEditReferenceNo(transfer.reference_no ?? "");
    setEditAmountMyr((transfer.amount_myr_sent / 100).toFixed(2));
    setEditFeeMyr((transfer.fee_myr / 100).toFixed(2));
    setEditReceipt(null);
  }

  async function handleCorrectionSubmit(e: React.FormEvent, transfer: SupplierTransfer) {
    e.preventDefault();
    if (!token) return;
    setCorrectionError(null);

    if (!correctionReason.trim()) {
      setCorrectionError("Enter a reason — required for every correction.");
      return;
    }

    setCorrecting(true);
    try {
      if (correctionMode === "void") {
        await voidSupplierTransfer(token, transfer.id, correctionReason.trim());
      } else if (correctionMode === "adjust") {
        if (!correctionAmount || Number(correctionAmount) === 0) {
          setCorrectionError("Enter a non-zero adjustment amount.");
          setCorrecting(false);
          return;
        }
        await adjustSupplierTransfer(token, transfer.id, correctionAmount, correctionReason.trim());
      } else {
        const values: CorrectSupplierTransferValues = { reason: correctionReason.trim() };
        let hasChange = false;

        if (editSourceChannel !== transfer.source_channel) {
          values.source_channel = editSourceChannel;
          hasChange = true;
        }
        if (editPaidBy !== (transfer.paid_by ?? "")) {
          values.paid_by = editPaidBy;
          hasChange = true;
        }
        if (editReferenceNo !== (transfer.reference_no ?? "")) {
          values.reference_no = editReferenceNo;
          hasChange = true;
        }
        const newAmountSen = Math.round(parseFloat(editAmountMyr) * 100);
        if (Number.isFinite(newAmountSen) && newAmountSen !== transfer.amount_myr_sent) {
          values.amount_myr_sent = newAmountSen;
          hasChange = true;
        }
        const newFeeSen = Math.round(parseFloat(editFeeMyr || "0") * 100);
        if (Number.isFinite(newFeeSen) && newFeeSen !== transfer.fee_myr) {
          values.fee_myr = newFeeSen;
          hasChange = true;
        }
        if (editReceipt) {
          values.receipt = editReceipt;
          hasChange = true;
        }

        if (!hasChange) {
          setCorrectionError("Change at least one field.");
          setCorrecting(false);
          return;
        }

        await correctSupplierTransfer(token, transfer.id, values);
      }
      setCorrectingId(null);
      await refresh(token, page);
    } catch (err) {
      setCorrectionError(err instanceof ApiError ? err.message : "Something went wrong.");
    } finally {
      setCorrecting(false);
    }
  }

  async function handleDownload(transfer: SupplierTransfer) {
    if (!token) return;
    setDownloading(transfer.id);
    try {
      await downloadSupplierTransferReceipt(token, transfer.id, `supplier-transfer-${transfer.id}-receipt`);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Download failed.");
    } finally {
      setDownloading(null);
    }
  }

  if (error && !ledger) {
    return <p className="rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>;
  }

  if (!token || !ledger) {
    return <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>;
  }

  return (
    <div>
      <div className="mb-6">
        <Link href="/admin/accounting/suppliers" className="text-theme-sm text-brand-500 hover:underline">
          ← Back to Supplier Funding
        </Link>
        <h1 className="mt-2 text-xl font-semibold text-gray-800 dark:text-white/90">
          {supplier?.name ?? "Supplier"} — Funding History
        </h1>
        <div className="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-white/[0.02]">
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Funding ledger balance ({ledger.currency})</p>
          <p className="text-2xl font-semibold text-gray-800 dark:text-white/90">{formatForeign(ledger.ledger_balance, ledger.currency)}</p>
        </div>
      </div>

      <div className="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 p-4 dark:border-gray-800">
        <div>
          <Label htmlFor="fh_from">From</Label>
          <Input id="fh_from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
        </div>
        <div>
          <Label htmlFor="fh_to">To</Label>
          <Input id="fh_to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
        </div>
        <div>
          <Label htmlFor="fh_status">Status</Label>
          <select
            id="fh_status"
            value={status}
            onChange={(e) => setStatus(e.target.value as "" | "active" | "voided")}
            className="h-11 rounded-lg border border-gray-300 bg-transparent px-3 text-theme-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:text-white/90"
          >
            <option value="">All</option>
            <option value="active">Active</option>
            <option value="voided">Voided</option>
          </select>
        </div>
        <Button size="small" onClick={handleFilter}>Filter</Button>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <table className="w-full text-left text-theme-sm">
            <thead className="bg-gray-50 dark:bg-gray-900">
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
                      <td className="px-4 py-2 text-gray-700 dark:text-gray-300">
                        {sourceChannelLabel[transfer.source_channel] ?? transfer.source_channel}
                        {transfer.paid_by && (
                          <span className="block text-theme-xs text-gray-400">
                            {ledger.paid_from_options.find((p) => p.value === transfer.paid_by)?.label ?? transfer.paid_by}
                          </span>
                        )}
                      </td>
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

                    {transfer.corrections.map((correction) => (
                      <tr key={`corr-${correction.id}`} className="bg-gray-50/50 dark:bg-white/[0.02]">
                        <td colSpan={3} className="px-4 py-1.5 pl-8 text-theme-xs text-gray-400">
                          ↳ {formatDate(correction.created_at)}
                        </td>
                        <td colSpan={4} className="px-4 py-1.5 text-theme-xs">
                          <span className="text-warning-600 dark:text-warning-400">
                            Edited: {Object.entries(correction.changes).map(([field, [oldV, newV]]) => `${field} ${String(oldV)}→${String(newV)}`).join(", ")}
                          </span>
                          <span className="ml-2 text-gray-500 dark:text-gray-400">{correction.reason}</span>
                        </td>
                      </tr>
                    ))}

                    {correctingId === transfer.id && (
                      <tr>
                        <td colSpan={7} className="bg-gray-50 px-4 py-3 dark:bg-white/[0.02]">
                          <form onSubmit={(e) => handleCorrectionSubmit(e, transfer)} className="space-y-2">
                            <div className="flex gap-2">
                              {(["adjust", "edit", "void"] as const).map((mode) => (
                                <button
                                  key={mode}
                                  type="button"
                                  onClick={() => setCorrectionMode(mode)}
                                  className={`rounded-lg px-3 py-1.5 text-theme-xs capitalize ${correctionMode === mode ? "bg-brand-500 text-white" : "bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400"}`}
                                >
                                  {mode === "adjust" ? "Adjust" : mode === "edit" ? "Edit Details" : "Void Entirely"}
                                </button>
                              ))}
                            </div>

                            {correctionError && (
                              <p className="rounded-lg bg-error-50 px-3 py-2 text-theme-xs text-error-600 dark:bg-error-500/15 dark:text-error-400">{correctionError}</p>
                            )}

                            {correctionMode === "adjust" && (
                              <div>
                                <Label htmlFor={`adjust_amount_${transfer.id}`}>Adjustment ({transfer.currency}, signed)</Label>
                                <Input
                                  id={`adjust_amount_${transfer.id}`}
                                  value={correctionAmount}
                                  onChange={(e) => setCorrectionAmount(e.target.value)}
                                  placeholder="-15000"
                                />
                              </div>
                            )}

                            {correctionMode === "void" && (
                              <p className="text-theme-xs text-gray-500 dark:text-gray-400">
                                Reverses this transfer&apos;s full net credit ({formatForeign(String(netForeign(transfer)), transfer.currency)}) plus any prior adjustments — the money never reached {supplier?.name ?? "the supplier"} at all.
                              </p>
                            )}

                            {correctionMode === "edit" && (
                              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <p className="text-theme-xs text-gray-400 sm:col-span-2">
                                  Corrects only how this transfer was recorded (RM sent/fee/channel/reference/receipt) — never the {transfer.currency} amount credited to the ledger. Only the fields you change are sent.
                                </p>
                                <div>
                                  <Label htmlFor={`edit_channel_${transfer.id}`}>Sent via</Label>
                                  <select
                                    id={`edit_channel_${transfer.id}`}
                                    value={editSourceChannel}
                                    onChange={(e) => setEditSourceChannel(e.target.value as "wise" | "airwallex" | "bank")}
                                    className="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-theme-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:text-white/90"
                                  >
                                    <option value="wise">Wise</option>
                                    <option value="airwallex">Airwallex</option>
                                    <option value="bank">Bank transfer</option>
                                  </select>
                                </div>
                                <div>
                                  <Label htmlFor={`edit_paid_by_${transfer.id}`}>Paid by</Label>
                                  <select
                                    id={`edit_paid_by_${transfer.id}`}
                                    value={editPaidBy}
                                    onChange={(e) => setEditPaidBy(e.target.value)}
                                    className="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-theme-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:text-white/90"
                                  >
                                    <option value="">Not specified</option>
                                    {ledger.paid_from_options.map((p) => (
                                      <option key={p.value} value={p.value}>{p.label}</option>
                                    ))}
                                  </select>
                                </div>
                                <div>
                                  <Label htmlFor={`edit_ref_${transfer.id}`}>Reference no.</Label>
                                  <Input id={`edit_ref_${transfer.id}`} value={editReferenceNo} onChange={(e) => setEditReferenceNo(e.target.value)} />
                                </div>
                                <div>
                                  <Label htmlFor={`edit_amount_${transfer.id}`}>Amount sent (RM)</Label>
                                  <Input id={`edit_amount_${transfer.id}`} value={editAmountMyr} onChange={(e) => setEditAmountMyr(e.target.value)} />
                                </div>
                                <div>
                                  <Label htmlFor={`edit_fee_${transfer.id}`}>Transfer fee (RM)</Label>
                                  <Input id={`edit_fee_${transfer.id}`} value={editFeeMyr} onChange={(e) => setEditFeeMyr(e.target.value)} />
                                </div>
                                <div className="sm:col-span-2">
                                  <Label htmlFor={`edit_receipt_${transfer.id}`}>Replace receipt (optional)</Label>
                                  <input
                                    id={`edit_receipt_${transfer.id}`}
                                    type="file"
                                    accept=".jpg,.jpeg,.png,.pdf"
                                    onChange={(e) => setEditReceipt(e.target.files?.[0] ?? null)}
                                    className="block w-full text-theme-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-theme-xs dark:text-gray-400 dark:file:bg-gray-800"
                                  />
                                </div>
                              </div>
                            )}

                            <div>
                              <Label htmlFor={`correction_reason_${transfer.id}`}>Reason (required)</Label>
                              <Input
                                id={`correction_reason_${transfer.id}`}
                                value={correctionReason}
                                onChange={(e) => setCorrectionReason(e.target.value)}
                                placeholder={correctionMode === "void" ? "Wrong account number — confirmed with Wise support" : correctionMode === "edit" ? "Fat-fingered the RM sent amount at entry time" : "Digiflazz deposit fee missed at entry time"}
                              />
                            </div>

                            <div className="flex justify-end gap-2">
                              <Button type="button" size="small" variant="outlined" onClick={() => setCorrectingId(null)} disabled={correcting}>
                                Cancel
                              </Button>
                              <Button type="submit" size="small" severity={correctionMode === "void" ? "danger" : undefined} disabled={correcting}>
                                {correcting ? "Saving…" : correctionMode === "void" ? "Void Entirely" : correctionMode === "edit" ? "Save Details" : "Save Adjustment"}
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

      {ledger.transfers.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-theme-sm text-gray-500 dark:text-gray-400">
          <span>
            Page {ledger.transfers.current_page} of {ledger.transfers.last_page} ({ledger.transfers.total} total)
          </span>
          <div className="flex gap-2">
            <Button size="small" variant="outlined" disabled={page <= 1} onClick={() => handlePageChange(page - 1)}>
              Previous
            </Button>
            <Button
              size="small"
              variant="outlined"
              disabled={page >= ledger.transfers.last_page}
              onClick={() => handlePageChange(page + 1)}
            >
              Next
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
