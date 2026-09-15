"use client";

import { useState } from "react";
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
import { CloseIcon } from "@/icons";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
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
 * never a cash refund) — the counterpart to ResendDeliveryModal. For
 * an ordinary failed order, amount is never entered: it's computed
 * server-side from what the customer actually paid (final_amount -
 * transaction_fee), same ORD-9 "never trust a client-submitted money
 * value" principle as everywhere else in checkout/resend. ADR-094
 * decision 9's carve-out is the one exception — a genuine partial-
 * delivery combo order (`order.partial_combo_delivery`) has no single
 * correct auto-computed figure (the player already has some of the
 * goods), so a custom amount is entered here, prefilled from the
 * failed leg(s)' own price and admin-adjustable; the backend still
 * caps whatever's sent at `final_amount` independently. Rendered only
 * while the dialog is open — fresh state every open, same convention
 * as ResendDeliveryModal/CreateValidatorModal.
 */
function IssueVoucherFields({ onClose, onIssued, order, token }: Omit<IssueVoucherModalProps, "isOpen">) {
  const [reason, setReason] = useState("");
  const [customAmount, setCustomAmount] = useState(
    order.suggested_voucher_amount !== null ? (order.suggested_voucher_amount / 100).toFixed(2) : "",
  );
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const isPartial = order.partial_combo_delivery;
  const amount = isPartial ? Math.round(parseFloat(customAmount || "0") * 100) : order.final_amount - order.transaction_fee;

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      const voucher = await issueVoucherFromOrder(token, order.id, {
        reason: reason.trim() || undefined,
        amount: isPartial ? amount : undefined,
      });
      onIssued(voucher);
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not issue the voucher.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      {isPartial ? (
        <p className="mb-5 text-sm text-gray-500 dark:text-gray-400">
          This combo order partially delivered — the customer already received some of the goods. Set the
          store-credit amount for the part that failed, for{" "}
          <span className="font-medium">{order.customer_email}</span>. This platform never issues cash refunds
          (ADR-004). One voucher per order; this cannot be undone once issued.
        </p>
      ) : (
        <p className="mb-5 text-sm text-gray-500 dark:text-gray-400">
          Issues a <span className="font-medium">{formatRm(amount)}</span> store-credit voucher to{" "}
          <span className="font-medium">{order.customer_email}</span> — this platform never issues cash refunds
          (ADR-004). One voucher per order; this cannot be undone once issued.
        </p>
      )}

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        {isPartial && (
          <div>
            <Label htmlFor="voucher_amount">Amount (RM)</Label>
            <div className="relative">
              <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">RM</span>
              <Input
                id="voucher_amount"
                type="text"
                value={customAmount}
                onChange={(e) => setCustomAmount(e.target.value)}
                placeholder="e.g. 275.00"
                required
                className="pl-9"
              />
            </div>
            <p className="mt-1 text-theme-xs text-gray-400">
              Prefilled from the failed leg&apos;s own price — adjust if needed. Cannot exceed{" "}
              {formatRm(order.final_amount)} (what the customer paid).
            </p>
          </div>
        )}

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
          <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" disabled={submitting || (isPartial && (!Number.isFinite(amount) || amount <= 0))}>
            {submitting ? "Issuing…" : "Issue Voucher"}
          </Button>
        </div>
      </form>
    </>
  );
}

export default function IssueVoucherModal({ isOpen, onClose, onIssued, order, token }: IssueVoucherModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-lg">
            <DialogHeader>
              <DialogTitle>Issue Voucher</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && <IssueVoucherFields onClose={onClose} onIssued={onIssued} order={order} token={token} />}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
