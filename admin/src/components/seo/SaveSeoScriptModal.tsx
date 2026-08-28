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
import type { SeoScript, SaveSeoScriptValues } from "@/lib/seo";

interface Props {
  isOpen: boolean;
  script: SeoScript | null;
  onClose: () => void;
  onSubmit: (values: SaveSeoScriptValues) => Promise<void>;
}

const LOCATION_OPTIONS = [
  { value: "head", label: "Head" },
  { value: "body_end", label: "End of body" },
];

/** Renders as a child of <Modal>, which unmounts while closed — same fresh-mount-per-open reasoning as CreateBlacklistEntryModal. */
function Fields({ script, onClose, onSubmit }: Omit<Props, "isOpen">) {
  const [name, setName] = useState(script?.name ?? "");
  const [location, setLocation] = useState<"head" | "body_end">(script?.location ?? "head");
  const [code, setCode] = useState(script?.code ?? "");
  const [priority, setPriority] = useState(String(script?.priority ?? 0));
  const [isActive, setIsActive] = useState(script?.is_active ?? true);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await onSubmit({ name, location, code, priority: Number(priority), is_active: isActive, reseller_id: script?.reseller_id ?? null });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      <p className="mb-5 text-sm text-gray-500 dark:text-gray-400">Injected verbatim — trusted admin content only, not sanitized.</p>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label htmlFor="name">Name</Label>
          <Input id="name" value={name} onChange={(e) => setName(e.target.value)} required />
        </div>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <Label htmlFor="location">Location</Label>
            <SimpleSelect id="location" value={location} onChange={(v) => setLocation(v as "head" | "body_end")} options={LOCATION_OPTIONS} />
          </div>
          <div>
            <Label htmlFor="priority">Priority</Label>
            <input
              id="priority"
              type="number"
              value={priority}
              onChange={(e) => setPriority(e.target.value)}
              className="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800"
            />
          </div>
        </div>
        <div>
          <Label htmlFor="code">Code</Label>
          <textarea
            id="code"
            rows={6}
            value={code}
            onChange={(e) => setCode(e.target.value)}
            required
            className="w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 font-mono text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
          />
        </div>
        <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
          <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
          Active
        </label>

        <div className="flex items-center justify-end gap-3 pt-2">
          <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>Cancel</Button>
          <Button type="submit" disabled={submitting}>{submitting ? "Saving…" : script ? "Save" : "Add Script"}</Button>
        </div>
      </form>
    </>
  );
}

export default function SaveSeoScriptModal({ isOpen, script, onClose, onSubmit }: Props) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-lg">
            <DialogHeader>
              <DialogTitle>{script ? "Edit Script" : "Add Script"}</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && <Fields script={script} onClose={onClose} onSubmit={onSubmit} />}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
