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
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { SimpleSelect } from "@/components/ui/select";
import { Button } from "@/components/ui/button";
import type { AvailableValidatorKey } from "@/lib/player-validators";

interface CreateValidatorModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (values: { name: string; key: string }) => Promise<void>;
  /** Keys with no real backend implementation can never be picked — see
   * PlayerValidatorRegistry::AVAILABLE_KEYS. Keys already claimed by an
   * existing profile are filtered out client-side (the backend's own
   * unique index is the real enforcement). */
  availableKeys: AvailableValidatorKey[];
  usedKeys: string[];
}

/**
 * Renders as a child of <Modal>, which unmounts while closed — same
 * fresh-mount-per-open reasoning as LinkCategoryModal.
 */
function CreateValidatorFields({
  onClose,
  onSubmit,
  availableKeys,
  usedKeys,
}: Omit<CreateValidatorModalProps, "isOpen">) {
  const selectableKeys = availableKeys.filter((k) => !usedKeys.includes(k.key));
  const [name, setName] = useState("");
  const [key, setKey] = useState(selectableKeys[0]?.key ?? "");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);

    if (!name.trim()) {
      setError("Enter a name for this validator.");
      return;
    }
    if (!key) {
      setError("Select a key — every key here has a real backend implementation.");
      return;
    }

    setSubmitting(true);
    try {
      await onSubmit({ name: name.trim(), key });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      <p className="mb-5 text-sm text-gray-500 dark:text-gray-400">
        The key you pick here must already have a real implementation on the backend
        (app/Services/PlayerValidation/) — this list only shows keys that do, so a validator can never be created
        pointing at nothing.
      </p>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      {selectableKeys.length === 0 ? (
        <p className="mb-4 rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-500 dark:bg-white/5 dark:text-gray-400">
          Every implemented key already has a validator profile.
        </p>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <Label htmlFor="validator_name">Name</Label>
            <Input
              id="validator_name"
              placeholder="e.g. Mobile Legends Validator"
              value={name}
              onChange={(e) => setName(e.target.value)}
              required
            />
          </div>
          <div>
            <Label htmlFor="validator_key">Key</Label>
            <SimpleSelect
              id="validator_key"
              value={key}
              onChange={setKey}
              options={selectableKeys.map((k) => ({ value: k.key, label: `${k.label} (${k.key})` }))}
            />
          </div>

          <div className="flex items-center justify-end gap-3 pt-2">
            <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>
              Cancel
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? "Creating…" : "Create Validator"}
            </Button>
          </div>
        </form>
      )}
    </>
  );
}

export default function CreateValidatorModal({
  isOpen,
  onClose,
  onSubmit,
  availableKeys,
  usedKeys,
}: CreateValidatorModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-md">
            <DialogHeader>
              <DialogTitle>Create Validator</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && (
                <CreateValidatorFields onClose={onClose} onSubmit={onSubmit} availableKeys={availableKeys} usedKeys={usedKeys} />
              )}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
