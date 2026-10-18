"use client";

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
} from "@/components/ui/dialog";
import { Times as CloseIcon } from "@primeicons/react/times";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { SimpleSelect } from "@/components/ui/select";
import { Button } from "@/components/ui/button";
import {
  type CreateManualPackageValues,
  type GamePackage,
  type ManualSupplierOption,
  type PriceChange,
  getPackagePriceHistory,
  listManualSuppliers,
} from "@/lib/games";

/** "40.00" → 4000 sen; null when not a positive amount. */
function rmToSen(value: string): number | null {
  const amount = Number(value);
  return Number.isFinite(amount) && amount > 0 ? Math.round(amount * 100) : null;
}

const rm = (sen: number) => `RM ${(sen / 100).toFixed(2)}`;

function Shell({ open, title, onClose, children }: { open: boolean; title: string; onClose: () => void; children: React.ReactNode }) {
  return (
    <Dialog open={open} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-lg">
            <DialogHeader>
              <DialogTitle>{title}</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>{open && children}</DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}

/**
 * ADR-125 decision 4: a login game's package, priced from the cost agreed
 * with the manual supplier plus a markup, like any promoted package. Until
 * the supplier bot exists, use the highest cost the supplier may charge.
 */
function CreateFields({ onClose, onSubmit }: { onClose: () => void; onSubmit: (values: CreateManualPackageValues) => Promise<void> }) {
  const [suppliers, setSuppliers] = useState<ManualSupplierOption[] | null>(null);
  const [supplierId, setSupplierId] = useState("");
  const [name, setName] = useState("");
  const [cost, setCost] = useState("");
  const [markup, setMarkup] = useState("15");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    listManualSuppliers()
      .then((list) => {
        setSuppliers(list);
        if (list.length === 1) setSupplierId(String(list[0].id));
      })
      .catch(() => setError("Could not load manual suppliers."));
  }, []);

  const costSen = rmToSen(cost);
  const markupPct = Number(markup);
  const sellingSen = costSen !== null && Number.isFinite(markupPct) ? Math.round(costSen * (1 + markupPct / 100)) : null;

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    if (costSen === null || !supplierId) {
      setError("Choose a supplier and enter a cost above RM 0.");
      return;
    }
    setSubmitting(true);
    try {
      await onSubmit({ supplier_id: Number(supplierId), name, cost_price: costSen, markup_percent: markupPct });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      {error && <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>}
      <div>
        <Label htmlFor="manual_supplier">Manual supplier</Label>
        {suppliers?.length === 0 ? (
          <p className="text-sm text-gray-500 dark:text-gray-400">No active manual supplier yet. A Super Admin adds one in Middleware → Suppliers.</p>
        ) : (
          <SimpleSelect
            id="manual_supplier"
            value={supplierId}
            onChange={setSupplierId}
            options={[{ value: "", label: suppliers ? "Choose…" : "Loading…" }, ...(suppliers ?? []).map((s) => ({ value: String(s.id), label: s.name }))]}
          />
        )}
      </div>
      <div>
        <Label htmlFor="manual_name">Package name (shown to customers)</Label>
        <Input id="manual_name" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. 800 Robux" required />
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <Label htmlFor="manual_cost">Agreed cost (RM)</Label>
          <Input id="manual_cost" inputMode="decimal" value={cost} onChange={(e) => setCost(e.target.value)} placeholder="40.00" required />
        </div>
        <div>
          <Label htmlFor="manual_markup">Markup %</Label>
          <Input id="manual_markup" inputMode="decimal" value={markup} onChange={(e) => setMarkup(e.target.value)} required />
        </div>
      </div>
      <p className="text-sm text-gray-600 dark:text-gray-400">
        Selling price: <span className="font-medium text-gray-800 dark:text-white/90">{sellingSen !== null ? rm(sellingSen) : "—"}</span>
      </p>
      <div className="flex justify-end gap-3 pt-2">
        <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>Cancel</Button>
        <Button type="submit" disabled={submitting || suppliers?.length === 0}>{submitting ? "Adding…" : "Add Package"}</Button>
      </div>
    </form>
  );
}

/** ADR-125 decisions 34/45: an audited cost edit, with the package's full price history. */
function CostFields({ pkg, onClose, onSubmit }: { pkg: GamePackage; onClose: () => void; onSubmit: (costSen: number) => Promise<void> }) {
  const [cost, setCost] = useState((pkg.cost_price / 100).toFixed(2));
  const [history, setHistory] = useState<PriceChange[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    getPackagePriceHistory(pkg.id).then(setHistory).catch(() => setHistory([]));
  }, [pkg.id]);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const costSen = rmToSen(cost);
    if (costSen === null) {
      setError("Enter a cost above RM 0.");
      return;
    }
    setError(null);
    setSubmitting(true);
    try {
      await onSubmit(costSen);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="space-y-5">
      <form onSubmit={handleSubmit} className="space-y-4">
        {error && <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>}
        <div>
          <Label htmlFor="manual_cost_edit">Agreed cost (RM)</Label>
          <Input id="manual_cost_edit" inputMode="decimal" value={cost} onChange={(e) => setCost(e.target.value)} required />
          <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
            The selling price is recomputed from the markup ({pkg.markup_percent}%). Orders already placed keep their own cost.
          </p>
        </div>
        <div className="flex justify-end gap-3">
          <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>Cancel</Button>
          <Button type="submit" disabled={submitting}>{submitting ? "Saving…" : "Save Cost"}</Button>
        </div>
      </form>

      <div className="border-t border-gray-100 pt-4 dark:border-gray-800">
        <p className="mb-2 text-sm font-medium text-gray-800 dark:text-white/90">Price history</p>
        {history === null && <p className="text-sm text-gray-500">Loading…</p>}
        {history?.length === 0 && <p className="text-sm text-gray-500 dark:text-gray-400">No changes yet.</p>}
        <ul className="space-y-1.5 text-sm text-gray-600 dark:text-gray-400">
          {history?.map((h) => (
            <li key={h.id}>
              {new Date(h.created_at).toLocaleString()} — cost {rm(h.old_cost_price)} → {rm(h.new_cost_price)}, price{" "}
              {rm(h.old_standard_selling_price)} → {rm(h.new_standard_selling_price)} ·{" "}
              {h.admin_user?.name ?? (h.price_sync_run_id ? "Price Sync" : "—")}
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}

export function CreateManualPackageModal({ open, onClose, onSubmit }: { open: boolean; onClose: () => void; onSubmit: (values: CreateManualPackageValues) => Promise<void> }) {
  return (
    <Shell open={open} title="Add Manual Package" onClose={onClose}>
      <CreateFields onClose={onClose} onSubmit={onSubmit} />
    </Shell>
  );
}

export function ManualCostModal({ pkg, onClose, onSubmit }: { pkg: GamePackage | null; onClose: () => void; onSubmit: (costSen: number) => Promise<void> }) {
  return (
    <Shell open={pkg !== null} title={`Cost — ${pkg?.name ?? ""}`} onClose={onClose}>
      {pkg && <CostFields pkg={pkg} onClose={onClose} onSubmit={onSubmit} />}
    </Shell>
  );
}
