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
import { Button } from "@/components/ui/button";
import type { GamePackage, UpdateComboOverrideValues } from "@/lib/games";

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

interface ComboOverrideModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (values: UpdateComboOverrideValues) => Promise<void>;
  onDelete: () => Promise<void>;
  pkg: GamePackage | null;
}

/**
 * ADR-094 decision 5's second half — a combo's composition itself is
 * immutable after creation (no decision recorded for editing it post-
 * creation; delete and recreate instead, same as every other Package
 * today). This modal is the combo-row counterpart to EditPackageModal
 * — read-only composition + the one thing that IS editable, its
 * pricing override.
 */
function ComboOverrideFields({
  onClose,
  onSubmit,
  onDelete,
  pkg,
}: Omit<ComboOverrideModalProps, "isOpen" | "pkg"> & { pkg: GamePackage }) {
  const [mode, setMode] = useState<"default" | "markup" | "price">(
    pkg.combo_override_price !== null ? "price" : pkg.combo_override_markup_percent !== null ? "markup" : "default",
  );
  const [markup, setMarkup] = useState(pkg.combo_override_markup_percent ?? "");
  const [price, setPrice] = useState(pkg.combo_override_price !== null ? (pkg.combo_override_price / 100).toFixed(2) : "");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [confirmingDelete, setConfirmingDelete] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await onSubmit({
        combo_override_markup_percent: mode === "markup" ? parseFloat(markup) : null,
        combo_override_price: mode === "price" ? Math.round(parseFloat(price) * 100) : null,
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleDelete() {
    setError(null);
    setSubmitting(true);
    try {
      await onDelete();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
      setSubmitting(false);
    }
  }

  return (
    <>
      <div className="mb-4 rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/5">
        <p className="mb-1.5 text-xs text-gray-500 dark:text-gray-400">Composition (fixed at creation):</p>
        <ul className="space-y-1 text-sm text-gray-700 dark:text-gray-300">
          {pkg.components.map((c) => (
            <li key={c.id} className="flex justify-between">
              <span>
                {c.name} {c.quantity > 1 ? `×${c.quantity}` : ""}
                {!c.is_active && <span className="ml-1 text-theme-xs text-error-600 dark:text-error-400">(inactive)</span>}
              </span>
              <span className="text-gray-500 dark:text-gray-400">{formatRm(c.standard_selling_price * c.quantity)}</span>
            </li>
          ))}
        </ul>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      {confirmingDelete ? (
        <div className="space-y-4">
          <p className="text-sm text-gray-700 dark:text-gray-300">
            Delete <span className="font-medium">{pkg.name}</span> from the catalog? This cannot be undone.
          </p>
          <div className="flex items-center justify-end gap-3">
            <Button type="button" variant="outlined" onClick={() => setConfirmingDelete(false)} disabled={submitting}>
              Cancel
            </Button>
            <Button type="button" severity="danger" onClick={handleDelete} disabled={submitting}>
              {submitting ? "Deleting…" : "Delete Combo"}
            </Button>
          </div>
        </div>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <Label>Pricing</Label>
            <div className="mb-2 flex gap-2">
              {(["default", "markup", "price"] as const).map((m) => (
                <button
                  key={m}
                  type="button"
                  onClick={() => setMode(m)}
                  className={`rounded-lg px-3 py-1.5 text-sm capitalize ${mode === m ? "bg-brand-500 text-white" : "bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400"}`}
                >
                  {m === "default" ? "Sum of components" : m === "markup" ? "Custom markup %" : "Custom price"}
                </button>
              ))}
            </div>

            {mode === "markup" && (
              <div className="relative">
                <Input type="text" value={markup} onChange={(e) => setMarkup(e.target.value)} placeholder="e.g. 15" required />
                <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">%</span>
              </div>
            )}
            {mode === "price" && (
              <div className="relative">
                <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">RM</span>
                <Input type="text" value={price} onChange={(e) => setPrice(e.target.value)} placeholder="e.g. 399.00" required className="pl-9" />
              </div>
            )}
            {mode === "default" && (
              <p className="text-theme-xs text-gray-400">Reverts to the literal sum of each component&apos;s own selling price.</p>
            )}
          </div>

          <p className="text-theme-xs text-gray-400">
            Current selling price: <span className="font-medium text-gray-700 dark:text-gray-300">{formatRm(pkg.standard_selling_price)}</span>
          </p>

          <div className="flex items-center justify-between pt-2">
            <Button type="button" severity="danger" onClick={() => setConfirmingDelete(true)} disabled={submitting}>
              Delete
            </Button>
            <div className="flex gap-3">
              <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>
                Cancel
              </Button>
              <Button type="submit" disabled={submitting}>
                {submitting ? "Saving…" : "Save"}
              </Button>
            </div>
          </div>
        </form>
      )}
    </>
  );
}

export default function ComboOverrideModal({ isOpen, onClose, onSubmit, onDelete, pkg }: ComboOverrideModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-md">
            <DialogHeader>
              <DialogTitle>Edit Combo Package</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && pkg && <ComboOverrideFields onClose={onClose} onSubmit={onSubmit} onDelete={onDelete} pkg={pkg} />}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
