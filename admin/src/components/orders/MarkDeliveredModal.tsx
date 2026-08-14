"use client";

import { useState } from "react";
import { Modal } from "@/components/ui/modal";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import Button from "@/components/ui/button/Button";
import { ApiError } from "@/lib/api-client";
import { markOrderDelivered, type OrderDetail } from "@/lib/orders";

interface MarkDeliveredModalProps {
  isOpen: boolean;
  onClose: () => void;
  onConfirmed: (order: OrderDetail) => void;
  order: OrderDetail;
  token: string;
}

/**
 * ADR-026 decision 4a — the one needs_review exit that isn't a retry.
 * Requires the real Gamevion invoice number, not just a confirm click —
 * the admin finds it by cross-referencing Gamevion's dashboard (date
 * range + player ID + game, since there's no reference-number search
 * there, confirmed live). Entering it here closes the exact data gap
 * (a missing supplier_ref) that made this order ambiguous in the first
 * place. Renders as a child of <Modal>, same fresh-state-per-open
 * convention as IssueVoucherModal/ResendDeliveryModal.
 */
function MarkDeliveredFields({ onClose, onConfirmed, order, token }: Omit<MarkDeliveredModalProps, "isOpen">) {
  const [supplierRef, setSupplierRef] = useState("");
  const [note, setNote] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      const updated = await markOrderDelivered(token, order.id, {
        supplier_ref: supplierRef.trim(),
        note: note.trim() || undefined,
      });
      onConfirmed(updated);
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not confirm delivery.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="max-w-lg p-6">
      <h3 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">Mark as Delivered</h3>
      <p className="mb-5 text-sm text-gray-500 dark:text-gray-400">
        Only use this after confirming the real outcome on Gamevion&apos;s own dashboard (search by date range +
        Player ID <span className="font-medium">{order.player_id}</span> + game{" "}
        <span className="font-medium">{order.game?.name ?? "—"}</span> — there is no reference-number search
        there). Paste the exact invoice number you find. This credits ledger profit immediately and cannot be
        undone.
      </p>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label htmlFor="mark_delivered_supplier_ref">Gamevion Invoice Number</Label>
          <Input
            id="mark_delivered_supplier_ref"
            placeholder="e.g. GV-RAPI-1A2B3C4D5E6F"
            value={supplierRef}
            onChange={(e) => setSupplierRef(e.target.value)}
            required
          />
        </div>
        <div>
          <Label htmlFor="mark_delivered_note">Note (Optional)</Label>
          <Input
            id="mark_delivered_note"
            placeholder="e.g. Confirmed on Gamevion dashboard, matched by date + player ID"
            value={note}
            onChange={(e) => setNote(e.target.value)}
          />
        </div>

        <div className="flex items-center justify-end gap-3 pt-2">
          <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" disabled={submitting || !supplierRef.trim()}>
            {submitting ? "Confirming…" : "Mark as Delivered"}
          </Button>
        </div>
      </form>
    </div>
  );
}

export default function MarkDeliveredModal({ isOpen, onClose, onConfirmed, order, token }: MarkDeliveredModalProps) {
  return (
    <Modal isOpen={isOpen} onClose={onClose} className="max-w-lg">
      {isOpen && <MarkDeliveredFields onClose={onClose} onConfirmed={onConfirmed} order={order} token={token} />}
    </Modal>
  );
}
