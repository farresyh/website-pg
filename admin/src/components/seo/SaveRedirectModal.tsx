"use client";

import React, { useState } from "react";
import { Modal } from "@/components/ui/modal";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import Select from "@/components/form/Select";
import Button from "@/components/ui/button/Button";
import type { Redirect, SaveRedirectValues } from "@/lib/seo";

interface Props {
  isOpen: boolean;
  redirect: Redirect | null;
  onClose: () => void;
  onSubmit: (values: SaveRedirectValues) => Promise<void>;
}

const STATUS_OPTIONS = [
  { value: "301", label: "301 — Permanent" },
  { value: "302", label: "302 — Temporary" },
];

/** Renders as a child of <Modal>, which unmounts while closed — same fresh-mount-per-open reasoning as CreateBlacklistEntryModal. */
function Fields({ redirect, onClose, onSubmit }: Omit<Props, "isOpen">) {
  const [fromPath, setFromPath] = useState(redirect?.from_path ?? "");
  const [toPath, setToPath] = useState(redirect?.to_path ?? "");
  const [statusCode, setStatusCode] = useState(String(redirect?.status_code ?? 301));
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await onSubmit({ from_path: fromPath, to_path: toPath, status_code: Number(statusCode) as 301 | 302 });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="max-w-md p-6">
      <h3 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">{redirect ? "Edit Redirect" : "Add Redirect"}</h3>
      <p className="mb-5 text-sm text-gray-500 dark:text-gray-400">Exact-path matching only — no regex.</p>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label htmlFor="from_path">From path</Label>
          <Input id="from_path" placeholder="/old-page" value={fromPath} onChange={(e) => setFromPath(e.target.value)} required />
        </div>
        <div>
          <Label htmlFor="to_path">To path</Label>
          <Input id="to_path" placeholder="/new-page" value={toPath} onChange={(e) => setToPath(e.target.value)} required />
        </div>
        <div>
          <Label htmlFor="status_code">Status code</Label>
          <Select id="status_code" value={statusCode} onChange={setStatusCode} options={STATUS_OPTIONS} />
        </div>

        <div className="flex items-center justify-end gap-3 pt-2">
          <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>Cancel</Button>
          <Button type="submit" disabled={submitting}>{submitting ? "Saving…" : redirect ? "Save" : "Add Redirect"}</Button>
        </div>
      </form>
    </div>
  );
}

export default function SaveRedirectModal({ isOpen, redirect, onClose, onSubmit }: Props) {
  return (
    <Modal isOpen={isOpen} onClose={onClose} className="max-w-md">
      {isOpen && <Fields redirect={redirect} onClose={onClose} onSubmit={onSubmit} />}
    </Modal>
  );
}
