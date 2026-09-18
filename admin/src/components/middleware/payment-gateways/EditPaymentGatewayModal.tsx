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
import { PAYMENT_GATEWAY_FIELD_DEFINITIONS, type PaymentGateway } from "@/lib/payment-gateways";

interface EditPaymentGatewayModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (apiConfig: Record<string, string>) => Promise<void>;
  gatewayKey: string | null;
  gateway: PaymentGateway | null;
}

const inputClass =
  "w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-theme-sm text-gray-800 shadow-theme-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 dark:border-gray-800 dark:bg-white/[0.03] dark:text-white/90";

/**
 * ADR-110 PR-C decision 6 — mirrors `EditSupplierModal`'s own
 * secret-field discipline: `secret_key` is write-only (never
 * pre-filled) and, left blank, is simply omitted from the submitted
 * `api_config` — `PaymentGatewayController::update()` merges onto the
 * stored value, so an untouched secret survives unchanged. `brand_id`/
 * `base_url` are plain visible fields, resubmitted every time.
 */
function EditPaymentGatewayFields({
  onClose,
  onSubmit,
  gatewayKey,
  gateway,
}: Omit<EditPaymentGatewayModalProps, "isOpen"> & { gatewayKey: string; gateway: PaymentGateway | null }) {
  const fields = PAYMENT_GATEWAY_FIELD_DEFINITIONS[gatewayKey] ?? [];
  const [secretValues, setSecretValues] = useState<Record<string, string>>({});
  const [visibleValues, setVisibleValues] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  function visibleValue(key: string): string {
    if (key in visibleValues) return visibleValues[key];
    return gateway?.visible_config[key] ?? "";
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);

    const apiConfig: Record<string, string> = {};
    for (const field of fields) {
      if (field.type === "secret") {
        if (secretValues[field.key]?.trim()) apiConfig[field.key] = secretValues[field.key].trim();
        continue;
      }
      const value = visibleValue(field.key).trim();
      if (value) apiConfig[field.key] = value;
    }

    setSubmitting(true);
    try {
      await onSubmit(apiConfig);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>Edit {gatewayKey} credentials</DialogTitle>
      </DialogHeader>
      <DialogContent>
        {error && (
          <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
            {error}
          </p>
        )}

        <form id="edit-payment-gateway-form" onSubmit={handleSubmit} className="space-y-4">
          {fields.map((field) => {
            if (field.type === "secret") {
              const isConfigured = gateway?.configured_secret_keys.includes(field.key) ?? false;
              return (
                <div key={field.key}>
                  <label className="mb-1.5 flex items-center gap-2 text-theme-sm font-medium text-gray-700 dark:text-gray-300">
                    {field.label}
                    <span
                      className={`rounded-full px-2 py-0.5 text-theme-xs ${
                        isConfigured
                          ? "bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-400"
                          : "bg-gray-100 text-gray-500 dark:bg-white/[0.05] dark:text-gray-400"
                      }`}
                    >
                      {isConfigured ? "Configured" : "Not configured"}
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

            return (
              <div key={field.key}>
                <label className="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">
                  {field.label}
                </label>
                <input
                  className={inputClass}
                  placeholder={field.placeholder}
                  value={visibleValue(field.key)}
                  onChange={(e) => setVisibleValues((v) => ({ ...v, [field.key]: e.target.value }))}
                />
              </div>
            );
          })}
        </form>
      </DialogContent>
      <DialogFooter>
        <Button variant="outlined" onClick={onClose} disabled={submitting}>
          Cancel
        </Button>
        <Button type="submit" form="edit-payment-gateway-form" disabled={submitting}>
          {submitting ? "Saving…" : "Save"}
        </Button>
      </DialogFooter>
    </>
  );
}

export default function EditPaymentGatewayModal({ isOpen, onClose, onSubmit, gatewayKey, gateway }: EditPaymentGatewayModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => !e.value && onClose()}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup>
            {isOpen && gatewayKey && (
              <EditPaymentGatewayFields onClose={onClose} onSubmit={onSubmit} gatewayKey={gatewayKey} gateway={gateway} />
            )}
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
