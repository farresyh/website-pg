"use client";

import React, { useRef, useState } from "react";
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
import { SimpleSelect } from "@/components/ui/select";
import { Button } from "@/components/ui/button";
import type { MembershipPlan, RecordMembershipPaymentValues } from "@/lib/membership";

interface RecordPaymentModalProps {
  isOpen: boolean;
  onClose: () => void;
  plans: MembershipPlan[];
  presetEmail?: string;
  onSubmit: (values: RecordMembershipPaymentValues) => Promise<void>;
}

function toRm(sen: number): string {
  return (sen / 100).toFixed(2);
}

/**
 * ADR-027 continued addendum decision 15 / Phase 6.5 (grilled
 * 2026-08-29). Fee defaults to the chosen plan's `fee_sen` (Q4) but is
 * editable — a deviation from the plan fee needs a reason, enforced
 * server-side. One idempotency key per open (ADR-035 pattern), fresh on
 * every mount since this unmounts while closed.
 */
function RecordPaymentFields({ onClose, plans, presetEmail, onSubmit }: Omit<RecordPaymentModalProps, "isOpen">) {
  const [email, setEmail] = useState(presetEmail ?? "");
  const [planId, setPlanId] = useState(plans[0]?.id ? String(plans[0].id) : "");
  const [amountRm, setAmountRm] = useState(plans[0] ? toRm(plans[0].fee_sen) : "");
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const idempotencyKeyRef = useRef<string | null>(null);
  if (idempotencyKeyRef.current === null) {
    idempotencyKeyRef.current = crypto.randomUUID();
  }

  const selectedPlan = plans.find((p) => String(p.id) === planId);

  function handlePlanChange(next: string) {
    setPlanId(next);
    const plan = plans.find((p) => String(p.id) === next);
    if (plan) setAmountRm(toRm(plan.fee_sen));
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);

    if (!selectedPlan) {
      setError("Pick a tier first.");
      return;
    }

    const amountSen = Math.round(parseFloat(amountRm) * 100);
    if (!Number.isFinite(amountSen) || amountSen < 0) {
      setError("Enter a valid amount.");
      return;
    }

    setSubmitting(true);
    try {
      await onSubmit({
        email,
        membership_plan_id: selectedPlan.id,
        amount_sen: amountSen,
        reason: reason || null,
        idempotency_key: idempotencyKeyRef.current!,
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      <p className="mb-5 text-sm text-gray-500 dark:text-gray-400">
        Record a membership-fee payment received. Activates a new membership, or renews/reactivates an existing one.
      </p>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label htmlFor="membership_email">Member Email</Label>
          <Input id="membership_email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
        </div>
        <div>
          <Label htmlFor="membership_plan">Tier</Label>
          <SimpleSelect
            id="membership_plan"
            options={plans.map((p) => ({ value: String(p.id), label: p.name }))}
            value={planId}
            onChange={handlePlanChange}
          />
        </div>
        <div>
          <Label htmlFor="membership_amount">Amount (RM)</Label>
          <Input id="membership_amount" type="text" value={amountRm} onChange={(e) => setAmountRm(e.target.value)} placeholder="0.00" required />
          {selectedPlan && (
            <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
              This tier&apos;s fee is RM{toRm(selectedPlan.fee_sen)} — adjust only for partial/promo/waived payments.
            </p>
          )}
        </div>
        <div>
          <Label htmlFor="membership_reason">Reason (required if amount differs from the tier fee)</Label>
          <Input id="membership_reason" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. promo discount, founder waived" />
        </div>

        <div className="flex items-center justify-end gap-3 pt-2">
          <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" disabled={submitting}>
            {submitting ? "Recording…" : "Record Payment"}
          </Button>
        </div>
      </form>
    </>
  );
}

export default function RecordPaymentModal({ isOpen, onClose, plans, presetEmail, onSubmit }: RecordPaymentModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-md">
            <DialogHeader>
              <DialogTitle>Record Payment</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && (
                <RecordPaymentFields onClose={onClose} plans={plans} presetEmail={presetEmail} onSubmit={onSubmit} />
              )}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
