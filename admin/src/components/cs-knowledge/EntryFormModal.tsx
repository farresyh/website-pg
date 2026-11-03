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
import { CATEGORIES, type Category, type EntryValues } from "@/lib/cs-knowledge";

export interface Option {
  id: number;
  name: string;
}

interface Props {
  isOpen: boolean;
  title: string;
  submitLabel: string;
  initial: EntryValues;
  /** The masked customer question and staff answer this entry is written from. */
  source?: { question: string; answer: string };
  brands: Option[];
  games: Option[];
  onClose: () => void;
  onSubmit: (values: EntryValues) => Promise<void>;
}

const toOptions = (list: Option[], all: string) => [{ value: "", label: all }, ...list.map((o) => ({ value: String(o.id), label: o.name }))];

function Fields({ initial, source, brands, games, submitLabel, onClose, onSubmit }: Omit<Props, "isOpen" | "title">) {
  const [category, setCategory] = useState<Category>(initial.category);
  const [questions, setQuestions] = useState(initial.questions.join("\n"));
  const [answer, setAnswer] = useState(initial.answer);
  const [brand, setBrand] = useState(initial.affiliate_id ? String(initial.affiliate_id) : "");
  const [game, setGame] = useState(initial.game_id ? String(initial.game_id) : "");
  const [validUntil, setValidUntil] = useState(initial.valid_until ?? "");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await onSubmit({
        category,
        questions: questions.split("\n").map((q) => q.trim()).filter(Boolean),
        answer: answer.trim(),
        affiliate_id: brand ? Number(brand) : null,
        game_id: game ? Number(game) : null,
        valid_until: validUntil || null,
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      {source && (
        <div className="mb-5 space-y-3 rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm dark:border-gray-800 dark:bg-white/[0.03]">
          <div>
            <p className="text-theme-xs font-medium text-ink-muted">Customer asked (masked)</p>
            <p className="mt-1 whitespace-pre-line text-ink">{source.question}</p>
          </div>
          <div>
            <p className="text-theme-xs font-medium text-ink-muted">Staff answered (masked)</p>
            <p className="mt-1 whitespace-pre-line text-ink">{source.answer}</p>
          </div>
          <p className="text-theme-xs text-ink-muted">
            Write the entry for every customer: a general question, and an answer with no cost, supplier, margin or other
            internal detail. The assistant will say only what you approve here.
          </p>
        </div>
      )}

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label htmlFor="cs-category">Category</Label>
          <SimpleSelect id="cs-category" value={category} onChange={(v) => setCategory(v as Category)} options={[...CATEGORIES]} />
        </div>
        <div>
          <Label htmlFor="cs-questions">Questions (one per line)</Label>
          <Textarea id="cs-questions" value={questions} onChange={(e) => setQuestions(e.target.value)} rows={3} required />
          <p className="mt-1 text-theme-xs text-ink-muted">Different ways customers ask the same thing.</p>
        </div>
        <div>
          <Label htmlFor="cs-answer">Answer</Label>
          <Textarea id="cs-answer" value={answer} onChange={(e) => setAnswer(e.target.value)} rows={4} maxLength={2000} required />
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div>
            <Label htmlFor="cs-brand">Brand</Label>
            <SimpleSelect id="cs-brand" value={brand} onChange={setBrand} options={toOptions(brands, "All brands")} />
          </div>
          <div>
            <Label htmlFor="cs-game">Game</Label>
            <SimpleSelect id="cs-game" value={game} onChange={setGame} options={toOptions(games, "All games")} />
          </div>
          <div>
            <Label htmlFor="cs-valid-until">Valid until</Label>
            <Input id="cs-valid-until" type="date" value={validUntil} onChange={(e) => setValidUntil(e.target.value)} />
          </div>
        </div>

        <div className="flex items-center justify-end gap-3 pt-2">
          <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" disabled={submitting}>
            {submitting ? "Saving…" : submitLabel}
          </Button>
        </div>
      </form>
    </>
  );
}

export default function EntryFormModal({ isOpen, title, onClose, ...rest }: Props) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-2xl">
            <DialogHeader>
              <DialogTitle>{title}</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>{isOpen && <Fields onClose={onClose} {...rest} />}</DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
