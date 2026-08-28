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
import { CloseIcon } from "@/icons";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import { Button } from "@/components/ui/button";
import type { MergeVouchersValues, Voucher } from "@/lib/vouchers";

interface MergeVouchersModalProps {
  isOpen: boolean;
  vouchers: Voucher[];
  onClose: () => void;
  onSubmit: (values: MergeVouchersValues) => Promise<void>;
}

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

/**
 * ADR-036 — mints one brand-new voucher, voids the selected sources
 * (never deletes them). No maker-checker gate: the mandatory `reason`
 * field below is the audit control (decision 7).
 */
function MergeVouchersFields({ vouchers, onClose, onSubmit }: Omit<MergeVouchersModalProps, "isOpen">) {
  const [reason, setReason] = useState("");
  const [expiresAt, setExpiresAt] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const totalRemaining = vouchers.reduce((sum, v) => sum + v.remaining, 0);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await onSubmit({
        voucher_ids: vouchers.map((v) => v.id),
        reason,
        expires_at: expiresAt || null,
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
        Mints one new code worth the combined remaining balance; the {vouchers.length} selected vouchers
        are voided, not deleted. No new ledger liability is created.
      </p>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <div className="mb-4 rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
        <ul className="space-y-1 text-theme-sm text-gray-700 dark:text-gray-300">
          {vouchers.map((v) => (
            <li key={v.id} className="flex items-center justify-between">
              <span>{v.code}</span>
              <span>{formatRm(v.remaining)}</span>
            </li>
          ))}
        </ul>
        <div className="mt-2 flex items-center justify-between border-t border-gray-200 pt-2 text-theme-sm font-semibold text-gray-800 dark:border-gray-700 dark:text-white/90">
          <span>New voucher amount</span>
          <span>{formatRm(totalRemaining)}</span>
        </div>
      </div>

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label htmlFor="reason">Reason (required — the audit trail for this merge)</Label>
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
            {submitting ? "Merging…" : "Merge Vouchers"}
          </Button>
        </div>
      </form>
    </>
  );
}

export default function MergeVouchersModal({ isOpen, vouchers, onClose, onSubmit }: MergeVouchersModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-md">
            <DialogHeader>
              <DialogTitle>Merge Vouchers</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && <MergeVouchersFields vouchers={vouchers} onClose={onClose} onSubmit={onSubmit} />}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
