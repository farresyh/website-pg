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
import { Button } from "@/components/ui/button";
import type { ResellerTier, ResellerTierValues } from "@/lib/resellers";

interface Props {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (values: ResellerTierValues) => Promise<void>;
  editing: ResellerTier | null;
}

function Fields({ onClose, onSubmit, editing }: Omit<Props, "isOpen">) {
  const [name, setName] = useState(editing?.name ?? "");
  const [markup, setMarkup] = useState(editing?.markup_percent ?? "");
  const [isActive, setIsActive] = useState(editing?.is_active ?? true);
  const [sortOrder, setSortOrder] = useState(String(editing?.sort_order ?? 0));
  const [showOnPriceList, setShowOnPriceList] = useState(editing?.show_on_price_list ?? false);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);

    const markupPct = parseFloat(markup);
    const sort = parseInt(sortOrder, 10);

    if (!Number.isFinite(markupPct) || markupPct < 0) return setError("Enter a valid markup %.");

    setSubmitting(true);
    try {
      await onSubmit({
        name,
        markup_percent: markupPct,
        is_active: isActive,
        sort_order: Number.isFinite(sort) ? sort : 0,
        show_on_price_list: showOnPriceList,
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}
      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label htmlFor="tier_name">Name</Label>
          <Input id="tier_name" value={name} onChange={(e) => setName(e.target.value)} required />
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label htmlFor="tier_markup">Markup % over cost</Label>
            <Input id="tier_markup" value={markup} onChange={(e) => setMarkup(e.target.value)} placeholder="5" />
          </div>
          <div>
            <Label htmlFor="tier_sort">Sort order</Label>
            <Input id="tier_sort" value={sortOrder} onChange={(e) => setSortOrder(e.target.value)} />
          </div>
        </div>
        <label className="flex items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-300">
          <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
          Active (assignable to resellers)
        </label>
        <label className="flex items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-300">
          <input
            type="checkbox"
            checked={showOnPriceList}
            onChange={(e) => setShowOnPriceList(e.target.checked)}
          />
          Show on the public Reseller Price List
        </label>
        <p className="-mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
          Up to 3 tiers can be shown at once. Column order follows Sort order above.
        </p>

        <div className="flex items-center justify-end gap-3 pt-2">
          <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" disabled={submitting}>
            {submitting ? "Saving…" : editing ? "Save Tier" : "Create Tier"}
          </Button>
        </div>
      </form>
    </>
  );
}

export default function ResellerTierFormModal({ isOpen, onClose, onSubmit, editing }: Props) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-lg">
            <DialogHeader>
              <DialogTitle>{editing ? `Edit ${editing.name}` : "Add Wallet Tier"}</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && <Fields key={editing?.id ?? "new"} onClose={onClose} onSubmit={onSubmit} editing={editing} />}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
