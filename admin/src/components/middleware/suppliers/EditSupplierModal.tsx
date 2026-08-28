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
import { SUPPLIER_FIELD_DEFINITIONS, type Supplier, type UpdateSupplierValues } from "@/lib/suppliers";

interface EditSupplierModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (values: UpdateSupplierValues) => Promise<void>;
  supplier: Supplier | null;
}

const inputClass =
  "w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-theme-sm text-gray-800 shadow-theme-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 dark:border-gray-800 dark:bg-white/[0.03] dark:text-white/90";

/**
 * ADR-046 decision 3 — structured per-supplier form, not a blind JSON
 * editor. Secret fields are write-only (never pre-filled — SUPP-5
 * means the backend never sends the real value back) and, if left
 * blank, are simply not included in the submitted api_config;
 * SupplierController::update() merges onto the stored value, so an
 * untouched secret survives unchanged rather than being blanked out.
 * base_url/sandbox/testing are plain visible fields, resubmitted
 * every time — that's the whole point of moving them off .env, so an
 * admin can flip them live.
 */
function EditSupplierFields({ onClose, onSubmit, supplier }: Omit<EditSupplierModalProps, "isOpen"> & { supplier: Supplier }) {
  const [name, setName] = useState(supplier.name);
  const [currency, setCurrency] = useState(supplier.currency);
  const [logoUrl, setLogoUrl] = useState(supplier.logo_url ?? "");
  const [secretValues, setSecretValues] = useState<Record<string, string>>({});
  const [visibleValues, setVisibleValues] = useState<Record<string, string | boolean>>({});
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const fields = SUPPLIER_FIELD_DEFINITIONS[supplier.slug] ?? [];

  function visibleValue(key: string, fallback: string | boolean): string | boolean {
    if (key in visibleValues) return visibleValues[key];
    const fromServer = supplier.visible_config[key];
    return fromServer ?? fallback;
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);

    if (!name.trim() || !currency.trim()) {
      setError("Name and currency are required.");
      return;
    }

    const apiConfig: Record<string, unknown> = {};
    for (const field of fields) {
      if (field.type === "secret") {
        if (secretValues[field.key]?.trim()) apiConfig[field.key] = secretValues[field.key].trim();
        continue;
      }
      apiConfig[field.key] = field.type === "boolean" ? visibleValue(field.key, false) : visibleValue(field.key, "");
    }

    setSubmitting(true);
    try {
      await onSubmit({
        name: name.trim(),
        currency: currency.trim().toUpperCase(),
        logo_url: logoUrl.trim() || null,
        ...(fields.length > 0 ? { api_config: apiConfig } : {}),
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
        <DialogTitle>Edit {supplier.name}</DialogTitle>
      </DialogHeader>
      <DialogContent>
        {error && (
          <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
            {error}
          </p>
        )}

        <form id="edit-supplier-form" onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Name</label>
            <input className={inputClass} value={name} onChange={(e) => setName(e.target.value)} required />
          </div>

          <div>
            <label className="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">
              Currency
            </label>
            <input className={inputClass} value={currency} onChange={(e) => setCurrency(e.target.value)} maxLength={3} required />
          </div>

          <div>
            <label className="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">
              Logo URL
            </label>
            <input className={inputClass} value={logoUrl} onChange={(e) => setLogoUrl(e.target.value)} />
          </div>

          {fields.length > 0 && (
            <div className="space-y-4 border-t border-gray-200 pt-4 dark:border-gray-800">
              <p className="text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                Credentials &amp; connection settings
              </p>

              {fields.map((field) => {
                if (field.type === "secret") {
                  return (
                    <div key={field.key}>
                      <label className="mb-1.5 flex items-center gap-2 text-theme-sm font-medium text-gray-700 dark:text-gray-300">
                        {field.label}
                        <span
                          className={`rounded-full px-2 py-0.5 text-theme-xs ${
                            supplier.has_credentials
                              ? "bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-400"
                              : "bg-gray-100 text-gray-500 dark:bg-white/[0.05] dark:text-gray-400"
                          }`}
                        >
                          {supplier.has_credentials ? "Configured" : "Not configured"}
                        </span>
                      </label>
                      <input
                        type="password"
                        className={inputClass}
                        placeholder="Leave blank to keep the current value"
                        value={secretValues[field.key] ?? ""}
                        onChange={(e) => setSecretValues((v) => ({ ...v, [field.key]: e.target.value }))}
                        autoComplete="off"
                      />
                    </div>
                  );
                }

                if (field.type === "boolean") {
                  return (
                    <label key={field.key} className="flex items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-300">
                      <input
                        type="checkbox"
                        checked={Boolean(visibleValue(field.key, false))}
                        onChange={(e) => setVisibleValues((v) => ({ ...v, [field.key]: e.target.checked }))}
                      />
                      {field.label}
                    </label>
                  );
                }

                return (
                  <div key={field.key}>
                    <label className="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">
                      {field.label}
                    </label>
                    <input
                      className={inputClass}
                      placeholder={field.placeholder}
                      value={String(visibleValue(field.key, ""))}
                      onChange={(e) => setVisibleValues((v) => ({ ...v, [field.key]: e.target.value }))}
                    />
                  </div>
                );
              })}
            </div>
          )}
        </form>
      </DialogContent>
      <DialogFooter>
        <Button variant="outlined" onClick={onClose} disabled={submitting}>
          Cancel
        </Button>
        <Button type="submit" form="edit-supplier-form" disabled={submitting}>
          {submitting ? "Saving…" : "Save"}
        </Button>
      </DialogFooter>
    </>
  );
}

export default function EditSupplierModal({ isOpen, onClose, onSubmit, supplier }: EditSupplierModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => !e.value && onClose()}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup>
            {isOpen && supplier && <EditSupplierFields onClose={onClose} onSubmit={onSubmit} supplier={supplier} />}
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
