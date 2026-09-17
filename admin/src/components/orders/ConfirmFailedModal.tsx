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
import { confirmOrderDeliveryFailed, type OrderDetail } from "@/lib/orders";
import { confirmSandboxOrderDeliveryFailed } from "@/lib/sandboxOrders";

interface ConfirmFailedModalProps {
  isOpen: boolean;
  onClose: () => void;
  onConfirmed: (order: OrderDetail) => void;
  order: OrderDetail;
  token: string;
  /** Same sandbox-mode pattern as MarkDeliveredModal — swaps the target endpoint only. */
  sandbox?: boolean;
}

/**
 * ADR-026 addendum (2026-09-16, found shipping ADR-098) — the other
 * needs_review exit decision 4c's own text always assumed existed:
 * "An admin must resolve the order to Delivered or a genuine Failed
 * first" before Issue Voucher becomes available. Structurally
 * necessary for a `delivery_retry_unsafe_with_same_reference` order — retry can
 * never change that outcome, so without this the order has no exit at
 * all. Lands on plain Failed; Issue Voucher is a deliberately separate
 * second step, not collapsed into this one. `note` is required — this
 * is a genuine claim, same weight as Mark Delivered's, just the
 * opposite outcome.
 */
function ConfirmFailedFields({ onClose, onConfirmed, order, token, sandbox }: Omit<ConfirmFailedModalProps, "isOpen">) {
  const [note, setNote] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      const values = { note: note.trim() };
      const updated = sandbox
        ? await confirmSandboxOrderDeliveryFailed(token, order.id, values)
        : await confirmOrderDeliveryFailed(token, order.id, values);
      onConfirmed(updated);
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not confirm this delivery failed.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      <p className="mb-5 text-sm text-ink-muted">
        {sandbox ? (
          <>Sandbox mode — no real supplier order exists to look up. Enter any note to exercise this flow.</>
        ) : (
          <>
            Only use this once you&apos;ve confirmed the supplier genuinely never delivered this order — resending
            {order.delivery_retry_unsafe_with_same_reference
              ? " won't change the outcome for this order (the supplier already recorded a final result for this reference)."
              : "."}{" "}
            This moves the order to <span className="font-medium">Failed</span>, unlocking Issue Voucher as a
            separate next step, and cannot be undone.
          </>
        )}
      </p>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label htmlFor="confirm_failed_note">Reason (Required)</Label>
          <Input
            id="confirm_failed_note"
            placeholder="e.g. Same rc=02 replayed on every resend — confirmed dead via request logs."
            value={note}
            onChange={(e) => setNote(e.target.value)}
            required
          />
        </div>

        <div className="flex items-center justify-end gap-3 pt-2">
          <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" severity="danger" disabled={submitting || !note.trim()}>
            {submitting ? "Confirming…" : "Confirm Failed"}
          </Button>
        </div>
      </form>
    </>
  );
}

export default function ConfirmFailedModal({ isOpen, onClose, onConfirmed, order, token, sandbox }: ConfirmFailedModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-lg">
            <DialogHeader>
              <DialogTitle>Confirm Delivery Failed</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && (
                <ConfirmFailedFields onClose={onClose} onConfirmed={onConfirmed} order={order} token={token} sandbox={sandbox} />
              )}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
