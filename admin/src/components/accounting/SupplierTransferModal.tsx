"use client";

/**
 * ADR-083 decision 2 (PR-1): "Record Supplier Transfer" entry point +
 * the funding ledger's own current balance for one supplier.
 *
 * 2026-09-28 addendum: narrowed to record-form + balance summary only —
 * the full paginated/filterable transfer history (with its inline
 * Adjust/Void/Edit Details corrections) moved to its own page,
 * `/admin/accounting/suppliers/{id}/transfers` (linked below), since
 * the old unpaginated scroll-box history here didn't scale.
 */

import React, { useEffect, useState } from "react";
import Link from "next/link";
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
import { getSupplierTransfers, recordSupplierTransfer, type SupplierFundingLedger } from "@/lib/supplier-transfers";
import type { Supplier } from "@/lib/suppliers";
import { todayInKL } from "@/lib/date-range";

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

function Content({ token, supplier }: Omit<Props, "isOpen" | "onClose">) {
  const [ledger, setLedger] = useState<SupplierFundingLedger | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [transferredOn, setTransferredOn] = useState(todayInKL());
  const [sourceChannel, setSourceChannel] = useState<"wise" | "airwallex" | "bank">("wise");
  const [paidBy, setPaidBy] = useState("");
  const [amountMyr, setAmountMyr] = useState("");
  const [feeMyr, setFeeMyr] = useState("");
  const [amountForeign, setAmountForeign] = useState("");
  const [supplierFee, setSupplierFee] = useState("");
  const [referenceNo, setReferenceNo] = useState("");
  const [receipt, setReceipt] = useState<File | null>(null);
  const [submitting, setSubmitting] = useState(false);

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
        transferred_on: transferredOn,
        source_channel: sourceChannel,
        paid_by: paidBy || undefined,
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
      setPaidBy("");
      setReceipt(null);
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  if (!ledger) {
    return <p className="text-sm text-gray-500 dark:text-gray-400">{error ?? "Loading…"}</p>;
  }

  return (
    <div className="space-y-6">
      <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-white/[0.02]">
        <div className="flex items-start justify-between gap-3">
          <div>
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">Funding ledger balance ({ledger.currency})</p>
            <p className="text-2xl font-semibold text-gray-800 dark:text-white/90">{formatForeign(ledger.ledger_balance, ledger.currency)}</p>
            <p className="mt-1 text-theme-xs text-gray-400 dark:text-gray-500">
              API-polled balance: {supplier.balance ?? "—"} {supplier.currency} — for reference only, not auto-reconciled here.
            </p>
          </div>
          <Link
            href={`/admin/accounting/suppliers/${supplier.id}/transfers`}
            className="whitespace-nowrap text-theme-sm text-brand-500 hover:underline"
          >
            View full history →
          </Link>
        </div>
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
            <Label htmlFor="transfer_date">Transfer date</Label>
            <Input id="transfer_date" type="date" value={transferredOn} onChange={(e) => setTransferredOn(e.target.value)} max={todayInKL()} required />
            <p className="mt-1 text-theme-xs text-gray-400">The day the money left the bank, from the receipt — not today if you&apos;re recording it late.</p>
          </div>
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
            <Label htmlFor="transfer_paid_by">Paid by (optional)</Label>
            <select
              id="transfer_paid_by"
              value={paidBy}
              onChange={(e) => setPaidBy(e.target.value)}
              className="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-theme-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:text-white/90"
            >
              <option value="">Not specified</option>
              {ledger.paid_from_options.map((p) => (
                <option key={p.value} value={p.value}>{p.label}</option>
              ))}
            </select>
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
