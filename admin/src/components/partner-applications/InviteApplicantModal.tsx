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
import { SimpleSelect } from "@/components/ui/select";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api-client";
import { inviteApplicant, ORDER_CHANNEL_LABELS, type ApplicantType, type ApplicationDetail } from "@/lib/partner-applications";

/**
 * ADR-133 decision 6 (and its 2026-11-01 addendum): Invite directly replaces the old "Add Reseller".
 * Someone the founder knows skips the screening and gets the stage-2 link at once; the documents
 * then arrive through the same form as everyone else's, and approval makes the account.
 */
function Fields({ onClose, onInvited }: { onClose: () => void; onInvited: (application: ApplicationDetail) => void }) {
  const [type, setType] = useState<ApplicantType>("company");
  const [ownerName, setOwnerName] = useState("");
  const [businessName, setBusinessName] = useState("");
  const [email, setEmail] = useState("");
  const [phone, setPhone] = useState("");
  const [channel, setChannel] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      onInvited(
        await inviteApplicant({
          applicant_type: type,
          owner_name: ownerName.trim(),
          business_name: businessName.trim() || null,
          email: email.trim(),
          phone: phone.trim(),
          order_channel: channel || null,
        }),
      );
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={submit} className="space-y-4">
      {error && <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>}
      <div>
        <Label htmlFor="invite_type">Applying as</Label>
        <SimpleSelect
          options={[
            { value: "company", label: "A company (SSM)" },
            { value: "individual", label: "An individual" },
          ]}
          value={type}
          onChange={(v) => setType(v as ApplicantType)}
        />
      </div>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <Label htmlFor="invite_owner">Owner name</Label>
          <Input id="invite_owner" value={ownerName} onChange={(e) => setOwnerName(e.target.value)} required />
        </div>
        <div>
          <Label htmlFor="invite_business">{type === "company" ? "Company name" : "Business name (optional)"}</Label>
          <Input id="invite_business" value={businessName} onChange={(e) => setBusinessName(e.target.value)} required={type === "company"} />
        </div>
        <div>
          <Label htmlFor="invite_email">Email</Label>
          <Input id="invite_email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
        </div>
        <div>
          <Label htmlFor="invite_phone">Phone</Label>
          <Input id="invite_phone" value={phone} onChange={(e) => setPhone(e.target.value)} required />
        </div>
      </div>
      <div>
        <Label htmlFor="invite_channel">How they plan to order (optional)</Label>
        <SimpleSelect
          options={[{ value: "", label: "Not known" }, ...Object.entries(ORDER_CHANNEL_LABELS).map(([value, label]) => ({ value, label }))]}
          value={channel}
          onChange={setChannel}
        />
      </div>
      <p className="text-theme-xs text-ink-muted">
        We email them a link to send their address and documents (valid 14 days). You approve the account, with a tier, once
        the documents are in.
      </p>
      <div className="flex items-center justify-end gap-3 pt-2">
        <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>
          Cancel
        </Button>
        <Button type="submit" disabled={submitting}>
          {submitting ? "Sending…" : "Send invite"}
        </Button>
      </div>
    </form>
  );
}

export default function InviteApplicantModal({ isOpen, onClose, onInvited }: { isOpen: boolean; onClose: () => void; onInvited: (application: ApplicationDetail) => void }) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-2xl">
            <DialogHeader>
              <DialogTitle>Invite a reseller directly</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>{isOpen && <Fields onClose={onClose} onInvited={onInvited} />}</DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
