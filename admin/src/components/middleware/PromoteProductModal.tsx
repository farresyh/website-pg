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
import type { SupplierProduct, PromoteValues } from "@/lib/supplier-products";

interface PromoteProductModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (values: PromoteValues) => Promise<void>;
  product: SupplierProduct | null;
  gameName: string;
}

/**
 * Renders as a child of <Modal>, which unmounts while closed — same
 * fresh-mount-per-open reasoning as the other *FormFields components.
 * The game is already resolved (LinkCategoryModal, once per category)
 * and the backend reads it from the product — this only curates the item's own final name. No markup field
 * (founder revision, docs/prd.md §14) — every promoted Package gets
 * the configured default markup automatically; admin sets the real
 * per-package markup afterward in /admin/games.
 */
function PromoteProductFields({
  onClose,
  onSubmit,
  product,
  gameName,
}: {
  onClose: () => void;
  onSubmit: (values: PromoteValues) => Promise<void>;
  product: SupplierProduct;
  gameName: string;
}) {
  const [finalName, setFinalName] = useState(product.name);
  const [denomination, setDenomination] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const parsedDenomination = denomination.trim() === "" ? null : parseInt(denomination, 10);
      await onSubmit({ name: finalName, denomination: parsedDenomination });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      <p className="mb-4 text-sm text-ink-muted">
        {product.name} → <span className="font-medium">{gameName}</span>
      </p>

      <div className="mb-4 rounded-lg bg-subtle px-3 py-2 text-sm">
        <span className="text-ink-muted">Supplier Cost (from Gamevion, not editable): </span>
        <span className="font-medium text-ink">
          {product.price_sen !== null ? `RM ${(product.price_sen / 100).toFixed(2)}` : "unknown"}
        </span>
      </div>

      <p className="mb-4 text-theme-xs text-ink-subtle">
        Affiliate markup is set afterward in Games &amp; Packages, not here.
      </p>

      {error && (
        <p className="mb-4 rounded-lg bg-danger-surface px-3 py-2 text-sm text-danger-ink">
          {error}
        </p>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label htmlFor="final_name">Final Package Name (shown to customers)</Label>
          <Input id="final_name" value={finalName} onChange={(e) => setFinalName(e.target.value)} required />
        </div>

        <div>
          <Label htmlFor="denomination">Denomination (optional)</Label>
          <Input
            id="denomination"
            value={denomination}
            onChange={(e) => setDenomination(e.target.value)}
            placeholder="e.g. 14 for 14 Diamonds"
          />
          <p className="mt-1 text-theme-xs text-ink-subtle">
            Set this to let the storefront hide a pricier duplicate from another supplier selling the same amount.
          </p>
        </div>

        <div className="flex items-center justify-end gap-3 pt-2">
          <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" disabled={submitting}>
            {submitting ? "Adding…" : "Add to Catalog"}
          </Button>
        </div>
      </form>
    </>
  );
}

export default function PromoteProductModal({ isOpen, onClose, onSubmit, product, gameName }: PromoteProductModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-md">
            <DialogHeader>
              <DialogTitle>Add to Catalog</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && product && (
                <PromoteProductFields
                  onClose={onClose}
                  onSubmit={onSubmit}
                  product={product}
                  gameName={gameName}
                />
              )}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
