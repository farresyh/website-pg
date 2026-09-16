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
import type { GamePackage, CreateComboPackageValues } from "@/lib/games";

const MAX_TOTAL_LEGS = 5; // StoreComboPackageRequest::MAX_TOTAL_LEGS — client mirror, server is the real cap.

interface ComboRow {
  packageId: number;
  quantity: number;
}

interface CreateComboModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (values: CreateComboPackageValues) => Promise<void>;
  /** This game's own currently-loaded package list — eligible components are filtered down from it, no extra fetch. */
  packages: GamePackage[];
}

/**
 * ADR-094 decisions 1-4: assembles a combo from up to 5 total legs
 * (sum of quantities, decision 20's fix, raised from 3 in the
 * 2026-09-16 addendum) worth of this game's own already-promoted,
 * denominated, non-combo packages. Same-supplier-only and
 * no-nested-combo are enforced server-side (StoreComboPackageRequest)
 * — this form doesn't duplicate that client-side, it just surfaces
 * whatever the server rejects. Each option shows the component's own
 * supplier SKU (2026-09-16 addendum) — two packages can share a
 * denomination across suppliers, so name+denomination alone doesn't
 * tell admin which product is actually being picked.
 */
function CreateComboFields({ onClose, onSubmit, packages }: Omit<CreateComboModalProps, "isOpen">) {
  const [name, setName] = useState("");
  const [rows, setRows] = useState<ComboRow[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const eligible = packages.filter((p) => !p.is_combo && p.is_active && p.denomination !== null);
  const totalLegs = rows.reduce((sum, r) => sum + r.quantity, 0);

  function addRow() {
    const remaining = eligible.filter((p) => !rows.some((r) => r.packageId === p.id));
    if (remaining.length === 0) return;
    setRows((prev) => [...prev, { packageId: remaining[0].id, quantity: 1 }]);
  }

  function updateRow(index: number, patch: Partial<ComboRow>) {
    setRows((prev) => prev.map((r, i) => (i === index ? { ...r, ...patch } : r)));
  }

  function removeRow(index: number) {
    setRows((prev) => prev.filter((_, i) => i !== index));
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);

    if (rows.length === 0) {
      setError("Add at least one component.");
      return;
    }

    setSubmitting(true);
    try {
      await onSubmit({
        name,
        components: rows.map((r) => ({ package_id: r.packageId, quantity: r.quantity })),
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <p className="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">
        Assembles several existing packages into one checkout above this game&apos;s native denomination — the
        customer/reseller pays one CHIP FPX fee instead of several. Components must share the same supplier; the
        combo is fully opaque to the customer and reseller (ADR-094).
      </p>

      {error && (
        <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <div>
        <Label htmlFor="combo_name">Final Package Name (shown to customers)</Label>
        <Input id="combo_name" value={name} onChange={(e) => setName(e.target.value)} required />
      </div>

      <div>
        <div className="mb-2 flex items-center justify-between">
          <Label>Components</Label>
          <span className={`text-theme-xs ${totalLegs > MAX_TOTAL_LEGS ? "text-error-600 dark:text-error-400" : "text-gray-400"}`}>
            {totalLegs}/{MAX_TOTAL_LEGS} legs
          </span>
        </div>

        <div className="space-y-2">
          {rows.map((row, index) => {
            const pkg = eligible.find((p) => p.id === row.packageId);
            const pickable = eligible.filter((p) => p.id === row.packageId || !rows.some((r) => r.packageId === p.id));

            return (
              <div key={index} className="flex items-center gap-2">
                <select
                  value={row.packageId}
                  onChange={(e) => updateRow(index, { packageId: Number(e.target.value) })}
                  className="h-9 flex-1 rounded-lg border border-gray-300 bg-transparent px-2 text-sm dark:border-gray-700 dark:text-white/90"
                >
                  {pickable.map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.name} ({p.denomination}) — {p.supplier_package_ref}
                    </option>
                  ))}
                </select>
                <input
                  type="number"
                  min={1}
                  max={MAX_TOTAL_LEGS}
                  value={row.quantity}
                  onChange={(e) => updateRow(index, { quantity: Math.max(1, parseInt(e.target.value, 10) || 1) })}
                  className="h-9 w-16 rounded-lg border border-gray-300 px-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
                />
                <Button type="button" size="small" severity="danger" variant="outlined" onClick={() => removeRow(index)}>
                  Remove
                </Button>
                {pkg === undefined && (
                  <span className="text-theme-xs text-error-600 dark:text-error-400">no longer eligible</span>
                )}
              </div>
            );
          })}
        </div>

        {rows.length < eligible.length && (
          <Button type="button" size="small" variant="outlined" onClick={addRow} className="mt-2">
            + Add Component
          </Button>
        )}
        {eligible.length === 0 && (
          <p className="mt-2 text-theme-xs text-gray-400">
            No active, denominated packages on this game yet — promote some via Product Manager first.
          </p>
        )}
      </div>

      <div className="flex items-center justify-end gap-3 pt-2">
        <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>
          Cancel
        </Button>
        <Button type="submit" disabled={submitting || rows.length === 0}>
          {submitting ? "Creating…" : "Create Combo"}
        </Button>
      </div>
    </form>
  );
}

export default function CreateComboModal({ isOpen, onClose, onSubmit, packages }: CreateComboModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-lg">
            <DialogHeader>
              <DialogTitle>Create Combo Package</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && <CreateComboFields onClose={onClose} onSubmit={onSubmit} packages={packages} />}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
