"use client";

/**
 * ADR-136 decision 28 (PR 3a): run every model on every active case, then
 * pick the most natural safe answer per case without knowing which model
 * wrote it. Reveal shows model names, safety failures, picks and cost.
 */

import { useCallback, useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { SimpleSelect } from "@/components/ui/select";
import { notify } from "@/components/ui/toast";
import { StatusText } from "@/components/orders/StatusText";
import { ApiError } from "@/lib/api-client";
import { getRun, listRuns, pickAnswer, revealRun, startRun, usd, type RunDetail, type RunRow } from "@/lib/cs-eval";

const message = (err: unknown, fallback: string) => (err instanceof ApiError ? err.message : fallback);

export default function ModelTestPanel() {
  const [runs, setRuns] = useState<RunRow[] | null>(null);
  const [configured, setConfigured] = useState(true);
  const [runId, setRunId] = useState<number | null>(null);
  const [run, setRun] = useState<RunDetail | null>(null);
  const [busy, setBusy] = useState(false);

  const loadRuns = useCallback(
    () =>
      listRuns().then(
        (r) => {
          setRuns(r.data);
          setConfigured(r.configured);
          setRunId((id) => id ?? r.data[0]?.id ?? null);
        },
        (err: unknown) => notify.error(message(err, "Could not load runs.")),
      ),
    [],
  );

  useEffect(() => {
    loadRuns();
  }, [loadRuns]);

  useEffect(() => {
    if (runId === null) return;
    let stop = false;
    let timer: ReturnType<typeof setTimeout>;
    const tick = () =>
      getRun(runId).then((r) => {
        if (stop) return;
        setRun(r);
        if (r.completed < r.total) timer = setTimeout(tick, 4000);
      });
    tick();
    return () => {
      stop = true;
      clearTimeout(timer);
    };
  }, [runId]);

  async function start() {
    setBusy(true);
    try {
      const { id } = await startRun();
      notify.success("Run started");
      setRun(null);
      setRunId(id);
      await loadRuns();
    } catch (err) {
      notify.error(message(err, "Could not start a run."));
    } finally {
      setBusy(false);
    }
  }

  async function pick(resultId: number) {
    if (!run) return;
    try {
      await pickAnswer(run.id, resultId);
      setRun(await getRun(run.id));
    } catch (err) {
      notify.error(message(err, "Could not pick."));
    }
  }

  async function reveal() {
    if (!run || !window.confirm("Reveal which model wrote each answer? Do this after you have picked.")) return;
    setRun(await revealRun(run.id));
    await loadRuns();
  }

  const picked = run?.cases.filter((c) => c.answers.some((a) => a.picked)).length ?? 0;
  const pickable = run?.cases.filter((c) => c.answers.some((a) => a.safety_passed)).length ?? 0;

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-3">
          {runs && runs.length > 0 && (
            <SimpleSelect
              value={runId ? String(runId) : ""}
              onChange={(v) => {
                setRun(null);
                setRunId(Number(v));
              }}
              options={runs.map((r) => ({ value: String(r.id), label: `Run #${r.id} · ${new Date(r.created_at).toLocaleString()}${r.revealed_at ? " · revealed" : ""}` }))}
              className="min-w-[260px]"
            />
          )}
          {run && (
            <span className="text-theme-xs text-ink-muted">
              {run.completed < run.total ? `Running: ${run.completed} of ${run.total} answers` : `${run.cases_count} cases · ${run.knowledge_count} knowledge entries · picked ${picked} of ${pickable}`}
            </span>
          )}
        </div>
        <div className="flex gap-2">
          {run && !run.revealed_at && run.completed === run.total && (
            <Button size="small" variant="outlined" onClick={reveal}>
              Reveal models
            </Button>
          )}
          <Button size="small" onClick={start} disabled={busy || !configured}>
            {busy ? "Starting…" : "Start a run"}
          </Button>
        </div>
      </div>

      {!configured && <p className="mb-4 rounded-lg bg-warning-50 px-4 py-3 text-sm text-warning-600 dark:bg-warning-500/15 dark:text-warning-400">The Gemini API key is not set on this server, so no run can start.</p>}

      {run?.summary && (
        <div className="mb-6 overflow-x-auto rounded-2xl border border-gray-200 bg-surface dark:border-gray-800">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-gray-100 text-start text-theme-xs text-ink-muted dark:border-gray-800">
                {["Model", "Safety cases failed", "All checks failed", "Your picks", "Cost of this run", "Avg time"].map((h) => (
                  <th key={h} className="px-5 py-3 text-start font-medium">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
              {run.summary.map((s) => (
                <tr key={s.model}>
                  <td className="px-5 py-3 font-medium text-ink">{s.model}</td>
                  <td className="px-5 py-3">
                    <StatusText severity={s.safety_failed === 0 ? "success" : "danger"}>{s.safety_failed === 0 ? "None" : s.safety_failed}</StatusText>
                  </td>
                  <td className="px-5 py-3 text-ink-muted">{s.checks_failed}</td>
                  <td className="px-5 py-3 text-ink-muted">{s.picked}</td>
                  <td className="px-5 py-3 text-ink-muted">{usd(s.cost_usd_micros)}</td>
                  <td className="px-5 py-3 text-ink-muted">{(s.avg_latency_ms / 1000).toFixed(1)} s</td>
                </tr>
              ))}
            </tbody>
          </table>
          <p className="px-5 pb-4 text-theme-xs text-ink-muted">A model with any failed safety case is out. Among the rest, the one you picked most wins; a near tie goes to the cheaper.</p>
        </div>
      )}

      <div className="space-y-4">
        {run?.cases.map(({ case: c, answers }) => (
          <div key={c.id} className="rounded-2xl border border-gray-200 bg-surface p-4 dark:border-gray-800">
            <div className="mb-3">
              <p className="text-sm font-medium text-ink">
                {c.title}
                <span className="ml-2 text-theme-xs font-normal text-ink-muted">
                  {c.kind === "safety" ? "Safety" : "Quality"} · expected: {c.expected_action === "reply" ? "reply" : c.expected_action === "any" ? "reply or hand over" : `hand over${c.expected_reason ? ` (${c.expected_reason.replaceAll("_", " ")})` : ""}`}
                </span>
              </p>
              {c.messages.map((m, i) => (
                <p key={i} className="text-sm text-ink-muted">
                  “{m}”
                </p>
              ))}
              {c.order_facts && (
                <p className="text-theme-xs text-ink-subtle">
                  Order: {c.order_facts.payment_status}, {c.order_facts.delivery_status.replaceAll("_", " ")}
                  {c.order_facts.minutes_since_paid != null ? `, paid ${c.order_facts.minutes_since_paid} min ago` : ""}
                </p>
              )}
            </div>
            <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
              {answers.map((a) => (
                <div
                  key={a.id}
                  className={`rounded-xl border p-3 ${a.picked ? "border-cyan-600 bg-cyan-50/40 dark:bg-cyan-500/10" : "border-gray-200 dark:border-gray-800"}`}
                >
                  <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <span className="text-theme-xs font-semibold text-ink">
                      Answer {a.label}
                      {a.model ? ` · ${a.model}` : ""}
                    </span>
                    {!a.done ? (
                      <span className="text-theme-xs text-ink-muted">Waiting…</span>
                    ) : (
                      <StatusText severity={a.safety_passed ? "success" : "danger"}>{a.safety_passed ? "Checks passed" : "Failed checks"}</StatusText>
                    )}
                  </div>
                  {a.done && (
                    <>
                      <p className="text-theme-xs text-ink-subtle">{a.action === "reply" ? "Replies" : `Hands over (${(a.handover_reason ?? "").replaceAll("_", " ")})`}</p>
                      <p className="mt-1 whitespace-pre-line text-sm text-ink">{a.reply ?? "(no message)"}</p>
                      {a.safety_notes.length > 0 && (
                        <ul className="mt-2 space-y-0.5 text-theme-xs text-error-600 dark:text-error-400">
                          {a.safety_notes.map((n, i) => (
                            <li key={i}>{n}</li>
                          ))}
                        </ul>
                      )}
                      {a.model && (
                        <p className="mt-2 text-theme-xs text-ink-subtle">
                          {usd(a.cost_usd_micros ?? 0)} · {((a.latency_ms ?? 0) / 1000).toFixed(1)} s
                        </p>
                      )}
                      {a.safety_passed && !run.revealed_at && (
                        <Button size="small" variant={a.picked ? undefined : "outlined"} className="mt-3" onClick={() => pick(a.id)}>
                          {a.picked ? "Picked" : "Pick this one"}
                        </Button>
                      )}
                    </>
                  )}
                </div>
              ))}
            </div>
          </div>
        ))}
        {runs?.length === 0 && <p className="rounded-2xl border border-gray-200 bg-surface p-6 text-center text-sm text-ink-muted dark:border-gray-800">No runs yet. Approve some knowledge and add test cases first, then start a run.</p>}
      </div>
    </div>
  );
}
