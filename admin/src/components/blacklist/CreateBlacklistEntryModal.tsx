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
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import Select from "@/components/form/Select";
import { Button } from "@/components/ui/button";
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
 * Rendered only while the dialog is open — same fresh-mount-per-open
 * reasoning as CreateVoucherModal/CreateValidatorModal.
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
    <>
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
          <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" disabled={submitting}>
            {submitting ? "Adding…" : "Add Entry"}
          </Button>
        </div>
      </form>
    </>
  );
}

export default function CreateBlacklistEntryModal({ isOpen, onClose, onSubmit }: CreateBlacklistEntryModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-md">
            <DialogHeader>
              <DialogTitle>Add Blacklist Entry</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && <CreateBlacklistEntryFields onClose={onClose} onSubmit={onSubmit} />}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
