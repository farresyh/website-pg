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
  markup_pct: number;
  max_markup_pct: number | null;
  domains: string[];
  notes: string | null;
  is_owned: boolean;
  membership_enabled: boolean;
  tier_id: number | null;
  user_name: string;
  user_email: string;
}

interface Props {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (values: ResellerFormSubmitValues) => Promise<void>;
  editing: ResellerRow | null;
  tiers: ResellerTier[];
}

function toLines(domains: string[]): string {
  return domains.join("\n");
}

function fromLines(text: string): string[] {
  return text
    .split(/[\n,]/)
    .map((d) => d.trim())
    .filter(Boolean);
}

function Fields({ onClose, onSubmit, editing, tiers }: Omit<Props, "isOpen">) {
  const isEditing = editing !== null;

  const [businessName, setBusinessName] = useState(editing?.business_name ?? "");
  const [contactName, setContactName] = useState(editing?.contact_name ?? "");
  const [email, setEmail] = useState(editing?.email ?? "");
  const [phone, setPhone] = useState(editing?.phone ?? "");
  const [markupPct, setMarkupPct] = useState(editing?.markup_pct ?? "0");
  const [maxMarkupPct, setMaxMarkupPct] = useState(editing?.max_markup_pct ?? "");
  const [domainsText, setDomainsText] = useState(toLines(editing?.domains ?? []));
  const [notes, setNotes] = useState(editing?.notes ?? "");
  const [isOwned, setIsOwned] = useState(editing?.is_owned ?? false);
  const [membershipEnabled, setMembershipEnabled] = useState(editing?.membership_enabled ?? false);
  const [tierId, setTierId] = useState<string>("");
  const [userName, setUserName] = useState("");
  const [userEmail, setUserEmail] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);

    const markup = parseFloat(markupPct);
    const maxMarkup = maxMarkupPct.trim() === "" ? null : parseFloat(maxMarkupPct);

    if (!Number.isFinite(markup) || markup < 0) {
      setError("Enter a valid markup %.");
      return;
    }
    if (maxMarkup !== null && (!Number.isFinite(maxMarkup) || maxMarkup < markup)) {
      setError("Max markup % must be a number no lower than markup %.");
      return;
    }

    setSubmitting(true);
    try {
      await onSubmit({
        business_name: businessName,
        contact_name: contactName || null,
        email: email || null,
        phone: phone || null,
        markup_pct: markup,
        max_markup_pct: maxMarkup,
        domains: fromLines(domainsText),
        notes: notes || null,
        is_owned: isOwned,
        // A third-party reseller can never carry consumer Membership
        // (ADR-061) — the backend forces this off too, this just keeps
        // the payload honest.
        membership_enabled: isOwned && membershipEnabled,
        tier_id: tierId === "" ? null : Number(tierId),
        user_name: userName,
        user_email: userEmail,
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  const tierOptions = [
    { value: "", label: "— No tier (walk-in wholesale rate) —" },
    ...tiers
      .filter((t) => t.is_active)
      .map((t) => ({ value: String(t.id), label: `${t.name} (RM${(t.monthly_fee_sen / 100).toFixed(2)}/mo, +${t.markup_percent}%)` })),
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
          <div>
            <Label htmlFor="markup_pct">Reseller markup %</Label>
            <Input id="markup_pct" value={markupPct} onChange={(e) => setMarkupPct(e.target.value)} hint="Their own margin on top of the wholesale price." />
          </div>
          <div>
            <Label htmlFor="max_markup_pct">Max markup % (ceiling)</Label>
            <Input id="max_markup_pct" value={maxMarkupPct} onChange={(e) => setMaxMarkupPct(e.target.value)} hint="Leave blank for no ceiling." />
          </div>
        </div>

        <div>
          <Label htmlFor="domains">Domains</Label>
          <textarea
            id="domains"
            value={domainsText}
            onChange={(e) => setDomainsText(e.target.value)}
            rows={2}
            placeholder="shop.example.com"
            className="w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-sm dark:border-gray-700"
          />
          <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
            One per line. Cloudflare custom-hostname routing is wired in ADR-060 — this only stores the names for now.
          </p>
        </div>

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

        <div className="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
          <label className="flex items-center gap-2 text-theme-sm font-medium text-gray-700 dark:text-gray-300">
            <input
              type="checkbox"
              checked={isOwned}
              onChange={(e) => {
                const next = e.target.checked;
                setIsOwned(next);
                if (next && (markupPct.trim() === "" || parseFloat(markupPct) === 0)) {
                  setMarkupPct("0");
                }
                if (!next) setMembershipEnabled(false);
              }}
            />
            Our own brand
          </label>
          <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
            An internal storefront — its margin books as our own money, and it may run consumer Membership. Leave off for a third-party reseller.
          </p>
          {isOwned && (
            <label className="mt-3 flex items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-300">
              <input type="checkbox" checked={membershipEnabled} onChange={(e) => setMembershipEnabled(e.target.checked)} />
              Enable consumer Membership on this storefront
              <span className="text-theme-xs text-gray-400">(also needs the global membership switch on)</span>
            </label>
          )}
        </div>

        {!isEditing && (
          <div className="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
            <p className="mb-3 text-theme-xs font-medium text-gray-600 dark:text-gray-400">
              Initial wholesale tier + first portal login (they receive a set-password invite by email).
            </p>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="sm:col-span-2">
                <Label htmlFor="tier_id">Wholesale tier</Label>
                <SimpleSelect options={tierOptions} value={tierId} onChange={setTierId} />
              </div>
              <div>
                <Label htmlFor="user_name">Portal user name</Label>
                <Input id="user_name" value={userName} onChange={(e) => setUserName(e.target.value)} required />
              </div>
              <div>
                <Label htmlFor="user_email">Portal user email</Label>
                <Input id="user_email" type="email" value={userEmail} onChange={(e) => setUserEmail(e.target.value)} required />
              </div>
            </div>
          </div>
        )}

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
