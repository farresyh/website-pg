"use client";

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
} from "@/components/ui/dialog";
import { Times as CloseIcon } from "@primeicons/react/times";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api-client";
import { notify } from "@/components/ui/toast";
import { confirmManualDelivered, confirmManualFailed, correctManualToDelivered } from "@/lib/orders";

type Action = "delivered" | "failed" | "correct";

interface ManualOrder {
  id: number;
  order_number: string;
  delivery_status: string;
  supplier_id: number | null;
  cost_price: number;
  /** Order Detail only: a Failed order may be corrected while it is still compensable (no voucher yet). */
  compensable?: boolean;
}

const TITLES: Record<Action, string> = {
  delivered: "Mark Delivered",
  failed: "Mark Failed",
  correct: "Correct Failed → Delivered",
};

/**
 * ADR-125 decisions 8/36/42: the human confirmation of a manual
 * supplier's order, shared by the Manual Queue and Order Detail. Every
 * action goes through the backend's ManualDeliveryService
 * (finalizePendingDelivery: real cost, profit, voucher, drawdown). The
 * real cost defaults to the agreed cost; changing it needs a reason.
 */
function ActionForm({ order, action, onClose, onDone }: { order: ManualOrder; action: Action; onClose: () => void; onDone: () => void }) {
  const agreed = (order.cost_price / 100).toFixed(2);
  const [cost, setCost] = useState(agreed);
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const costChanged = action !== "failed" && cost.trim() !== agreed;
  const reasonRequired = action !== "delivered" || costChanged;

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const amount = Number(cost);
    if (action !== "failed" && !(Number.isFinite(amount) && amount > 0)) {
      setError("Enter a real cost above RM 0.");
      return;
    }
    if (order.supplier_id == null) {
      setError("This order has no supplier.");
      return;
    }
    const supplierId = order.supplier_id;
    setError(null);
    setSubmitting(true);
    try {
      const realCostSen = costChanged ? Math.round(amount * 100) : null;
      if (action === "delivered") await confirmManualDelivered(order.id, { supplier_id: supplierId, real_cost_sen: realCostSen, reason: reason.trim() || null });
      if (action === "failed") await confirmManualFailed(order.id, supplierId, reason.trim());
      if (action === "correct") await correctManualToDelivered(order.id, { supplier_id: supplierId, real_cost_sen: realCostSen, reason: reason.trim() });
      notify.success(action === "delivered" ? "Order marked delivered" : action === "failed" ? "Order marked failed" : "Order corrected to delivered");
      onDone();
    } catch (err) {
      notify.stickyError("Action failed", err instanceof ApiError ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      {error && <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>}
      <p className="text-sm text-gray-700 dark:text-gray-300">
        Order <span className="font-medium">{order.order_number}</span>
        {action === "delivered" && " — the supplier confirmed the top-up."}
        {action === "failed" && " — the supplier could not top up. The customer gets nothing back automatically; Retry, Resend or Issue Voucher next."}
        {action === "correct" && " was marked Failed, but the supplier did top up. Only possible before a voucher is issued."}
      </p>
      {action !== "failed" && (
        <div>
          <Label htmlFor="manual_real_cost">Real cost paid to the supplier (RM)</Label>
          <Input id="manual_real_cost" inputMode="decimal" value={cost} onChange={(e) => setCost(e.target.value)} required />
          <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">Agreed cost: RM {agreed}. Change it only if the supplier charged differently.</p>
        </div>
      )}
      <div>
        <Label htmlFor="manual_reason">Reason{reasonRequired ? "" : " (optional)"}</Label>
        <Input
          id="manual_reason"
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          required={reasonRequired}
          placeholder={action === "failed" ? "e.g. Wrong password" : "e.g. Supplier raised the price"}
        />
      </div>
      <div className="flex justify-end gap-3">
        <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>Cancel</Button>
        <Button type="submit" severity={action === "failed" ? "danger" : undefined} disabled={submitting}>
          {submitting ? "Saving…" : TITLES[action]}
        </Button>
      </div>
    </form>
  );
}

export default function ManualOrderActions({ order, onDone }: { order: ManualOrder; onDone: () => void }) {
  const [action, setAction] = useState<Action | null>(null);
  const open = order.delivery_status === "pending" || order.delivery_status === "needs_review";
  const correctable = order.delivery_status === "failed" && order.compensable === true;

  if (!open && !correctable) return null;

  return (
    <>
      <div className="flex flex-wrap items-center gap-2">
        {open && (
          <>
            <Button size="small" onClick={() => setAction("delivered")}>Mark Delivered…</Button>
            <Button size="small" variant="outlined" severity="danger" onClick={() => setAction("failed")}>Mark Failed…</Button>
          </>
        )}
        {correctable && (
          <Button size="small" variant="outlined" onClick={() => setAction("correct")}>Correct to Delivered…</Button>
        )}
      </div>

      <Dialog open={action !== null} onOpenChange={(e) => { if (!e.value) setAction(null); }}>
        <DialogPortal>
          <DialogBackdrop />
          <DialogPositioner>
            <DialogPopup className="w-full max-w-md">
              <DialogHeader>
                <DialogTitle>{action ? TITLES[action] : ""}</DialogTitle>
                <DialogHeaderActions>
                  <DialogClose aria-label="Close">
                    <CloseIcon className="h-5 w-5" />
                  </DialogClose>
                </DialogHeaderActions>
              </DialogHeader>
              <DialogContent>
                {action && (
                  <ActionForm
                    order={order}
                    action={action}
                    onClose={() => setAction(null)}
                    onDone={() => {
                      setAction(null);
                      onDone();
                    }}
                  />
                )}
              </DialogContent>
            </DialogPopup>
          </DialogPositioner>
        </DialogPortal>
      </Dialog>
    </>
  );
}
