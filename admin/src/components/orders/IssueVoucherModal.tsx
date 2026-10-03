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
import { Times as CloseIcon } from "@primeicons/react/times";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api-client";
import { issueVoucherFromOrder, type IssueVoucherResult, type OrderDetail } from "@/lib/orders";

interface IssueVoucherModalProps {
  isOpen: boolean;
  onClose: () => void;
  onIssued: (result: IssueVoucherResult) => void;
  order: OrderDetail;
  token: string;
}

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

/**
 * ADR-024 addendum (2026-09-17, restore-only) — nothing left to mint:
 * the cash share is RM0.00 (a full-cover-by-voucher order), so the
 * action only gives the paid-with voucher its balance back.
 */
export function isRestoreOnly(order: OrderDetail): boolean {
  return (order.compensation_preview?.cash_sen ?? 0) === 0;
}

/**
 * ORD-7's other resolution path (ADR-004: retry-delivery or voucher,
 * never a cash refund) — the counterpart to ResendDeliveryModal. The
 * amount is never entered: the backend computes it (ADR-094 decision
 * 33) and `order.compensation_preview` shows exactly what it will be —
 * all of it for a failed order, the undelivered share for a partially
 * delivered one. Rendered only while the dialog is open — fresh state
 * every open, same convention as ResendDeliveryModal.
 *
 * Restore-only mode drops the Reason field: nothing gets created for
 * it to attach to.
 */
function IssueVoucherFields({ onClose, onIssued, order, token }: Omit<IssueVoucherModalProps, "isOpen">) {
  const [reason, setReason] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const preview = order.compensation_preview;
  const isPartial = order.delivery_status === "partially_delivered";
  const restoreOnly = isRestoreOnly(order);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      const result = await issueVoucherFromOrder(token, order.id, {
        reason: restoreOnly ? undefined : reason.trim() || undefined,
      });
      onIssued(result);
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not complete this action.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      {preview === null ? (
        <p className="mb-5 text-sm text-ink-muted">
          No amount can be worked out for this order (a delivery leg has no recorded price). Contact the developer
          before compensating it.
        </p>
      ) : (
        <div className="mb-5 space-y-2 text-sm text-ink-muted">
          {isPartial && (
            <p>
              Part of this order was delivered. Only the undelivered part is compensated, in proportion to what the
              customer paid.
            </p>
          )}
          {preview.cash_sen > 0 && (
            <p>
              Issues a <span className="font-medium">{formatRm(preview.cash_sen)}</span> store-credit voucher to{" "}
              <span className="font-medium">{order.customer_email}</span>.
            </p>
          )}
          {preview.voucher_restore_sen > 0 && (
            <p>
              Returns <span className="font-medium">{formatRm(preview.voucher_restore_sen)}</span> to the voucher this
              order was paid with.
            </p>
          )}
          <p>This platform never issues cash refunds (ADR-004). One compensation per order; it cannot be undone.</p>
        </div>
      )}

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        {!restoreOnly && (
          <div>
            <Label htmlFor="voucher_reason">Reason (Optional)</Label>
            <Input
              id="voucher_reason"
              placeholder="e.g. Customer requested a refund after repeated delivery failures"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
            />
          </div>
        )}

        <div className="flex items-center justify-end gap-3 pt-2">
          <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" disabled={submitting || preview === null}>
            {submitting ? (restoreOnly ? "Restoring…" : "Issuing…") : restoreOnly ? "Restore Voucher" : "Issue Voucher"}
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
              <DialogTitle>{isRestoreOnly(order) ? "Restore Voucher" : "Issue Voucher"}</DialogTitle>
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
