"use client";

/**
 * ADR-004 2026-11-12 addendum: pay a failed order's voucher out by bank
 * transfer. The transfer goes out first; this records it with the proof.
 * The amount is the voucher's balance, set by the server.
 */

import React, { useState } from "react";
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
  DialogFooter,
} from "@/components/ui/dialog";
import { Times as CloseIcon } from "@primeicons/react/times";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api-client";
import { notify } from "@/components/ui/toast";
import { refundVoucherInCash, type RefundSourceOrder, type VoucherDetail } from "@/lib/vouchers";

interface Props {
  isOpen: boolean;
  onClose: () => void;
  voucher: VoucherDetail;
  sourceOrders: RefundSourceOrder[];
  onRefunded: () => void;
}

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

/** Malaysia's calendar day, as the backend reads it. */
function malaysiaToday(): string {
  return new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Kuala_Lumpur" }).format(new Date());
}

function messagesOf(err: unknown): string[] {
  if (!(err instanceof ApiError)) return ["Something went wrong."];
  const errors = err.payload?.errors as Record<string, string[]> | undefined;
  const all = errors ? Object.values(errors).flat() : [];
  return all.length > 0 ? all : [err.message];
}

function Content({ voucher, sourceOrders, onRefunded }: Omit<Props, "isOpen" | "onClose">) {
  const today = malaysiaToday();
  const [transferredOn, setTransferredOn] = useState(today);
  const [bankName, setBankName] = useState("");
  const [accountNo, setAccountNo] = useState("");
  const [holder, setHolder] = useState("");
  const [reference, setReference] = useState("");
  const [reason, setReason] = useState("");
  const [proof, setProof] = useState<File | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const late = sourceOrders.filter((o) => o.late_supplier_result !== null);
  const ready = late.length === 0 && proof !== null && transferredOn !== "" && bankName.trim().length >= 2
    && /^[0-9 -]{5,30}$/.test(accountNo.trim()) && holder.trim().length >= 2 && reference.trim().length >= 3 && reason.trim().length >= 3;

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!proof) return;
    setError(null);
    setSubmitting(true);
    try {
      const result = await refundVoucherInCash(voucher.id, {
        transferred_on: transferredOn,
        bank_name: bankName.trim(),
        bank_account_no: accountNo.trim(),
        bank_account_holder: holder.trim(),
        bank_reference: reference.trim(),
        reason: reason.trim(),
        proof,
      });
      notify.success("Refund recorded", `${voucher.code} · ${formatRm(result.cash_refund.amount_sen)} paid by bank transfer`);
      onRefunded();
    } catch (err) {
      const messages = messagesOf(err);
      setError(messages.join(" "));
      notify.stickyError("Could not record the refund", messages[0]);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-white/[0.02]">
        <p className="text-theme-xs text-gray-500 dark:text-gray-400">Balance to refund</p>
        <p className="text-2xl font-semibold text-gray-800 dark:text-white/90">{formatRm(voucher.remaining)}</p>
        <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
          The payment fee is not refunded, the same as the voucher. The voucher closes and can no longer be spent.
        </p>
      </div>

      <div>
        <p className="mb-1 text-theme-xs text-gray-500 dark:text-gray-400">Failed order{sourceOrders.length > 1 ? "s" : ""} behind this voucher</p>
        <ul className="space-y-1">
          {sourceOrders.map((o) => (
            <li key={o.id} className="text-theme-sm text-gray-800 dark:text-white/90">
              <span className="font-medium">{o.order_number}</span>
              <span className="text-gray-500 dark:text-gray-400"> · {o.delivery_status}</span>
              {o.late_supplier_result !== null && <span className="text-error-600 dark:text-error-400"> · the supplier reported a late result</span>}
            </li>
          ))}
        </ul>
      </div>

      {late.length > 0 ? (
        <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          The supplier sent a result after {late.map((o) => o.order_number).join(", ")} failed. It may have been delivered. Check with the supplier first; a cash refund is blocked while this result is recorded.
        </p>
      ) : (
        <p className="text-sm text-gray-600 dark:text-gray-300">
          Check with the supplier that the order really was not delivered. Then send {formatRm(voucher.remaining)} by bank transfer FIRST, and record it here. This cannot be undone.
        </p>
      )}

      {error && (
        <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div>
          <Label htmlFor="refund_bank">Bank</Label>
          <Input id="refund_bank" value={bankName} onChange={(e) => setBankName(e.target.value)} placeholder="e.g. Maybank" maxLength={100} required />
        </div>
        <div>
          <Label htmlFor="refund_account">Account number</Label>
          <Input id="refund_account" value={accountNo} onChange={(e) => setAccountNo(e.target.value)} inputMode="numeric" maxLength={30} required />
        </div>
        <div className="sm:col-span-2">
          <Label htmlFor="refund_holder">Account holder (as the bank shows it)</Label>
          <Input id="refund_holder" value={holder} onChange={(e) => setHolder(e.target.value)} maxLength={150} required />
        </div>
        <div>
          <Label htmlFor="refund_date">Date the transfer was sent</Label>
          <Input id="refund_date" type="date" value={transferredOn} min={`${today.slice(0, 7)}-01`} max={today} onChange={(e) => setTransferredOn(e.target.value)} required />
        </div>
        <div>
          <Label htmlFor="refund_ref">Bank reference</Label>
          <Input id="refund_ref" value={reference} onChange={(e) => setReference(e.target.value)} placeholder="Transfer reference" maxLength={100} required />
        </div>
        <div className="sm:col-span-2">
          <Label htmlFor="refund_proof">Proof of transfer</Label>
          <input
            id="refund_proof"
            type="file"
            accept=".jpg,.jpeg,.png,.pdf"
            required
            onChange={(e) => setProof(e.target.files?.[0] ?? null)}
            className="block w-full text-theme-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-theme-xs dark:text-gray-400 dark:file:bg-gray-800"
          />
        </div>
        <div className="sm:col-span-2">
          <Label htmlFor="refund_reason">Why cash, not the voucher</Label>
          <Textarea id="refund_reason" value={reason} onChange={(e) => setReason(e.target.value)} rows={2} maxLength={500} required />
        </div>
      </div>

      <DialogFooter>
        <Button type="submit" severity="danger" disabled={!ready || submitting}>
          {submitting ? "Recording…" : `Record ${formatRm(voucher.remaining)} refund`}
        </Button>
      </DialogFooter>
    </form>
  );
}

export default function VoucherCashRefundModal({ isOpen, onClose, voucher, sourceOrders, onRefunded }: Props) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-lg">
            <DialogHeader>
              <DialogTitle>Refund {voucher.code} in cash</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && <Content key={voucher.id} voucher={voucher} sourceOrders={sourceOrders} onRefunded={onRefunded} />}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
