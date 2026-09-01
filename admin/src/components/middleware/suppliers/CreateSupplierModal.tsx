"use client";

import React, { useState } from "react";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogPortal,
  DialogBackdrop,
  DialogPositioner,
  DialogPopup,
  DialogHeader,
  DialogTitle,
  DialogContent,
  DialogFooter,
} from "@/components/ui/dialog";
import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectIndicator,
  SelectPortal,
  SelectPositioner,
  SelectPopup,
  SelectList,
  SelectOption,
} from "@/components/ui/select";
import type { CreateSupplierValues } from "@/lib/suppliers";

/**
 * ADR-046 decision 4 — `availableSlugs` comes from the backend's own
 * SupplierAdapterFactory::registeredSlugs(), already filtered to
 * exclude slugs an existing Supplier row already uses (see
 * SuppliersPage), so this dropdown can never submit a slug with no
 * adapter implementation behind it.
 */
interface CreateSupplierModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (values: CreateSupplierValues) => Promise<void>;
  availableSlugs: string[];
}

function CreateSupplierFields({
  onClose,
  onSubmit,
  availableSlugs,
}: Omit<CreateSupplierModalProps, "isOpen">) {
  const [name, setName] = useState("");
  const [slug, setSlug] = useState(availableSlugs[0] ?? "");
  const [currency, setCurrency] = useState("MYR");
  const [logoUrl, setLogoUrl] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const slugOptions = availableSlugs.map((s) => ({ label: s, value: s }));

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);

    if (!name.trim() || !slug || !currency.trim()) {
      setError("Name, slug, and currency are required.");
      return;
    }

    setSubmitting(true);
    try {
      await onSubmit({
        name: name.trim(),
        slug,
        currency: currency.trim().toUpperCase(),
        logo_url: logoUrl.trim() || undefined,
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>Add Supplier</DialogTitle>
      </DialogHeader>
      <DialogContent>
        {error && (
          <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
            {error}
          </p>
        )}

        {availableSlugs.length === 0 ? (
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Every registered supplier adapter already has a Supplier row. A new one only appears here once a new
            adapter implementation is built and bound in the container.
          </p>
        ) : (
          <form id="create-supplier-form" onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">
                Slug
              </label>
              <Select value={slug} options={slugOptions} optionLabel="label" optionValue="value" onValueChange={(e) => setSlug(e.value as string)}>
                <SelectTrigger>
                  <SelectValue />
                  <SelectIndicator />
                </SelectTrigger>
                <SelectPortal>
                  <SelectPositioner>
                    <SelectPopup>
                      <SelectList>
                        {slugOptions.map((option, index) => (
                          <SelectOption key={option.value} index={index}>
                            {option.label}
                          </SelectOption>
                        ))}
                      </SelectList>
                    </SelectPopup>
                  </SelectPositioner>
                </SelectPortal>
              </Select>
              <p className="mt-1 text-theme-xs text-gray-400">
                Only adapters already implemented in code can be added here — a typo&apos;d slug would otherwise sit
                silently broken until the first real order.
              </p>
            </div>

            <div>
              <label className="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Name</label>
              <input
                className="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-theme-sm text-gray-800 shadow-theme-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 dark:border-gray-800 dark:bg-white/[0.03] dark:text-white/90"
                value={name}
                onChange={(e) => setName(e.target.value)}
                required
              />
            </div>

            <div>
              <label className="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">
                Currency
              </label>
              <input
                className="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-theme-sm text-gray-800 shadow-theme-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 dark:border-gray-800 dark:bg-white/[0.03] dark:text-white/90"
                value={currency}
                onChange={(e) => setCurrency(e.target.value)}
                maxLength={3}
                required
              />
            </div>

            <div>
              <label className="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">
                Logo URL (optional)
              </label>
              <input
                className="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-theme-sm text-gray-800 shadow-theme-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 dark:border-gray-800 dark:bg-white/[0.03] dark:text-white/90"
                value={logoUrl}
                onChange={(e) => setLogoUrl(e.target.value)}
              />
            </div>

            <p className="text-theme-xs text-gray-400">
              Credentials aren&apos;t set here — add this supplier first, then open Edit to configure its api_config.
            </p>
          </form>
        )}
      </DialogContent>
      <DialogFooter>
        <Button variant="outlined" onClick={onClose} disabled={submitting}>
          Cancel
        </Button>
        {availableSlugs.length > 0 && (
          <Button type="submit" form="create-supplier-form" disabled={submitting}>
            {submitting ? "Adding…" : "Add Supplier"}
          </Button>
        )}
      </DialogFooter>
    </>
  );
}

export default function CreateSupplierModal({ isOpen, onClose, onSubmit, availableSlugs }: CreateSupplierModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => !e.value && onClose()}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup>
            {isOpen && (
              <CreateSupplierFields onClose={onClose} onSubmit={onSubmit} availableSlugs={availableSlugs} />
            )}
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
