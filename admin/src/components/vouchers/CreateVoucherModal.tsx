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
import { Button } from "@/components/ui/button";
import { SimpleSelect } from "@/components/ui/select";
import type { CreateVoucherValues } from "@/lib/vouchers";

/** ADR-060 PR-4d: the storefront brands a voucher can be scoped to. */
export interface VoucherBrandOption {
  id: number;
  business_name: string;
  is_primary: boolean;
}

interface CreateVoucherModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (values: CreateVoucherValues) => Promise<void>;
  brands: VoucherBrandOption[];
}

/**
 * Renders as a child of <Modal>, which unmounts while closed — same
 * fresh-mount-per-open reasoning as AdminUserFormFields / WithdrawalRequestFields.
 */
function CreateVoucherFields({ onClose, onSubmit, brands }: Omit<CreateVoucherModalProps, "isOpen">) {
  const [customerEmail, setCustomerEmail] = useState("");
  const [amountRm, setAmountRm] = useState("");
  const [reason, setReason] = useState("");
  const [expiresAt, setExpiresAt] = useState("");
  // Default to the primary brand (ADR-060 PR-4d decision 5).
  const [affiliateId, setAffiliateId] = useState(
    String(brands.find((b) => b.is_primary)?.id ?? brands[0]?.id ?? ""),
  );
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

    if (!affiliateId) {
      setError("Select a brand.");
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
        affiliate_id: Number(affiliateId),
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
        Standalone voucher — debited from the platform balance. Vouchers above the maker-checker
        threshold require a Super Admin.
      </p>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        {brands.length > 1 && (
          <div>
            <Label htmlFor="voucher_brand">Brand</Label>
            <SimpleSelect
              id="voucher_brand"
              options={brands.map((b) => ({ value: String(b.id), label: b.business_name }))}
              value={affiliateId}
              onChange={setAffiliateId}
            />
            <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
              The voucher is redeemable only on this brand&apos;s storefront.
            </p>
          </div>
        )}
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
          <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" disabled={submitting}>
            {submitting ? "Creating…" : "Create Voucher"}
          </Button>
        </div>
      </form>
    </>
  );
}

export default function CreateVoucherModal({ isOpen, onClose, onSubmit, brands }: CreateVoucherModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-md">
            <DialogHeader>
              <DialogTitle>Create Voucher</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && <CreateVoucherFields onClose={onClose} onSubmit={onSubmit} brands={brands} />}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
