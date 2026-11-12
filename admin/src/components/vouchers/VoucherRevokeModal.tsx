"use client";

/**
 * ADR-004 2026-11-12 addendum, decision 8: revoking gift credit is a
 * confirmed step with a reason. The unspent balance becomes income
 * (breakage) of this month. A failed order's refund can't be revoked.
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
import { Textarea } from "@/components/ui/textarea";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api-client";
import { notify } from "@/components/ui/toast";
import { revokeVoucher, type Voucher } from "@/lib/vouchers";

interface Props {
  voucher: Voucher | null;
  onClose: () => void;
  onRevoked: () => void;
}

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

function Content({ voucher, onRevoked }: { voucher: Voucher; onRevoked: () => void }) {
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await revokeVoucher(voucher.id, reason.trim());
      notify.success("Voucher revoked", voucher.code);
      onRevoked();
    } catch (err) {
      const message = err instanceof ApiError ? err.message : "Could not revoke voucher.";
      setError(message);
      notify.stickyError("Failed to revoke voucher", message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <p className="text-sm text-gray-600 dark:text-gray-300">
        <span className="font-medium">{voucher.code}</span> ({voucher.customer_email}) still has{" "}
        <span className="font-medium">{formatRm(voucher.remaining)}</span>. Revoking it cancels that balance for good, and the books count it as
        this month&apos;s income. This cannot be undone.
      </p>
      {error && (
        <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}
      <div>
        <Label htmlFor="revoke_reason">Reason</Label>
        <Textarea id="revoke_reason" value={reason} onChange={(e) => setReason(e.target.value)} rows={2} maxLength={500} required />
      </div>
      <DialogFooter>
        <Button type="submit" severity="danger" disabled={submitting || reason.trim().length < 3}>
          {submitting ? "Revoking…" : `Revoke ${formatRm(voucher.remaining)}`}
        </Button>
      </DialogFooter>
    </form>
  );
}

export default function VoucherRevokeModal({ voucher, onClose, onRevoked }: Props) {
  return (
    <Dialog open={voucher !== null} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-md">
            <DialogHeader>
              <DialogTitle>Revoke {voucher?.code}?</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>{voucher && <Content key={voucher.id} voucher={voucher} onRevoked={onRevoked} />}</DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
