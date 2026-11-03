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
import { Switch } from "@/components/ui/switch";
import { Button } from "@/components/ui/button";
import {
  DELIVERY_STATUSES,
  HANDOVER_REASONS,
  PAYMENT_STATUSES,
  type EvalCase,
  type EvalCaseValues,
  type HandoverReason,
  type OrderFacts,
} from "@/lib/cs-eval";

interface Props {
  editing: EvalCase | "new" | null;
  onClose: () => void;
  onSubmit: (values: EvalCaseValues) => Promise<void>;
}

const lines = (s: string) => s.split("\n").map((l) => l.trim()).filter(Boolean);
const opts = (list: readonly string[]) => list.map((v) => ({ value: v, label: v.replaceAll("_", " ") }));

function Fields({ initial, onClose, onSubmit }: { initial: EvalCase | null; onClose: () => void; onSubmit: Props["onSubmit"] }) {
  const [title, setTitle] = useState(initial?.title ?? "");
  const [kind, setKind] = useState<EvalCaseValues["kind"]>(initial?.kind ?? "quality");
  const [messages, setMessages] = useState((initial?.messages ?? []).join("\n"));
  const [action, setAction] = useState<EvalCaseValues["expected_action"]>(initial?.expected_action ?? "reply");
  const [reason, setReason] = useState<string>(initial?.expected_reason ?? "");
  const [forbidden, setForbidden] = useState((initial?.must_not_contain ?? []).join("\n"));
  const [hasOrder, setHasOrder] = useState(initial?.order_facts != null);
  const [facts, setFacts] = useState<OrderFacts>(initial?.order_facts ?? { payment_status: "paid", delivery_status: "processing", minutes_since_paid: 5 });
  const [notes, setNotes] = useState(initial?.notes ?? "");
  const [active, setActive] = useState(initial?.is_active ?? true);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const num = (v: string) => (v === "" ? null : Number(v));

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await onSubmit({
        title: title.trim(),
        kind,
        messages: lines(messages),
        affiliate_id: initial?.affiliate_id ?? null,
        order_facts: hasOrder ? facts : null,
        expected_action: action,
        expected_reason: action === "handover" && reason ? (reason as HandoverReason) : null,
        must_not_contain: lines(forbidden).length ? lines(forbidden) : null,
        notes: notes.trim() || null,
        is_active: active,
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      {error && <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>}
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div className="sm:col-span-2">
            <Label htmlFor="ev-title">Title</Label>
            <Input id="ev-title" value={title} onChange={(e) => setTitle(e.target.value)} maxLength={120} required />
          </div>
          <div>
            <Label htmlFor="ev-kind">Kind</Label>
            <SimpleSelect id="ev-kind" value={kind} onChange={(v) => setKind(v as EvalCaseValues["kind"])} options={[{ value: "safety", label: "Safety (must pass)" }, { value: "quality", label: "Quality" }]} />
          </div>
        </div>
        <div>
          <Label htmlFor="ev-messages">Customer messages (one per line, already masked)</Label>
          <Textarea id="ev-messages" value={messages} onChange={(e) => setMessages(e.target.value)} rows={3} required />
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label htmlFor="ev-action">Expected</Label>
            <SimpleSelect id="ev-action" value={action} onChange={(v) => setAction(v as EvalCaseValues["expected_action"])} options={[{ value: "reply", label: "Reply" }, { value: "handover", label: "Hand over to staff" }, { value: "any", label: "Either (only no leaks matter)" }]} />
          </div>
          {action === "handover" && (
            <div>
              <Label htmlFor="ev-reason">Expected reason (optional)</Label>
              <SimpleSelect id="ev-reason" value={reason} onChange={setReason} options={[{ value: "", label: "Any" }, ...opts(HANDOVER_REASONS)]} />
            </div>
          )}
        </div>
        <div>
          <Label htmlFor="ev-forbidden">The reply must not contain (one per line)</Label>
          <Textarea id="ev-forbidden" value={forbidden} onChange={(e) => setForbidden(e.target.value)} rows={2} />
        </div>
        <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
          <div className="flex items-center gap-3">
            <Switch checked={hasOrder} onChange={setHasOrder} ariaLabel="Case has an order" />
            <span className="text-sm text-ink">The customer has an order (facts the system would look up)</span>
          </div>
          {hasOrder && (
            <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
              <div>
                <Label htmlFor="ev-pay">Payment</Label>
                <SimpleSelect id="ev-pay" value={facts.payment_status} onChange={(v) => setFacts({ ...facts, payment_status: v as OrderFacts["payment_status"] })} options={opts(PAYMENT_STATUSES)} />
              </div>
              <div>
                <Label htmlFor="ev-del">Delivery</Label>
                <SimpleSelect id="ev-del" value={facts.delivery_status} onChange={(v) => setFacts({ ...facts, delivery_status: v as OrderFacts["delivery_status"] })} options={opts(DELIVERY_STATUSES)} />
              </div>
              <div>
                <Label htmlFor="ev-min">Minutes since paid</Label>
                <Input id="ev-min" type="number" min={0} value={facts.minutes_since_paid ?? ""} onChange={(e) => setFacts({ ...facts, minutes_since_paid: num(e.target.value) })} />
              </div>
              <div>
                <Label htmlFor="ev-exp">Expected minutes (manual game)</Label>
                <Input id="ev-exp" type="number" min={1} value={facts.expected_minutes ?? ""} onChange={(e) => setFacts({ ...facts, expected_minutes: num(e.target.value) })} />
              </div>
              <div>
                <Label htmlFor="ev-pkg">Package</Label>
                <Input id="ev-pkg" value={facts.package ?? ""} onChange={(e) => setFacts({ ...facts, package: e.target.value || null })} />
              </div>
              <div>
                <Label htmlFor="ev-game">Game</Label>
                <Input id="ev-game" value={facts.game ?? ""} onChange={(e) => setFacts({ ...facts, game: e.target.value || null })} />
              </div>
            </div>
          )}
        </div>
        <div>
          <Label htmlFor="ev-notes">Notes</Label>
          <Input id="ev-notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
        </div>
        <div className="flex items-center gap-3">
          <Switch checked={active} onChange={setActive} ariaLabel="Active" />
          <span className="text-sm text-ink">Used in the next run</span>
        </div>
        <div className="flex items-center justify-end gap-3 pt-2">
          <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" disabled={submitting}>
            {submitting ? "Saving…" : "Save"}
          </Button>
        </div>
      </form>
    </>
  );
}

export default function EvalCaseModal({ editing, onClose, onSubmit }: Props) {
  return (
    <Dialog open={editing !== null} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-2xl">
            <DialogHeader>
              <DialogTitle>{editing === "new" ? "New test case" : "Edit test case"}</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {editing !== null && <Fields initial={editing === "new" ? null : editing} onClose={onClose} onSubmit={onSubmit} />}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
