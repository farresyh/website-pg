"use client";

/**
 * ADR-131 decisions 12-16: close a reseller account. The refund goes out by bank transfer first,
 * to the account the money came from; this records it. The one way to remove a reseller.
 */

import React, { useEffect, useState } from "react";
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
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api-client";
import { notify } from "@/components/ui/toast";
import { closeReseller, getResellerCloseCheck, type ResellerCloseCheck, type ResellerRow } from "@/lib/resellers";

interface Props {
  isOpen: boolean;
  onClose: () => void;
  reseller: ResellerRow;
  onClosed: () => void;
}

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

/** Malaysia's calendar day, as the backend reads it (the closure is dated by it). */
function malaysiaToday(): string {
  return new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Kuala_Lumpur" }).format(new Date());
}

/** Every validation sentence the 422 carries (blockers sit under `reseller`). */
function messagesOf(err: unknown): string[] {
  if (!(err instanceof ApiError)) return ["Something went wrong."];
  const errors = err.payload?.errors as Record<string, string[]> | undefined;
  const all = errors ? Object.values(errors).flat() : [];
  return all.length > 0 ? all : [err.message];
}

function Content({ reseller, onClosed }: Omit<Props, "isOpen" | "onClose">) {
  const today = malaysiaToday();
  const [check, setCheck] = useState<ResellerCloseCheck | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [transferredOn, setTransferredOn] = useState(today);
  const [bankReference, setBankReference] = useState("");
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    getResellerCloseCheck(reseller.id)
      .then(setCheck)
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not check this account."));
  }, [reseller.id]);

  async function handleClose(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const result = await closeReseller(reseller.id, { transferred_on: transferredOn, bank_reference: bankReference.trim() });
      notify.success("Account closed", `${reseller.business_name} · refunded ${formatRm(result.refunded_sen)}`);
      onClosed();
    } catch (err) {
      const messages = messagesOf(err);
      setError(messages.join(" "));
      notify.stickyError("Could not close the account", messages[0]);
    } finally {
      setSubmitting(false);
    }
  }

  if (!check) {
    return <p className="text-sm text-gray-500 dark:text-gray-400">{error ?? "Checking…"}</p>;
  }

  const blocked = check.blockers.length > 0;
  const hasBalance = check.balance_sen > 0;

  return (
    <form onSubmit={handleClose} className="space-y-4">
      <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-white/[0.02]">
        <p className="text-theme-xs text-gray-500 dark:text-gray-400">Balance to refund</p>
        <p className="text-2xl font-semibold text-gray-800 dark:text-white/90">{formatRm(check.balance_sen)}</p>
      </div>

      {blocked ? (
        <div className="rounded-lg bg-warning-50 px-3 py-2 text-sm text-warning-600 dark:bg-warning-500/15 dark:text-warning-400">
          <p className="font-medium">This account cannot be closed yet:</p>
          <ul className="mt-1 list-disc ps-5">
            {check.blockers.map((b) => (
              <li key={b}>{b}</li>
            ))}
          </ul>
        </div>
      ) : (
        <p className="text-sm text-gray-600 dark:text-gray-300">
          {hasBalance
            ? `Send ${formatRm(check.balance_sen)} by bank transfer to the account the money came from FIRST, then record it here. `
            : "There is no balance to refund. "}
          Closing cancels API keys and portal logins, unlinks WhatsApp groups and turns off the webhook. This cannot be undone.
        </p>
      )}

      {error && (
        <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      {!blocked && (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <Label htmlFor="close_date">{hasBalance ? "Date the refund was sent" : "Date of closure"}</Label>
            <Input
              id="close_date"
              type="date"
              value={transferredOn}
              min={`${today.slice(0, 7)}-01`}
              max={today}
              onChange={(e) => setTransferredOn(e.target.value)}
              required
            />
          </div>
          <div>
            <Label htmlFor="close_ref">Bank reference</Label>
            <Input id="close_ref" value={bankReference} onChange={(e) => setBankReference(e.target.value)} placeholder="Transfer reference" maxLength={100} required />
          </div>
        </div>
      )}

      <DialogFooter>
        <Button type="submit" severity="danger" disabled={blocked || submitting || transferredOn === "" || bankReference.trim().length < 3}>
          {submitting ? "Closing…" : "Close account"}
        </Button>
      </DialogFooter>
    </form>
  );
}

export default function ResellerCloseModal({ isOpen, onClose, reseller, onClosed }: Props) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-lg">
            <DialogHeader>
              <DialogTitle>Close {reseller.business_name}?</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && <Content key={reseller.id} reseller={reseller} onClosed={onClosed} />}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
