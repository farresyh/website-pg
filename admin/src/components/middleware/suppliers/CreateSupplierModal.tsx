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
 * ADR-046 decision 4 + ADR-125 decision 1 — `availableDrivers` comes from
 * the backend's SupplierAdapterFactory::registeredDrivers(), with API
 * drivers that already have a row filtered out (see SuppliersPage). An
 * API supplier's slug is its driver (set by the backend); a manual
 * supplier — any number of them — names its own, is paid in MYR, and
 * picks how it is funded.
 */
interface CreateSupplierModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (values: CreateSupplierValues) => Promise<void>;
  availableDrivers: string[];
}

const INPUT =
  "w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-theme-sm text-gray-800 shadow-theme-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 disabled:opacity-60 dark:border-gray-800 dark:bg-white/[0.03] dark:text-white/90";
const LABEL = "mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300";

function driverLabel(driver: string): string {
  return driver === "manual" ? "Manual (no API — a person fulfils each order)" : driver;
}

function slugify(value: string): string {
  return value.toLowerCase().trim().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
}

function OptionSelect({ id, value, options, onChange }: { id: string; value: string; options: { label: string; value: string }[]; onChange: (v: string) => void }) {
  return (
    <Select value={value} options={options} optionLabel="label" optionValue="value" onValueChange={(e) => onChange(e.value as string)}>
      <SelectTrigger id={id}>
        <SelectValue />
        <SelectIndicator />
      </SelectTrigger>
      <SelectPortal>
        <SelectPositioner>
          <SelectPopup>
            <SelectList>
              {options.map((option, index) => (
                <SelectOption key={option.value} index={index}>
                  {option.label}
                </SelectOption>
              ))}
            </SelectList>
          </SelectPopup>
        </SelectPositioner>
      </SelectPortal>
    </Select>
  );
}

function CreateSupplierFields({ onClose, onSubmit, availableDrivers }: Omit<CreateSupplierModalProps, "isOpen">) {
  const [driver, setDriver] = useState(availableDrivers[0] ?? "");
  const [name, setName] = useState("");
  const [slug, setSlug] = useState("");
  const [slugEdited, setSlugEdited] = useState(false);
  const [currency, setCurrency] = useState("MYR");
  const [fundingMode, setFundingMode] = useState<"prepaid" | "pay_after">("prepaid");
  const [logoUrl, setLogoUrl] = useState("");
  const [isPenjoki, setIsPenjoki] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const isManual = driver === "manual";
  const driverOptions = availableDrivers.map((d) => ({ label: driverLabel(d), value: d }));
  const fundingOptions = [
    { label: "Prepaid — we top up its balance first", value: "prepaid" },
    { label: "Pay after delivery — we owe it per order", value: "pay_after" },
  ];

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);

    if (!name.trim() || !driver || (!isManual && !currency.trim()) || (isManual && !slug)) {
      setError(isManual ? "Name and slug are required." : "Name and currency are required.");
      return;
    }

    setSubmitting(true);
    try {
      await onSubmit({
        name: name.trim(),
        driver,
        slug: isManual ? slug : undefined,
        currency: isManual ? "MYR" : currency.trim().toUpperCase(),
        funding_mode: isManual ? fundingMode : "prepaid",
        logo_url: logoUrl.trim() || undefined,
        joki_role: isManual && fundingMode === "pay_after" && isPenjoki ? "penjoki" : undefined,
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

        <form id="create-supplier-form" onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label htmlFor="supplier_driver" className={LABEL}>Type</label>
            <OptionSelect id="supplier_driver" value={driver} options={driverOptions} onChange={setDriver} />
            <p className="mt-1 text-theme-xs text-ink-subtle">
              {isManual
                ? "No sync, no balance check, no status polling. Its balance is its funding ledger."
                : "Only adapters already built in code are listed. Its slug is the adapter name."}
            </p>
          </div>

          <div>
            <label htmlFor="supplier_name" className={LABEL}>Name</label>
            <input
              id="supplier_name"
              className={INPUT}
              value={name}
              onChange={(e) => {
                setName(e.target.value);
                if (!slugEdited) setSlug(slugify(e.target.value));
              }}
              required
            />
          </div>

          {isManual && (
            <div>
              <label htmlFor="supplier_slug" className={LABEL}>Slug</label>
              <input
                id="supplier_slug"
                className={INPUT}
                value={slug}
                onChange={(e) => {
                  setSlug(slugify(e.target.value));
                  setSlugEdited(true);
                }}
                required
              />
              <p className="mt-1 text-theme-xs text-ink-subtle">A short unique name, e.g. roblox-ali. It can&apos;t be changed later.</p>
            </div>
          )}

          <div>
            <label htmlFor="supplier_currency" className={LABEL}>Currency</label>
            <input
              id="supplier_currency"
              className={INPUT}
              value={isManual ? "MYR" : currency}
              onChange={(e) => setCurrency(e.target.value)}
              maxLength={3}
              disabled={isManual}
              required
            />
            {isManual && <p className="mt-1 text-theme-xs text-ink-subtle">A manual supplier is paid in ringgit.</p>}
          </div>

          {isManual && (
            <div>
              <label htmlFor="supplier_funding_mode" className={LABEL}>How it is paid</label>
              <OptionSelect id="supplier_funding_mode" value={fundingMode} options={fundingOptions} onChange={(v) => setFundingMode(v as "prepaid" | "pay_after")} />
              <p className="mt-1 text-theme-xs text-ink-subtle">Can be changed later in Edit. Payments are recorded in Accounting → Supplier Funding.</p>
            </div>
          )}

          {isManual && fundingMode === "pay_after" && (
            <label className="flex items-start gap-2 text-theme-sm text-gray-700 dark:text-gray-300">
              <input type="checkbox" className="mt-1" checked={isPenjoki} onChange={(e) => setIsPenjoki(e.target.checked)} />
              <span>
                A penjoki (Joki player)
                <span className="block text-theme-xs text-ink-subtle">Gets joki orders through Assign in Admin → Joki, never an ordinary package. Can&apos;t be changed later.</span>
              </span>
            </label>
          )}

          <div>
            <label htmlFor="supplier_logo" className={LABEL}>Logo URL (optional)</label>
            <input id="supplier_logo" className={INPUT} value={logoUrl} onChange={(e) => setLogoUrl(e.target.value)} />
          </div>

          {!isManual && (
            <p className="text-theme-xs text-ink-subtle">
              Credentials aren&apos;t set here — add this supplier first, then open Edit to configure its api_config.
            </p>
          )}
        </form>
      </DialogContent>
      <DialogFooter>
        <Button variant="outlined" onClick={onClose} disabled={submitting}>
          Cancel
        </Button>
        <Button type="submit" form="create-supplier-form" disabled={submitting || availableDrivers.length === 0}>
          {submitting ? "Adding…" : "Add Supplier"}
        </Button>
      </DialogFooter>
    </>
  );
}

export default function CreateSupplierModal({ isOpen, onClose, onSubmit, availableDrivers }: CreateSupplierModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => !e.value && onClose()}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup>
            {isOpen && (
              <CreateSupplierFields onClose={onClose} onSubmit={onSubmit} availableDrivers={availableDrivers} />
            )}
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
