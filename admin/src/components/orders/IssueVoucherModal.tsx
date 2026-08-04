"use client";

import { useState } from "react";
import { Modal } from "@/components/ui/modal";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import Button from "@/components/ui/button/Button";
import { ApiError } from "@/lib/api-client";
import { issueVoucherFromOrder, type OrderDetail } from "@/lib/orders";
import type { Voucher } from "@/lib/vouchers";

interface IssueVoucherModalProps {
  isOpen: boolean;
  onClose: () => void;
  onIssued: (voucher: Voucher) => void;
  order: OrderDetail;
  token: string;
}

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

/**
 * ORD-7's other resolution path (ADR-004: retry-delivery or voucher,
 * never a cash refund) — the counterpart to ResendDeliveryModal.
 * Amount is never entered here: it's computed server-side from what
 * the customer actually paid (final_amount - transaction_fee), same
 * ORD-9 "never trust a client-submitted money value" principle as
 * everywhere else in checkout/resend. Renders as a child of <Modal>,
 * which unmounts while closed — fresh state every open, same
 * convention as ResendDeliveryModal/CreateValidatorModal.
 */
function IssueVoucherFields({ onClose, onIssued, order, token }: Omit<IssueVoucherModalProps, "isOpen">) {
  const [reason, setReason] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const amount = order.final_amount - order.transaction_fee;

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      const voucher = await issueVoucherFromOrder(token, order.id, { reason: reason.trim() || undefined });
      onIssued(voucher);
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not issue the voucher.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="max-w-lg p-6">
      <h3 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">Issue Voucher</h3>
      <p className="mb-5 text-sm text-gray-500 dark:text-gray-400">
        Issues a <span className="font-medium">{formatRm(amount)}</span> store-credit voucher to{" "}
        <span className="font-medium">{order.customer_email}</span> — this platform never issues cash refunds
        (ADR-004). One voucher per order; this cannot be undone once issued.
      </p>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label htmlFor="voucher_reason">Reason (Optional)</Label>
          <Input
            id="voucher_reason"
            placeholder="e.g. Customer requested a refund after repeated delivery failures"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
        </div>

        <div className="flex items-center justify-end gap-3 pt-2">
          <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" disabled={submitting}>
            {submitting ? "Issuing…" : "Issue Voucher"}
          </Button>
        </div>
      </form>
    </div>
  );
}

export default function IssueVoucherModal({ isOpen, onClose, onIssued, order, token }: IssueVoucherModalProps) {
  return (
    <Modal isOpen={isOpen} onClose={onClose} className="max-w-lg">
      {isOpen && <IssueVoucherFields onClose={onClose} onIssued={onIssued} order={order} token={token} />}
    </Modal>
  );
}
