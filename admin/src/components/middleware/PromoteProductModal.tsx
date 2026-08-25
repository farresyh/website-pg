"use client";

import React, { useState } from "react";
import { Modal } from "@/components/ui/modal";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import Button from "@/components/ui/button/Button";
import type { SupplierProduct, PromoteValues } from "@/lib/supplier-products";

type ItemValues = Omit<PromoteValues, "game_id">;

interface PromoteProductModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (values: PromoteValues) => Promise<void>;
  product: SupplierProduct | null;
  gameId: number;
  gameName: string;
}

/**
 * Renders as a child of <Modal>, which unmounts while closed — same
 * fresh-mount-per-open reasoning as the other *FormFields components.
 * `gameId` is already resolved (LinkCategoryModal, once per category)
 * — this only curates the item's own final name. No markup field
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
  onSubmit: (values: ItemValues) => Promise<void>;
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
    <div className="max-w-md p-6">
      <h3 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">Add to Catalog</h3>
      <p className="mb-4 text-sm text-gray-500 dark:text-gray-400">
        {product.name} → <span className="font-medium">{gameName}</span>
      </p>

      <div className="mb-4 rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/5">
        <span className="text-gray-500 dark:text-gray-400">Supplier Cost (from Gamevion, not editable): </span>
        <span className="font-medium text-gray-800 dark:text-white/90">
          {product.price_sen !== null ? `RM ${(product.price_sen / 100).toFixed(2)}` : "unknown"}
        </span>
      </div>

      <p className="mb-4 text-theme-xs text-gray-400">
        Reseller markup is set afterward in Games &amp; Packages, not here.
      </p>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
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
          <p className="mt-1 text-theme-xs text-gray-400">
            Set this to let the storefront hide a pricier duplicate from another supplier selling the same amount.
          </p>
        </div>

        <div className="flex items-center justify-end gap-3 pt-2">
          <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" disabled={submitting}>
            {submitting ? "Adding…" : "Add to Catalog"}
          </Button>
        </div>
      </form>
    </div>
  );
}

export default function PromoteProductModal({ isOpen, onClose, onSubmit, product, gameId, gameName }: PromoteProductModalProps) {
  return (
    <Modal isOpen={isOpen} onClose={onClose} className="max-w-md">
      {isOpen && product && (
        <PromoteProductFields
          onClose={onClose}
          onSubmit={(values) => onSubmit({ ...values, game_id: gameId })}
          product={product}
          gameName={gameName}
        />
      )}
    </Modal>
  );
}
