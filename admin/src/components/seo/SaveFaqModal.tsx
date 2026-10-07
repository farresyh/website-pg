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
import { Times as CloseIcon } from "@primeicons/react/times";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import type { Faq, SaveFaqValues } from "@/lib/seo";

interface Props {
  isOpen: boolean;
  faq: Faq | null;
  /** Default position for a new entry — after the current last one. */
  nextSortOrder: number;
  onClose: () => void;
  onSubmit: (values: SaveFaqValues) => Promise<void>;
}

/** Fresh mount per open (see SaveRedirectModal). */
function Fields({ faq, nextSortOrder, onClose, onSubmit }: Omit<Props, "isOpen">) {
  const [question, setQuestion] = useState(faq?.question ?? "");
  const [answer, setAnswer] = useState(faq?.answer ?? "");
  const [sortOrder, setSortOrder] = useState(String(faq?.sort_order ?? nextSortOrder));
  const [isActive, setIsActive] = useState(faq?.is_active ?? true);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await onSubmit({ question, answer, sort_order: Number(sortOrder), is_active: isActive });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      <p className="mb-5 text-sm text-gray-500 dark:text-gray-400">
        Plain text. <code>{"{store_name}"}</code> becomes each brand&apos;s own store name.
      </p>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label htmlFor="faq_question">Question</Label>
          <Input id="faq_question" maxLength={255} value={question} onChange={(e) => setQuestion(e.target.value)} required />
        </div>
        <div>
          <Label htmlFor="faq_answer">Answer ({answer.length}/2000)</Label>
          <textarea
            id="faq_answer"
            rows={5}
            maxLength={2000}
            value={answer}
            onChange={(e) => setAnswer(e.target.value)}
            required
            className="w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
          />
        </div>
        <div>
          <Label htmlFor="faq_sort_order">Position (lower shows first)</Label>
          <Input id="faq_sort_order" type="number" min={0} max={65535} value={sortOrder} onChange={(e) => setSortOrder(e.target.value)} required />
        </div>
        <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
          <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
          Shown on the storefront
        </label>

        <div className="flex items-center justify-end gap-3 pt-2">
          <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>Cancel</Button>
          <Button type="submit" disabled={submitting}>{submitting ? "Saving…" : faq ? "Save" : "Add Question"}</Button>
        </div>
      </form>
    </>
  );
}

export default function SaveFaqModal({ isOpen, faq, nextSortOrder, onClose, onSubmit }: Props) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-lg">
            <DialogHeader>
              <DialogTitle>{faq ? "Edit Question" : "Add Question"}</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && <Fields faq={faq} nextSortOrder={nextSortOrder} onClose={onClose} onSubmit={onSubmit} />}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
