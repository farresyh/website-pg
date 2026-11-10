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
import { Textarea } from "@/components/ui/textarea";
import { SimpleSelect } from "@/components/ui/select";
import { Button } from "@/components/ui/button";
import { categoryLabel, type Entry, type Suggestion } from "@/lib/cs-knowledge";

interface Props {
  suggestion: Suggestion | null;
  entries: Entry[];
  onClose: () => void;
  onSubmit: (values: { cs_knowledge_entry_id: number; question: string; answer: string | null }) => Promise<void>;
}

/** ADR-136 decision 10: the same question asked again becomes a new way of asking an existing entry. */
function Fields({ suggestion, entries, onClose, onSubmit }: Props & { suggestion: Suggestion }) {
  // Start on the entry the nightly AI matched, so "consider Merge" and the
  // modal agree. With no match, start on nothing: a default entry is one
  // careless click from merging into the wrong one.
  const suggested = entries.find((e) => e.id === suggestion.ai_entry_id);
  const [entryId, setEntryId] = useState(suggested ? String(suggested.id) : "");
  const [question, setQuestion] = useState(suggestion.ai_question ?? suggestion.question.split("\n")[0] ?? "");
  const [answer, setAnswer] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const target = entries.find((e) => String(e.id) === entryId);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await onSubmit({ cs_knowledge_entry_id: Number(entryId), question: question.trim(), answer: answer.trim() || null });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  if (entries.length === 0) {
    return <p className="text-sm text-ink-muted">There is no approved entry to merge into yet. Use Approve as new instead.</p>;
  }

  return (
    <>
      <div className="mb-5 rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <p className="text-theme-xs font-medium text-ink-muted">Customer asked (masked)</p>
        <p className="mt-1 whitespace-pre-line text-ink">{suggestion.question}</p>
        <p className="mt-3 text-theme-xs font-medium text-ink-muted">Staff answered (masked)</p>
        <p className="mt-1 whitespace-pre-line text-ink">{suggestion.answer}</p>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label htmlFor="cs-merge-entry">Entry</Label>
          <SimpleSelect
            id="cs-merge-entry"
            value={entryId}
            onChange={setEntryId}
            options={[
              ...(suggested ? [] : [{ value: "", label: "Choose an entry…" }]),
              ...entries.map((e) => ({
                value: String(e.id),
                label: `#${e.id} ${categoryLabel(e.category)}: ${e.questions[0]}${e.id === suggestion.ai_entry_id ? " (suggested)" : ""}`,
              })),
            ]}
          />
          {target && <p className="mt-2 whitespace-pre-line text-theme-xs text-ink-muted">Current answer: {target.answer}</p>}
        </div>
        <div>
          <Label htmlFor="cs-merge-question">New way of asking</Label>
          <Input id="cs-merge-question" value={question} onChange={(e) => setQuestion(e.target.value)} maxLength={500} required />
        </div>
        <div>
          <Label htmlFor="cs-merge-answer">Replace the answer (optional)</Label>
          <Textarea id="cs-merge-answer" value={answer} onChange={(e) => setAnswer(e.target.value)} rows={3} maxLength={2000} placeholder="Leave empty to keep the current answer" />
        </div>
        <div className="flex items-center justify-end gap-3 pt-2">
          <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" disabled={submitting || !entryId}>
            {submitting ? "Merging…" : "Merge"}
          </Button>
        </div>
      </form>
    </>
  );
}

export default function MergeSuggestionModal(props: Props) {
  const { suggestion, onClose } = props;
  return (
    <Dialog open={suggestion !== null} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-2xl">
            <DialogHeader>
              <DialogTitle>Merge into an existing entry</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>{suggestion && <Fields key={suggestion.id} {...props} suggestion={suggestion} />}</DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
