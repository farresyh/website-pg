"use client";

/**
 * ADR-136 decisions 18 and 27 (PR 3b): Shadow review. Per category, how often
 * the assistant's drafts matched what staff replied, and whether the category
 * meets the bar for Live (only a mark; switching Live is PR 4). Below, each
 * draft beside staff's reply with the judge's grade, which you can overrule.
 */

import { useCallback, useEffect, useState } from "react";
import { SimpleSelect } from "@/components/ui/select";
import { Button } from "@/components/ui/button";
import { notify } from "@/components/ui/toast";
import { StatusText } from "@/components/orders/StatusText";
import { ApiError } from "@/lib/api-client";
import { CATEGORIES, categoryLabel } from "@/lib/cs-knowledge";
import { GRADES, getAccuracy, gradeDraft, gradeLabel, listDrafts, type AccuracyRow, type Draft, type Grade } from "@/lib/cs-shadow";

const GRADE_SEVERITY: Record<Grade, "success" | "warn" | "danger" | "secondary" | "info"> = {
  same: "success",
  partly: "info",
  correct_handover: "success",
  different: "warn",
  should_have_handed_over: "danger",
  unclear: "secondary",
};

const FILTER_GRADES = [{ value: "", label: "Every grade" }, ...GRADES, { value: "ungraded", label: "Not graded yet" }, { value: "no_staff_answer", label: "Staff never replied" }];

export default function ShadowPanel() {
  const [accuracy, setAccuracy] = useState<AccuracyRow[] | null>(null);
  const [drafts, setDrafts] = useState<Draft[] | null>(null);
  const [meta, setMeta] = useState<{ current_page: number; last_page: number; total: number } | null>(null);
  const [page, setPage] = useState(1);
  const [category, setCategory] = useState("");
  const [grade, setGrade] = useState("");
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    Promise.all([getAccuracy(), listDrafts(page, category, grade)]).then(
      ([a, d]) => {
        setAccuracy(a.data);
        setDrafts(d.data);
        setMeta(d.meta);
        setError(null);
      },
      (err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load Shadow drafts."),
    );
  }, [page, category, grade]);

  useEffect(() => {
    load();
  }, [load]);

  async function overrule(d: Draft, value: string) {
    try {
      const r = await gradeDraft(d.id, value === "" ? null : (value as Grade));
      setDrafts((list) => list?.map((x) => (x.id === d.id ? { ...x, admin_grade: r.admin_grade, final_grade: r.final_grade } : x)) ?? null);
      notify.success("Grade saved");
      getAccuracy().then((a) => setAccuracy(a.data), () => undefined);
    } catch (err) {
      notify.error(err instanceof ApiError ? err.message : "Could not save the grade.");
    }
  }

  if (error) return <p className="rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>;

  return (
    <div className="space-y-6">
      <div className="overflow-x-auto rounded-2xl border border-gray-200 bg-surface dark:border-gray-800">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-gray-100 text-theme-xs text-ink-muted dark:border-gray-800">
              {["Category", "Graded drafts", "Right", "Accuracy", "Should have handed over", "Blocked by guard", "Ready for Live"].map((h) => (
                <th key={h} className="px-5 py-3 text-start font-medium">
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
            {(accuracy ?? []).map((r) => (
              <tr key={r.category}>
                <td className="px-5 py-3 text-ink">{categoryLabel(r.category)}</td>
                <td className="px-5 py-3 text-ink-muted">{r.graded}</td>
                <td className="px-5 py-3 text-ink-muted">{r.right}</td>
                <td className="px-5 py-3 text-ink-muted">{r.accuracy_pct === null ? "—" : `${r.accuracy_pct}%`}</td>
                <td className="px-5 py-3 text-ink-muted">{r.should_have_handed_over}</td>
                <td className="px-5 py-3 text-ink-muted">{r.guard_hits}</td>
                <td className="px-5 py-3">{r.ready ? <StatusText severity="success">Ready</StatusText> : <span className="text-ink-subtle">Not yet</span>}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {accuracy === null && <p className="p-6 text-center text-sm text-ink-muted">Loading…</p>}
      </div>

      <div className="flex flex-wrap items-end gap-3">
        <div className="w-full sm:w-56">
          <SimpleSelect
            value={category}
            onChange={(v) => {
              setCategory(v);
              setPage(1);
            }}
            options={[{ value: "", label: "Every category" }, ...CATEGORIES.map((c) => ({ value: c.value, label: c.label }))]}
          />
        </div>
        <div className="w-full sm:w-56">
          <SimpleSelect
            value={grade}
            onChange={(v) => {
              setGrade(v);
              setPage(1);
            }}
            options={FILTER_GRADES.map((g) => ({ value: g.value, label: g.label }))}
          />
        </div>
        <Button size="small" variant="outlined" onClick={load}>
          Refresh
        </Button>
      </div>

      <div className="space-y-3">
        {(drafts ?? []).map((d) => (
          <div key={d.id} className="rounded-2xl border border-gray-200 bg-surface p-4 dark:border-gray-800">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2 text-theme-xs text-ink-muted">
              <span>
                {new Date(d.asked_at).toLocaleString()} · {d.brand ?? "Unknown brand"} · {d.category ? categoryLabel(d.category) : "No category"}
                {d.order_numbers?.length ? ` · ${d.order_numbers.join(", ")}` : ""}
              </span>
              <span className="flex items-center gap-2">
                {d.final_grade ? <StatusText severity={GRADE_SEVERITY[d.final_grade]}>{gradeLabel(d.final_grade)}</StatusText> : <span>{d.judged_at ? "Staff never replied" : "Not graded yet"}</span>}
                {d.admin_grade && <span className="text-ink-subtle">(your grade)</span>}
              </span>
            </div>
            <div className="grid gap-3 md:grid-cols-3">
              <div>
                <p className="mb-1 text-theme-xs font-medium text-ink-muted">Customer</p>
                <p className="text-theme-sm whitespace-pre-line text-ink">{d.question}</p>
              </div>
              <div>
                <p className="mb-1 text-theme-xs font-medium text-ink-muted">Assistant&apos;s draft (not sent)</p>
                {d.action === "handover" && (
                  <p className="mb-1 text-theme-xs text-warning-600 dark:text-warning-400">Handed over: {d.handover_reason}</p>
                )}
                <p className="text-theme-sm whitespace-pre-line text-ink">{d.reply ?? "—"}</p>
                {d.violations?.length ? <p className="mt-1 text-theme-xs text-error-600 dark:text-error-400">Blocked: {d.violations.join(", ")}</p> : null}
                {d.error && <p className="mt-1 text-theme-xs text-error-600 dark:text-error-400">{d.error}</p>}
              </div>
              <div>
                <p className="mb-1 text-theme-xs font-medium text-ink-muted">Staff replied</p>
                <p className="text-theme-sm whitespace-pre-line text-ink-muted">{d.staff_answer ?? "—"}</p>
              </div>
            </div>
            {(d.grade_note || d.judged_at) && (
              <div className="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-3 dark:border-gray-800">
                <p className="text-theme-xs text-ink-muted">{d.grade_note ? `Judge: ${d.grade_note}` : ""}</p>
                <div className="w-full sm:w-56">
                  <SimpleSelect
                    value={d.admin_grade ?? ""}
                    onChange={(v) => overrule(d, v)}
                    options={[{ value: "", label: `Judge's grade (${gradeLabel(d.grade)})` }, ...GRADES.map((g) => ({ value: g.value, label: g.label }))]}
                  />
                </div>
              </div>
            )}
          </div>
        ))}
        {drafts !== null && drafts.length === 0 && (
          <p className="rounded-2xl border border-gray-200 bg-surface p-6 text-center text-sm text-ink-muted dark:border-gray-800">
            No drafts here. Switch the assistant to Shadow in Settings, and drafts appear as customers write in.
          </p>
        )}
      </div>

      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm text-ink-muted">
          <span>
            Page {meta.current_page} of {meta.last_page} ({meta.total} total)
          </span>
          <div className="flex gap-2">
            <Button size="small" variant="outlined" disabled={meta.current_page <= 1} onClick={() => setPage((p) => p - 1)}>
              Previous
            </Button>
            <Button size="small" variant="outlined" disabled={meta.current_page >= meta.last_page} onClick={() => setPage((p) => p + 1)}>
              Next
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
