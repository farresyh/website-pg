"use client";

import React, { useState } from "react";
import { Modal } from "@/components/ui/modal";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import Select from "@/components/form/Select";
import Button from "@/components/ui/button/Button";
import type { CreateBlacklistEntryValues, BlacklistEntryType } from "@/lib/blacklist";

interface CreateBlacklistEntryModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (values: CreateBlacklistEntryValues) => Promise<void>;
}

const TYPE_OPTIONS: { value: BlacklistEntryType; label: string }[] = [
  { value: "player_id", label: "Player ID" },
  { value: "email", label: "Customer Email" },
  { value: "phone", label: "Customer Phone" },
];

/**
 * Renders as a child of <Modal>, which unmounts while closed — same
 * fresh-mount-per-open reasoning as CreateVoucherModal/CreateValidatorModal.
 */
function CreateBlacklistEntryFields({ onClose, onSubmit }: Omit<CreateBlacklistEntryModalProps, "isOpen">) {
  const [type, setType] = useState<BlacklistEntryType>("player_id");
  const [value, setValue] = useState("");
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);

    try {
      await onSubmit({ type, value, reason });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="max-w-md p-6">
      <h3 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">Add Blacklist Entry</h3>
      <p className="mb-5 text-sm text-gray-500 dark:text-gray-400">
        Blocks any checkout matching this value, independent of any supplier-side blacklist.
      </p>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label htmlFor="type">Type</Label>
          <Select
            id="type"
            value={type}
            onChange={(v) => setType(v as BlacklistEntryType)}
            options={TYPE_OPTIONS}
          />
        </div>
        <div>
          <Label htmlFor="value">Value</Label>
          <Input id="value" value={value} onChange={(e) => setValue(e.target.value)} required />
        </div>
        <div>
          <Label htmlFor="reason">Reason</Label>
          <Input id="reason" value={reason} onChange={(e) => setReason(e.target.value)} required />
        </div>

        <div className="flex items-center justify-end gap-3 pt-2">
          <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" disabled={submitting}>
            {submitting ? "Adding…" : "Add Entry"}
          </Button>
        </div>
      </form>
    </div>
  );
}

export default function CreateBlacklistEntryModal({ isOpen, onClose, onSubmit }: CreateBlacklistEntryModalProps) {
  return (
    <Modal isOpen={isOpen} onClose={onClose} className="max-w-md">
      {isOpen && <CreateBlacklistEntryFields onClose={onClose} onSubmit={onSubmit} />}
    </Modal>
  );
}
