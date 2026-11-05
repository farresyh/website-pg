"use client";

import React, { useEffect, useMemo, useState } from "react";
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
import { SimpleSelect } from "@/components/ui/select";
import { Button } from "@/components/ui/button";
import { listGamePackages, type GamePackage } from "@/lib/games";
import type { SupplierProduct } from "@/lib/supplier-products";

interface RepointProductModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (packageId: number) => Promise<void>;
  product: SupplierProduct | null;
  gameId: number;
  gameName: string;
}

const rm = (sen: number | null) => (sen === null ? "unknown" : `RM ${(sen / 100).toFixed(2)}`);

/**
 * ADR-137 decision 21: when a supplier changes system, point one of this
 * game's existing packages from the same supplier at the new item instead of
 * adding a second package. The package keeps its name, markup, history and
 * combos; its cost becomes the new supplier price; it stays on or off.
 */
function RepointFields({
  onClose,
  onSubmit,
  product,
  gameId,
  gameName,
}: Omit<RepointProductModalProps, "isOpen" | "product"> & { product: SupplierProduct }) {
  const [packages, setPackages] = useState<GamePackage[] | null>(null);
  const [packageId, setPackageId] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    listGamePackages(gameId)
      .then((all) => setPackages(all.filter((p) => !p.is_combo && p.supplier_id === product.supplier_id)))
      .catch((err) => setError(err instanceof Error ? err.message : "Could not load packages."));
  }, [gameId, product.supplier_id]);

  const options = useMemo(
    () =>
      (packages ?? []).map((p) => ({
        value: String(p.id),
        label: `${p.name} (${p.supplier_package_ref}, ${p.is_active ? "on" : "off"}, cost ${rm(p.cost_price)})`,
      })),
    [packages],
  );
  const chosen = packages?.find((p) => String(p.id) === packageId) ?? null;

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!chosen) return;
    setError(null);
    setSubmitting(true);
    try {
      await onSubmit(chosen.id);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      <p className="mb-4 text-sm text-gray-500 dark:text-gray-400">
        {product.name} ({product.external_ref}) → an existing <span className="font-medium">{gameName}</span> package
      </p>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label htmlFor="repoint_package">Package to link</Label>
          {packages === null ? (
            <p className="text-sm text-ink-subtle">Loading packages…</p>
          ) : packages.length === 0 ? (
            <p className="text-sm text-ink-subtle">This game has no package from this supplier. Use Add to Catalog instead.</p>
          ) : (
            <SimpleSelect id="repoint_package" options={options} value={packageId} onChange={setPackageId} className="w-full" />
          )}
        </div>

        {chosen && (
          <div className="rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/5">
            <p>
              <span className="text-gray-500 dark:text-gray-400">Cost now: </span>
              <span className="font-medium text-gray-800 dark:text-white/90">{rm(chosen.cost_price)}</span>
              <span className="text-gray-500 dark:text-gray-400"> → new supplier price: </span>
              <span className="font-medium text-gray-800 dark:text-white/90">{rm(product.price_sen)}</span>
            </p>
            <p className="mt-1 text-theme-xs text-ink-subtle">
              Its name, markup ({chosen.markup_percent}%), history and combos stay. It stays {chosen.is_active ? "on" : "off"}.
            </p>
          </div>
        )}

        <div className="flex items-center justify-end gap-3 pt-2">
          <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" disabled={submitting || !chosen}>
            {submitting ? "Linking…" : "Link package"}
          </Button>
        </div>
      </form>
    </>
  );
}

export default function RepointProductModal({ isOpen, onClose, onSubmit, product, gameId, gameName }: RepointProductModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-lg">
            <DialogHeader>
              <DialogTitle>Link to an existing package</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && product && (
                <RepointFields onClose={onClose} onSubmit={onSubmit} product={product} gameId={gameId} gameName={gameName} />
              )}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
