"use client";

import React, { useRef, useState } from "react";
import { Modal } from "@/components/ui/modal";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import Button from "@/components/ui/button/Button";
import type { CreateVoucherValues } from "@/lib/vouchers";

interface CreateVoucherModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (values: CreateVoucherValues) => Promise<void>;
}

/**
 * Renders as a child of <Modal>, which unmounts while closed — same
 * fresh-mount-per-open reasoning as AdminUserFormFields / WithdrawalRequestFields.
 */
function CreateVoucherFields({ onClose, onSubmit }: Omit<CreateVoucherModalProps, "isOpen">) {
  const [customerEmail, setCustomerEmail] = useState("");
  const [amountRm, setAmountRm] = useState("");
  const [reason, setReason] = useState("");
  const [expiresAt, setExpiresAt] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  // ADR-035: one key per modal open, reused across every resubmit of
  // this same attempt (double-click, timeout retry) — CreateVoucherFields
  // fully unmounts while the modal is closed (see this file's own
  // fresh-mount-per-open note), so this component's mount already *is*
  // the "modal open" event; a fresh open always gets a fresh key.
  const idempotencyKeyRef = useRef<string | null>(null);
  if (idempotencyKeyRef.current === null) {
    idempotencyKeyRef.current = crypto.randomUUID();
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);

    const amountSen = Math.round(parseFloat(amountRm) * 100);
    if (!Number.isFinite(amountSen) || amountSen <= 0) {
      setError("Enter a valid amount.");
      return;
    }

    setSubmitting(true);
    try {
      await onSubmit({
        customer_email: customerEmail,
        amount: amountSen,
        reason,
        expires_at: expiresAt || null,
        idempotency_key: idempotencyKeyRef.current!,
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="max-w-md p-6">
      <h3 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">Create Voucher</h3>
      <p className="mb-5 text-sm text-gray-500 dark:text-gray-400">
        Standalone voucher — debited from the platform balance. Vouchers above the maker-checker
        threshold require a Super Admin.
      </p>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label htmlFor="customer_email">Customer Email</Label>
          <Input id="customer_email" type="email" value={customerEmail} onChange={(e) => setCustomerEmail(e.target.value)} required />
        </div>
        <div>
          <Label htmlFor="amount">Amount (RM)</Label>
          <Input id="amount" type="text" value={amountRm} onChange={(e) => setAmountRm(e.target.value)} placeholder="0.00" required />
        </div>
        <div>
          <Label htmlFor="reason">Reason</Label>
          <Input id="reason" value={reason} onChange={(e) => setReason(e.target.value)} required />
        </div>
        <div>
          <Label htmlFor="expires_at">Expiry (optional)</Label>
          <Input id="expires_at" type="text" value={expiresAt} onChange={(e) => setExpiresAt(e.target.value)} placeholder="YYYY-MM-DD" />
        </div>

        <div className="flex items-center justify-end gap-3 pt-2">
          <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" disabled={submitting}>
            {submitting ? "Creating…" : "Create Voucher"}
          </Button>
        </div>
      </form>
    </div>
  );
}

export default function CreateVoucherModal({ isOpen, onClose, onSubmit }: CreateVoucherModalProps) {
  return (
    <Modal isOpen={isOpen} onClose={onClose} className="max-w-md">
      {isOpen && <CreateVoucherFields onClose={onClose} onSubmit={onSubmit} />}
    </Modal>
  );
}
