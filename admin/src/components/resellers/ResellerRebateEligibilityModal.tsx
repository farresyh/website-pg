"use client";

/**
 * ADR-139 decision 36: take a reseller out of the rebate programme (an
 * internal or test account) or put it back. Behind the MFA step-up, which
 * the api client asks for; the reason is recorded with the change. Out of
 * the programme, the account earns nothing and cannot claim; rewards it
 * already has lapse on their own date unless it is put back in time.
 */

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
  DialogFooter,
} from "@/components/ui/dialog";
import { Times as CloseIcon } from "@primeicons/react/times";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { ApiError } from "@/lib/api-client";
import { notify } from "@/components/ui/toast";
import { setRebateEligibility } from "@/lib/rebate";
import type { ResellerRow } from "@/lib/resellers";

interface Props {
  isOpen: boolean;
  onClose: () => void;
  reseller: ResellerRow;
  onChanged: () => void;
}

function Content({ reseller, onChanged }: Omit<Props, "isOpen" | "onClose">) {
  const [reason, setReason] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const goingOut = reseller.rebate_eligible;

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSaving(true);
    try {
      await setRebateEligibility(reseller.id, !goingOut, reason.trim());
      notify.success(goingOut ? "Taken out of the rebate programme" : "Put back into the rebate programme", reseller.business_name);
      onChanged();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not change the rebate programme.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit}>
      <div className="space-y-4">
        <p className="text-theme-sm text-ink-muted">
          {goingOut
            ? "Out of the programme, this account earns no rebate and cannot claim. Rewards it already has lapse on their own date unless it is put back before then."
            : "Back in the programme, this account earns rebate on the ladder from now, for the whole of this month."}
        </p>
        <div>
          <Label htmlFor="rebate_reason">Reason (recorded with the change)</Label>
          <Textarea id="rebate_reason" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} required minLength={3} maxLength={500} />
        </div>
        {error && <p className="rounded-lg bg-danger-surface px-3 py-2 text-theme-sm text-danger-ink">{error}</p>}
      </div>
      <DialogFooter>
        <Button type="submit" severity={goingOut ? "danger" : undefined} disabled={saving || reason.trim().length < 3}>
          {saving ? "Saving…" : goingOut ? "Take out of the programme" : "Put back into the programme"}
        </Button>
      </DialogFooter>
    </form>
  );
}

export default function ResellerRebateEligibilityModal({ isOpen, onClose, reseller, onChanged }: Props) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-lg">
            <DialogHeader>
              <DialogTitle>Rebate programme: {reseller.business_name}</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>{isOpen && <Content key={reseller.id} reseller={reseller} onChanged={onChanged} />}</DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
