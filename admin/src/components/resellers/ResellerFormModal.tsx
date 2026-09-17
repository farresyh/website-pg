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
import { SimpleSelect } from "@/components/ui/select";
import { Button } from "@/components/ui/button";
import type { ResellerRow, ResellerTier } from "@/lib/resellers";

export interface ResellerFormSubmitValues {
  business_name: string;
  contact_name: string | null;
  email: string | null;
  phone: string | null;
  reseller_tier_id: number | null;
  notes: string | null;
}

interface Props {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (values: ResellerFormSubmitValues) => Promise<void>;
  editing: ResellerRow | null;
  tiers: ResellerTier[];
}

function Fields({ onClose, onSubmit, editing, tiers }: Omit<Props, "isOpen">) {
  const isEditing = editing !== null;

  const [businessName, setBusinessName] = useState(editing?.business_name ?? "");
  const [contactName, setContactName] = useState(editing?.contact_name ?? "");
  const [email, setEmail] = useState(editing?.email ?? "");
  const [phone, setPhone] = useState(editing?.phone ?? "");
  const [tierId, setTierId] = useState<string>(editing?.reseller_tier_id ? String(editing.reseller_tier_id) : "");
  const [notes, setNotes] = useState(editing?.notes ?? "");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);

    setSubmitting(true);
    try {
      await onSubmit({
        business_name: businessName,
        contact_name: contactName || null,
        email: email || null,
        phone: phone || null,
        reseller_tier_id: tierId === "" ? null : Number(tierId),
        notes: notes || null,
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  const tierOptions = [
    { value: "", label: "— No tier yet —" },
    ...tiers
      .filter((t) => t.is_active)
      .map((t) => ({ value: String(t.id), label: `${t.name} (+${t.markup_percent}% over cost)` })),
  ];

  return (
    <>
      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label htmlFor="business_name">Business name</Label>
            <Input id="business_name" value={businessName} onChange={(e) => setBusinessName(e.target.value)} required />
          </div>
          <div>
            <Label htmlFor="contact_name">Contact name</Label>
            <Input id="contact_name" value={contactName} onChange={(e) => setContactName(e.target.value)} />
          </div>
          <div>
            <Label htmlFor="email">Contact email</Label>
            <Input id="email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
          </div>
          <div>
            <Label htmlFor="phone">Phone</Label>
            <Input id="phone" value={phone} onChange={(e) => setPhone(e.target.value)} />
          </div>
        </div>

        {!isEditing && (
          <div>
            <Label htmlFor="reseller_tier_id">Wallet tier</Label>
            <SimpleSelect options={tierOptions} value={tierId} onChange={setTierId} />
            <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
              Markup % applied over cost price on every wallet order. Can be changed later.
            </p>
          </div>
        )}

        <div>
          <Label htmlFor="notes">Notes</Label>
          <textarea
            id="notes"
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            rows={2}
            className="w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-sm dark:border-gray-700"
          />
        </div>

        <div className="flex items-center justify-end gap-3 pt-2">
          <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" disabled={submitting}>
            {submitting ? "Saving…" : isEditing ? "Save Changes" : "Create Reseller"}
          </Button>
        </div>
      </form>
    </>
  );
}

export default function ResellerFormModal({ isOpen, onClose, onSubmit, editing, tiers }: Props) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-2xl">
            <DialogHeader>
              <DialogTitle>{editing !== null ? `Edit ${editing.business_name}` : "Add Reseller"}</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && (
                <Fields
                  key={editing?.id ?? "new"}
                  onClose={onClose}
                  onSubmit={onSubmit}
                  editing={editing}
                  tiers={tiers}
                />
              )}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
