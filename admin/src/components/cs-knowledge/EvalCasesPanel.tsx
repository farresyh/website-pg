"use client";

/**
 * ADR-136 decision 28 (PR 3a): the model-test cases. They live in the
 * database, never in the public repo; Import takes a JSON array pasted in.
 */

import { useCallback, useEffect, useState } from "react";
import {
  Dialog,
  DialogPortal,
  DialogBackdrop,
  DialogPositioner,
  DialogPopup,
  DialogHeader,
  DialogTitle,
  DialogContent,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { notify } from "@/components/ui/toast";
import { StatusText } from "@/components/orders/StatusText";
import { ApiError } from "@/lib/api-client";
import EvalCaseModal from "@/components/cs-knowledge/EvalCaseModal";
import { createEvalCase, importEvalCases, listEvalCases, updateEvalCase, type EvalCase, type EvalCaseValues } from "@/lib/cs-eval";

const message = (err: unknown, fallback: string) => (err instanceof ApiError ? err.message : fallback);

export default function EvalCasesPanel() {
  const [cases, setCases] = useState<EvalCase[] | null>(null);
  const [editing, setEditing] = useState<EvalCase | "new" | null>(null);
  const [importing, setImporting] = useState(false);
  const [json, setJson] = useState("");
  const [importError, setImportError] = useState<string | null>(null);

  const load = useCallback(() => listEvalCases().then((r) => setCases(r.data), (err: unknown) => notify.error(message(err, "Could not load test cases."))), []);

  useEffect(() => {
    load();
  }, [load]);

  async function save(values: EvalCaseValues) {
    if (editing === "new") await createEvalCase(values);
    else if (editing) await updateEvalCase(editing.id, values);
    notify.success("Test case saved");
    setEditing(null);
    await load();
  }

  async function runImport() {
    setImportError(null);
    let parsed: unknown;
    try {
      parsed = JSON.parse(json);
    } catch {
      setImportError("That is not valid JSON.");
      return;
    }
    if (!Array.isArray(parsed)) {
      setImportError("Paste a JSON array of cases.");
      return;
    }
    try {
      const { created } = await importEvalCases(parsed);
      notify.success(`Imported ${created} test cases`);
      setImporting(false);
      setJson("");
      await load();
    } catch (err) {
      setImportError(message(err, "Import failed."));
    }
  }

  const active = cases?.filter((c) => c.is_active).length ?? 0;

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-ink-muted">
          {cases ? `${active} active of ${cases.length}. ` : ""}A safety case must pass for a model to be chosen; quality cases are for your blind pick.
        </p>
        <div className="flex gap-2">
          <Button size="small" variant="outlined" onClick={() => setImporting(true)}>
            Import JSON
          </Button>
          <Button size="small" onClick={() => setEditing("new")}>
            New case
          </Button>
        </div>
      </div>

      <div className="space-y-3">
        {cases?.map((c) => (
          <div key={c.id} className={`rounded-2xl border border-gray-200 bg-surface p-4 dark:border-gray-800 ${c.is_active ? "" : "opacity-60"}`}>
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div className="min-w-0 flex-1">
                <p className="text-sm font-medium text-ink">
                  {c.title}
                  <span className="ml-2 text-theme-xs font-normal text-ink-muted">{c.kind === "safety" ? "Safety" : "Quality"}{c.is_active ? "" : " · inactive"}</span>
                </p>
                <ul className="mt-1 space-y-0.5 text-sm text-ink-muted">
                  {c.messages.map((m, i) => (
                    <li key={i}>“{m}”</li>
                  ))}
                </ul>
                {c.order_facts && (
                  <p className="mt-1 text-theme-xs text-ink-subtle">
                    Order: {c.order_facts.payment_status}, {c.order_facts.delivery_status.replaceAll("_", " ")}
                    {c.order_facts.minutes_since_paid != null ? `, paid ${c.order_facts.minutes_since_paid} min ago` : ""}
                  </p>
                )}
              </div>
              <div className="flex items-center gap-3">
                <StatusText severity={c.expected_action === "handover" ? "warn" : "success"}>
                  {c.expected_action === "reply" ? "Should reply" : c.expected_action === "any" ? "Reply or hand over" : `Should hand over${c.expected_reason ? ` (${c.expected_reason.replaceAll("_", " ")})` : ""}`}
                </StatusText>
                <Button size="small" variant="outlined" onClick={() => setEditing(c)}>
                  Edit
                </Button>
              </div>
            </div>
          </div>
        ))}
        {cases?.length === 0 && <p className="rounded-2xl border border-gray-200 bg-surface p-6 text-center text-sm text-ink-muted dark:border-gray-800">No test cases yet. Import the drafted set or add one.</p>}
        {cases === null && <p className="p-6 text-center text-sm text-ink-muted">Loading…</p>}
      </div>

      <EvalCaseModal editing={editing} onClose={() => setEditing(null)} onSubmit={save} />

      <Dialog open={importing} onOpenChange={(e) => { if (!e.value) setImporting(false); }}>
        <DialogPortal>
          <DialogBackdrop />
          <DialogPositioner>
            <DialogPopup className="w-full max-w-2xl">
              <DialogHeader>
                <DialogTitle>Import test cases</DialogTitle>
              </DialogHeader>
              <DialogContent>
                <p className="mb-3 text-sm text-ink-muted">Paste a JSON array. Each case: title, kind, messages, expected_action, and optionally expected_reason, order_facts, must_not_contain, notes.</p>
                {importError && <p className="mb-3 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{importError}</p>}
                <Textarea value={json} onChange={(e) => setJson(e.target.value)} rows={12} className="font-mono text-xs" aria-label="Cases JSON" />
                <div className="mt-4 flex justify-end gap-3">
                  <Button variant="outlined" onClick={() => setImporting(false)}>
                    Cancel
                  </Button>
                  <Button onClick={runImport} disabled={!json.trim()}>
                    Import
                  </Button>
                </div>
              </DialogContent>
            </DialogPopup>
          </DialogPositioner>
        </DialogPortal>
      </Dialog>
    </div>
  );
}
