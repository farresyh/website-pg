"use client";

/**
 * ADR-136 decisions 2, 10, 16, 17 (PR 2): the CS knowledge book. Each night
 * finished customer-support chats become suggestions (masked customer question
 * plus the staff answer); a super_admin approves, merges, rejects or ignores
 * each one. Only approved entries will ever reach the assistant (PR 3).
 * Laid out like /admin/applications (title row, underline tabs, DataTable).
 */

import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import {
  DataTable,
  DataTableTableContainer,
  DataTableTable,
  DataTableTHead,
  DataTableTHeadRow,
  DataTableTHeadCell,
  DataTableTBody,
  DataTableRow,
  DataTableCell,
} from "@/components/ui/datatable";
import { Button } from "@/components/ui/button";
import { notify } from "@/components/ui/toast";
import { Plus as PlusIcon } from "@primeicons/react/plus";
import { StatusText } from "@/components/orders/StatusText";
import EntryFormModal, { type Option } from "@/components/cs-knowledge/EntryFormModal";
import MergeSuggestionModal from "@/components/cs-knowledge/MergeSuggestionModal";
import EvalCasesPanel from "@/components/cs-knowledge/EvalCasesPanel";
import ModelTestPanel from "@/components/cs-knowledge/ModelTestPanel";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import { listAffiliates } from "@/lib/affiliates";
import { listGames } from "@/lib/games";
import {
  approveSuggestion,
  categoryLabel,
  createEntry,
  ignoreSuggestionChat,
  listEntries,
  listSuggestions,
  mergeSuggestion,
  rejectSuggestion,
  setEntryArchived,
  updateEntry,
  type Entry,
  type EntryStatus,
  type EntryValues,
  type Page,
  type Suggestion,
  type SuggestionStatus,
} from "@/lib/cs-knowledge";

type Tab =
  | { kind: "suggestions"; status: SuggestionStatus | "all" }
  | { kind: "entries"; status: EntryStatus }
  | { kind: "cases"; status?: undefined }
  | { kind: "model-test"; status?: undefined };

const TABS: { tab: Tab; label: string }[] = [
  { tab: { kind: "suggestions", status: "pending" }, label: "To review" },
  { tab: { kind: "suggestions", status: "all" }, label: "All suggestions" },
  { tab: { kind: "entries", status: "approved" }, label: "Knowledge" },
  { tab: { kind: "entries", status: "archived" }, label: "Archived" },
  // ADR-136 decision 28 (PR 3a): the model test.
  { tab: { kind: "cases" }, label: "Test cases" },
  { tab: { kind: "model-test" }, label: "Model test" },
];

const SUGGESTION_STATUS: Record<SuggestionStatus, { label: string; severity: "success" | "warn" | "danger" | "secondary" | "info" }> = {
  pending: { label: "To review", severity: "warn" },
  approved: { label: "Approved", severity: "success" },
  merged: { label: "Merged", severity: "info" },
  rejected: { label: "Rejected", severity: "danger" },
  ignored: { label: "Chat ignored", severity: "secondary" },
};

const TH = "px-5 py-3 text-start text-theme-xs font-medium text-ink-muted";
const BLANK: EntryValues = { category: "other", questions: [], answer: "", affiliate_id: null, game_id: null, valid_until: null };

const sameTab = (a: Tab, b: Tab) => a.kind === b.kind && a.status === b.status;
const message = (err: unknown, fallback: string) => (err instanceof ApiError ? err.message : fallback);

type Editing =
  | { mode: "approve"; suggestion: Suggestion }
  | { mode: "create" }
  | { mode: "edit"; entry: Entry };

export default function CsKnowledgePage() {
  const router = useRouter();
  const [tab, setTab] = useState<Tab>(TABS[0].tab);
  const [pageNumber, setPageNumber] = useState(1);
  const [suggestions, setSuggestions] = useState<Page<Suggestion, SuggestionStatus> | null>(null);
  const [entries, setEntries] = useState<Page<Entry, EntryStatus> | null>(null);
  const [allEntries, setAllEntries] = useState<Entry[]>([]);
  const [brands, setBrands] = useState<Option[]>([]);
  const [games, setGames] = useState<Option[]>([]);
  const [editing, setEditing] = useState<Editing | null>(null);
  const [merging, setMerging] = useState<Suggestion | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    if (tab.kind === "cases" || tab.kind === "model-test") return Promise.resolve();
    const request =
      tab.kind === "suggestions"
        ? listSuggestions(tab.status, pageNumber).then((p) => {
            setSuggestions(p);
            setEntries(null);
          })
        : listEntries(tab.status, pageNumber).then((p) => {
            setEntries(p);
            setSuggestions(null);
          });
    return request.then(() => setError(null), (err: unknown) => setError(message(err, "Could not load CS knowledge.")));
  }, [tab, pageNumber]);

  const loadAllEntries = useCallback(() => listEntries("approved", 1, 500).then((p) => setAllEntries(p.data), () => setAllEntries([])), []);

  useEffect(() => {
    if (!getClientSession()) {
      router.replace("/login");
      return;
    }
    load();
  }, [load, router]);

  useEffect(() => {
    loadAllEntries();
    listAffiliates().then((r) => setBrands(r.affiliates.map((a) => ({ id: a.id, name: a.business_name }))), () => setBrands([]));
    listGames().then((g) => setGames(g.map((x) => ({ id: x.id, name: x.name }))), () => setGames([]));
  }, [loadAllEntries]);

  async function act(id: number, run: () => Promise<unknown>, done: string) {
    setBusyId(id);
    try {
      await run();
      notify.success(done);
      await Promise.all([load(), loadAllEntries()]);
    } catch (err) {
      notify.error(message(err, "Action failed."));
    } finally {
      setBusyId(null);
    }
  }

  async function saveEntry(values: EntryValues) {
    if (!editing) return;
    if (editing.mode === "approve") await approveSuggestion(editing.suggestion.id, values);
    else if (editing.mode === "create") await createEntry(values);
    else await updateEntry(editing.entry.id, values);
    notify.success(editing.mode === "edit" ? "Entry saved" : "Entry approved");
    setEditing(null);
    await Promise.all([load(), loadAllEntries()]);
  }

  const counts = suggestions?.counts ?? {};
  const meta = (suggestions ?? entries)?.meta;
  const rows = tab.kind === "suggestions" ? (suggestions?.data ?? []) : (entries?.data ?? []);
  const loaded = tab.kind === "suggestions" ? suggestions !== null : entries !== null;

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-page-title font-semibold text-ink">CS knowledge</h1>
          <p className="mt-1 max-w-3xl text-sm text-ink-muted">
            What the support assistant will be allowed to say. Every night, finished chats on the support number become
            suggestions: the customer&apos;s question and the staff answer, with phone numbers, names and IDs removed. Nothing
            is used until you approve it.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <Button size="small" variant="outlined" onClick={load}>
            Refresh
          </Button>
          <Button size="small" onClick={() => setEditing({ mode: "create" })}>
            <PlusIcon />
            New entry
          </Button>
        </div>
      </div>

      {error && <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>}

      <div className="mb-4 flex flex-wrap gap-1 border-b border-gray-200 dark:border-gray-800">
        {TABS.map(({ tab: t, label }) => {
          const count = t.kind === "suggestions" && t.status === "pending" ? counts.pending : undefined;
          return (
            <button
              key={label}
              onClick={() => {
                setTab(t);
                setPageNumber(1);
                setSuggestions(null);
                setEntries(null);
              }}
              className={`border-b-2 px-3 py-1.5 text-sm font-medium ${sameTab(tab, t) ? "border-cyan-600 text-cyan-ink" : "border-transparent text-ink-muted hover:text-ink"}`}
            >
              {label}
              {count ? <span className="ml-1.5 text-theme-xs text-ink-subtle">{count}</span> : null}
            </button>
          );
        })}
      </div>

      {tab.kind === "cases" && <EvalCasesPanel />}
      {tab.kind === "model-test" && <ModelTestPanel />}

      {(tab.kind === "suggestions" || tab.kind === "entries") && (
      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-surface dark:border-gray-800">
        <div className="max-w-full overflow-x-auto">
          {tab.kind === "suggestions" ? (
            <DataTable data={rows} dataKey="id">
              <DataTableTableContainer>
                <DataTableTable>
                  <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                    <DataTableTHeadRow>
                      <DataTableTHeadCell className={TH}>Customer asked</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Staff answered</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Brand</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Asked</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Status</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Actions</DataTableTHeadCell>
                    </DataTableTHeadRow>
                  </DataTableTHead>
                  <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                    {({ item }) => {
                      const s = item as unknown as Suggestion;
                      const busy = busyId === s.id;
                      return (
                        <DataTableRow key={s.id}>
                          <DataTableCell className="min-w-[200px] max-w-xs px-5 py-4 align-top text-theme-sm whitespace-pre-line text-ink">{s.question}</DataTableCell>
                          <DataTableCell className="min-w-[200px] max-w-xs px-5 py-4 align-top text-theme-sm whitespace-pre-line text-ink-muted">{s.answer}</DataTableCell>
                          <DataTableCell className="px-5 py-4 align-top text-theme-sm text-ink-muted">{s.affiliate?.business_name ?? "Unknown"}</DataTableCell>
                          <DataTableCell className="px-5 py-4 align-top text-theme-sm text-ink-muted">{new Date(s.asked_at).toLocaleString()}</DataTableCell>
                          <DataTableCell className="px-5 py-4 align-top text-theme-sm">
                            <StatusText severity={SUGGESTION_STATUS[s.status].severity}>{SUGGESTION_STATUS[s.status].label}</StatusText>
                          </DataTableCell>
                          <DataTableCell className="px-5 py-4 align-top text-theme-sm">
                            {s.status === "pending" ? (
                              <div className="flex min-w-[220px] flex-wrap gap-2">
                                <Button size="small" disabled={busy} onClick={() => setEditing({ mode: "approve", suggestion: s })}>
                                  Approve as new
                                </Button>
                                <Button size="small" variant="outlined" disabled={busy} onClick={() => setMerging(s)}>
                                  Merge
                                </Button>
                                <Button size="small" variant="outlined" severity="danger" disabled={busy} onClick={() => act(s.id, () => rejectSuggestion(s.id), "Suggestion rejected")}>
                                  Reject
                                </Button>
                                <Button
                                  size="small"
                                  variant="text"
                                  disabled={busy}
                                  onClick={() => {
                                    if (window.confirm("Ignore this whole chat? Its other suggestions close too, and it is never suggested again. Use this for your own and staff test chats.")) {
                                      act(s.id, () => ignoreSuggestionChat(s.id), "Chat ignored");
                                    }
                                  }}
                                >
                                  Ignore chat
                                </Button>
                              </div>
                            ) : (
                              <span className="text-theme-xs text-ink-muted">
                                {s.reviewer?.name ?? "—"}
                                {s.entry ? ` · ${s.entry.questions[0]}` : ""}
                              </span>
                            )}
                          </DataTableCell>
                        </DataTableRow>
                      );
                    }}
                  </DataTableTBody>
                </DataTableTable>
              </DataTableTableContainer>
            </DataTable>
          ) : (
            <DataTable data={rows} dataKey="id">
              <DataTableTableContainer>
                <DataTableTable>
                  <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                    <DataTableTHeadRow>
                      <DataTableTHeadCell className={TH}>Category</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Questions</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Answer</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Scope</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Approved</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Actions</DataTableTHeadCell>
                    </DataTableTHeadRow>
                  </DataTableTHead>
                  <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                    {({ item }) => {
                      const e = item as unknown as Entry;
                      const expired = e.valid_until !== null && e.valid_until < new Date().toISOString().slice(0, 10);
                      return (
                        <DataTableRow key={e.id}>
                          <DataTableCell className="px-5 py-4 align-top text-theme-sm text-ink">{categoryLabel(e.category)}</DataTableCell>
                          <DataTableCell className="min-w-[200px] max-w-xs px-5 py-4 align-top text-theme-sm text-ink">
                            <ul className="space-y-1">
                              {e.questions.map((q, i) => (
                                <li key={i}>{q}</li>
                              ))}
                            </ul>
                          </DataTableCell>
                          <DataTableCell className="min-w-[240px] max-w-sm px-5 py-4 align-top text-theme-sm whitespace-pre-line text-ink-muted">{e.answer}</DataTableCell>
                          <DataTableCell className="px-5 py-4 align-top text-theme-sm text-ink-muted">
                            {e.affiliate?.business_name ?? "All brands"}
                            <p className="text-theme-xs text-ink-subtle">{e.game?.name ?? "All games"}</p>
                            {e.valid_until && (
                              <p className={`text-theme-xs ${expired ? "text-error-600 dark:text-error-400" : "text-ink-subtle"}`}>
                                {expired ? "Expired" : "Until"} {e.valid_until}
                              </p>
                            )}
                          </DataTableCell>
                          <DataTableCell className="px-5 py-4 align-top text-theme-sm text-ink-muted">
                            {e.approver?.name ?? "—"}
                            <p className="text-theme-xs text-ink-subtle">{e.approved_at ? new Date(e.approved_at).toLocaleDateString() : ""}</p>
                          </DataTableCell>
                          <DataTableCell className="px-5 py-4 align-top text-theme-sm">
                            <div className="flex gap-2">
                              <Button size="small" variant="outlined" onClick={() => setEditing({ mode: "edit", entry: e })}>
                                Edit
                              </Button>
                              <Button
                                size="small"
                                variant="outlined"
                                severity={e.status === "approved" ? "danger" : undefined}
                                disabled={busyId === e.id}
                                onClick={() =>
                                  act(e.id, () => setEntryArchived(e.id, e.status === "approved"), e.status === "approved" ? "Entry archived" : "Entry restored")
                                }
                              >
                                {e.status === "approved" ? "Archive" : "Restore"}
                              </Button>
                            </div>
                          </DataTableCell>
                        </DataTableRow>
                      );
                    }}
                  </DataTableTBody>
                </DataTableTable>
              </DataTableTableContainer>
            </DataTable>
          )}
          {loaded && rows.length === 0 && (
            <p className="p-6 text-center text-sm text-ink-muted">
              {tab.kind === "suggestions" ? "No suggestions here. New ones arrive each night at 03:00." : "No entries here yet."}
            </p>
          )}
          {!loaded && !error && <p className="p-6 text-center text-sm text-ink-muted">Loading…</p>}
        </div>
      </div>
      )}

      {meta && meta.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-sm text-ink-muted">
          <span>
            Page {meta.current_page} of {meta.last_page} ({meta.total} total)
          </span>
          <div className="flex gap-2">
            <Button size="small" variant="outlined" disabled={meta.current_page <= 1} onClick={() => setPageNumber((p) => p - 1)}>
              Previous
            </Button>
            <Button size="small" variant="outlined" disabled={meta.current_page >= meta.last_page} onClick={() => setPageNumber((p) => p + 1)}>
              Next
            </Button>
          </div>
        </div>
      )}

      <EntryFormModal
        isOpen={editing !== null}
        title={editing?.mode === "edit" ? "Edit entry" : editing?.mode === "approve" ? "Approve as a new entry" : "New entry"}
        submitLabel={editing?.mode === "edit" ? "Save" : "Approve"}
        initial={
          editing?.mode === "edit"
            ? editing.entry
            : editing?.mode === "approve"
              ? { ...BLANK, questions: [editing.suggestion.question.split("\n")[0] ?? ""], answer: editing.suggestion.answer }
              : BLANK
        }
        source={editing?.mode === "approve" ? { question: editing.suggestion.question, answer: editing.suggestion.answer } : undefined}
        brands={brands}
        games={games}
        onClose={() => setEditing(null)}
        onSubmit={saveEntry}
      />

      <MergeSuggestionModal
        suggestion={merging}
        entries={allEntries}
        onClose={() => setMerging(null)}
        onSubmit={async (values) => {
          if (!merging) return;
          await mergeSuggestion(merging.id, values);
          notify.success("Merged into the entry");
          setMerging(null);
          await Promise.all([load(), loadAllEntries()]);
        }}
      />
    </div>
  );
}
