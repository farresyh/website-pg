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
import type { GamePackage, UpdatePackageValues } from "@/lib/games";

interface EditPackageModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (values: UpdatePackageValues) => Promise<void>;
  onDelete: () => Promise<void>;
  pkg: GamePackage | null;
}

/**
 * Renders as a child of <Modal>, which unmounts while closed — same
 * fresh-mount-per-open reasoning as the other *FormFields components.
 * GAME-7. Rename + delete only — markup and active/inactive are
 * dedicated inline, per-row controls in the games page table now
 * (founder revision: matches the legacy reference system's own
 * layout, not bundled into a general edit form).
 */
function EditPackageFields({
  onClose,
  onSubmit,
  onDelete,
  pkg,
}: Omit<EditPackageModalProps, "isOpen" | "pkg"> & { pkg: GamePackage }) {
  const [name, setName] = useState(pkg.name);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [confirmingDelete, setConfirmingDelete] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await onSubmit({ name });
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
        <span className="text-gray-500 dark:text-gray-400">Supplier Cost (from Gamevion, not editable): </span>
        <span className="font-medium text-gray-800 dark:text-white/90">RM {(pkg.cost_price / 100).toFixed(2)}</span>
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
              {submitting ? "Deleting…" : "Delete Package"}
            </Button>
          </div>
        </div>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <Label htmlFor="pkg_name">Final Package Name (shown to customers)</Label>
            <Input id="pkg_name" value={name} onChange={(e) => setName(e.target.value)} required />
          </div>

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

export default function EditPackageModal({ isOpen, onClose, onSubmit, onDelete, pkg }: EditPackageModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-md">
            <DialogHeader>
              <DialogTitle>Edit Package</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && pkg && <EditPackageFields onClose={onClose} onSubmit={onSubmit} onDelete={onDelete} pkg={pkg} />}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
