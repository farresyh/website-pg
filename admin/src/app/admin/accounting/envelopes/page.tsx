"use client";

/**
 * ADR-083 Envelope Ledger (2026-09-28 addendum), reshaped by the
 * 2026-10-10 addendum: each action is one posting with lines into or out
 * of envelopes, and the company's loan balance per director is computed
 * from those postings. An envelope is an allocation — what money is for —
 * never where it sits: supplier top-ups and CHIP payouts are recorded in
 * their own modules, never here. Not a double-entry engine.
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Tag } from "@/components/ui/tag";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import {
  createBudgetEnvelope,
  downloadEnvelopeExport,
  formatRm,
  getBudgetEnvelopeEntries,
  getBudgetEnvelopes,
  updateBudgetEnvelope,
  type BudgetEnvelope,
  type BudgetEnvelopeEntry,
  type BudgetEnvelopeIndex,
} from "@/lib/budget-envelopes";
import { PostingForm } from "@/components/accounting/envelopes/PostingForm";
import { EntriesTable } from "@/components/accounting/envelopes/EntriesTable";
import { AllocateProfitForm } from "@/components/accounting/envelopes/AllocateProfitForm";

export default function EnvelopeLedgerPage() {
  const router = useRouter();
  const session = useClientSession();
  const token = session?.token ?? null;

  const [index, setIndex] = useState<BudgetEnvelopeIndex | null>(null);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [entries, setEntries] = useState<BudgetEnvelopeEntry[]>([]);
  const [error, setError] = useState<string | null>(null);

  const [newEnvelopeName, setNewEnvelopeName] = useState("");
  const [showNewEnvelope, setShowNewEnvelope] = useState(false);
  const [showArchived, setShowArchived] = useState(false);
  const [renaming, setRenaming] = useState(false);
  const [renameValue, setRenameValue] = useState("");
  const [panel, setPanel] = useState<"record" | "allocate" | null>(null);

  function loadIndex(t: string) {
    return getBudgetEnvelopes(t)
      .then((data) => {
        setIndex(data);
        setSelectedId((current) => current ?? data.envelopes.find((e) => e.is_active)?.id ?? data.envelopes[0]?.id ?? null);
      })
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load envelopes."));
  }

  function loadEntries(t: string, envelopeId: number) {
    return getBudgetEnvelopeEntries(t, envelopeId)
      .then((data) => setEntries(data.entries))
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load entries."));
  }

  async function reload() {
    if (!token) return;
    setError(null);
    await Promise.all([loadIndex(token), selectedId ? loadEntries(token, selectedId) : Promise.resolve()]);
  }

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    loadIndex(s.token);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (token && selectedId) loadEntries(token, selectedId);
  }, [token, selectedId]);

  const envelopes = index?.envelopes ?? [];
  const selectedEnvelope = envelopes.find((e) => e.id === selectedId) ?? null;

  async function run(action: () => Promise<unknown>, fallback: string) {
    if (!token) return;
    setError(null);
    try {
      await action();
      await reload();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : fallback);
    }
  }

  async function handleCreateEnvelope(e: React.FormEvent) {
    e.preventDefault();
    if (!token || !newEnvelopeName.trim()) return;
    await run(async () => {
      await createBudgetEnvelope(token, newEnvelopeName.trim());
      setNewEnvelopeName("");
      setShowNewEnvelope(false);
    }, "Could not create envelope.");
  }

  async function handleRename(e: React.FormEvent) {
    e.preventDefault();
    if (!token || !selectedEnvelope || !renameValue.trim()) return;
    await run(async () => {
      await updateBudgetEnvelope(token, selectedEnvelope.id, { name: renameValue.trim() });
      setRenaming(false);
    }, "Could not rename this envelope.");
  }

  /** Never a hard delete. Archiving needs a zero balance; the backend explains if not. */
  function handleToggleArchive(envelope: BudgetEnvelope) {
    if (!token) return;
    return run(() => updateBudgetEnvelope(token, envelope.id, { is_active: !envelope.is_active }), "Could not update this envelope.");
  }

  async function handleExport() {
    if (!token) return;
    try {
      await downloadEnvelopeExport(token);
    } catch {
      setError("Export failed.");
    }
  }

  if (!token || !index) {
    return <p className="text-sm text-gray-500 dark:text-gray-400">{error ?? "Loading…"}</p>;
  }

  const owedTotal = index.loan_balances.reduce((sum, b) => sum + b.balance_sen, 0);

  return (
    <div>
      <div className="mb-6 flex items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Envelope Ledger</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            What the company&apos;s money is set aside for. Supplier top-ups and CHIP payouts move money between places, not between envelopes, so they are never recorded here.
          </p>
        </div>
        <button type="button" onClick={() => void handleExport()} className="whitespace-nowrap text-theme-sm text-brand-500 hover:underline">
          Export CSV →
        </button>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {envelopes.filter((e) => e.is_active).map((envelope) => (
          <button
            key={envelope.id}
            type="button"
            onClick={() => setSelectedId(envelope.id)}
            className={`rounded-2xl border p-4 text-left transition ${
              selectedId === envelope.id
                ? "border-brand-500 bg-brand-50 dark:border-brand-400 dark:bg-brand-500/10"
                : "border-gray-200 bg-white hover:border-gray-300 dark:border-gray-800 dark:bg-white/[0.03]"
            }`}
          >
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">{envelope.name}</p>
            <p className={`mt-1 text-xl font-semibold ${envelope.balance_sen < 0 ? "text-error-600 dark:text-error-400" : "text-gray-800 dark:text-white/90"}`}>
              {formatRm(envelope.balance_sen)}
            </p>
            {envelope.balance_sen < 0 && <p className="mt-1 text-theme-xs text-error-600 dark:text-error-400">Over budget — transfer money in.</p>}
          </button>
        ))}
      </div>

      <div className="mb-6 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
        <p className="text-theme-xs font-medium text-gray-600 dark:text-gray-400">
          Owed to directors (loans, and expenses they paid themselves) — <span className="font-mono">{formatRm(owedTotal)}</span>
        </p>
        <div className="mt-2 flex flex-wrap gap-x-8 gap-y-1 text-theme-sm">
          {index.loan_balances.map((b) => (
            <span key={b.counterparty} className="text-gray-700 dark:text-gray-300">
              {b.label}: <span className="font-mono font-medium">{formatRm(b.balance_sen)}</span>
            </span>
          ))}
        </div>
      </div>

      <div className="mb-6 flex items-center gap-4">
        {!showNewEnvelope ? (
          <button type="button" onClick={() => setShowNewEnvelope(true)} className="text-theme-sm text-brand-500 hover:underline">
            + New envelope
          </button>
        ) : (
          <form onSubmit={handleCreateEnvelope} className="flex items-end gap-2">
            <div>
              <Label htmlFor="new_envelope_name">Envelope name</Label>
              <Input id="new_envelope_name" value={newEnvelopeName} onChange={(e) => setNewEnvelopeName(e.target.value)} placeholder="Staff Bonus" />
            </div>
            <Button type="submit" size="small">Create</Button>
            <Button type="button" size="small" variant="outlined" onClick={() => setShowNewEnvelope(false)}>Cancel</Button>
          </form>
        )}
        {envelopes.some((e) => !e.is_active) && (
          <button type="button" onClick={() => setShowArchived((v) => !v)} className="text-theme-sm text-gray-400 hover:underline">
            {showArchived ? "Hide" : "Show"} archived ({envelopes.filter((e) => !e.is_active).length})
          </button>
        )}
      </div>

      {showArchived && (
        <div className="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {envelopes.filter((e) => !e.is_active).map((envelope) => (
            <div key={envelope.id} className="flex items-center justify-between rounded-lg border border-dashed border-gray-200 p-3 text-theme-sm dark:border-gray-800">
              <button type="button" onClick={() => setSelectedId(envelope.id)} className="text-left text-gray-500 hover:underline dark:text-gray-400">
                {envelope.name} — {formatRm(envelope.balance_sen)}
              </button>
              <Button type="button" size="small" variant="outlined" onClick={() => handleToggleArchive(envelope)}>Reactivate</Button>
            </div>
          ))}
        </div>
      )}

      {selectedEnvelope && (
        <>
          <div className="mb-4 flex items-center justify-between">
            {!renaming ? (
              <span className="flex items-center gap-2">
                <h2 className="text-base font-semibold text-gray-800 dark:text-white/90">{selectedEnvelope.name}</h2>
                {!selectedEnvelope.is_active && <Tag severity="secondary">Archived</Tag>}
                <button
                  type="button"
                  onClick={() => { setRenaming(true); setRenameValue(selectedEnvelope.name); }}
                  className="text-theme-xs text-gray-400 hover:underline"
                >
                  Rename
                </button>
                <button type="button" onClick={() => handleToggleArchive(selectedEnvelope)} className="text-theme-xs text-gray-400 hover:underline">
                  {selectedEnvelope.is_active ? "Archive" : "Reactivate"}
                </button>
              </span>
            ) : (
              <form onSubmit={handleRename} className="flex items-center gap-2">
                <Input value={renameValue} onChange={(e) => setRenameValue(e.target.value)} className="h-9 w-48" />
                <Button type="submit" size="small">Save</Button>
                <Button type="button" size="small" variant="outlined" onClick={() => setRenaming(false)}>Cancel</Button>
              </form>
            )}
            <div className="flex gap-2">
              <Button size="small" variant="outlined" onClick={() => setPanel((p) => (p === "allocate" ? null : "allocate"))}>
                Allocate Monthly Profit
              </Button>
              <Button size="small" onClick={() => setPanel((p) => (p === "record" ? null : "record"))}>
                {panel === "record" ? "Cancel" : "Record"}
              </Button>
            </div>
          </div>

          {panel === "allocate" && <AllocateProfitForm token={token} index={index} onAllocated={async () => { setPanel(null); await reload(); }} />}
          {panel === "record" && (
            <PostingForm
              key={selectedEnvelope.id}
              token={token}
              index={index}
              defaultEnvelopeId={selectedEnvelope.is_active ? selectedEnvelope.id : null}
              onRecorded={async () => { setPanel(null); await reload(); }}
            />
          )}

          <EntriesTable token={token} entries={entries} onChanged={reload} onError={setError} />
        </>
      )}
    </div>
  );
}
