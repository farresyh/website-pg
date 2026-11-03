"use client";

/**
 * ADR-136 decisions 7, 11, 22, 25, 27 and 33 (PR 3b): the assistant's settings.
 * Off stops everything; Shadow drafts answers and never sends them. Live is PR 4.
 */

import { useEffect, useState } from "react";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { SimpleSelect } from "@/components/ui/select";
import { Button } from "@/components/ui/button";
import { notify } from "@/components/ui/toast";
import { ApiError } from "@/lib/api-client";
import { getSettings, saveSettings, type AssistantSettings, type SettingsResponse } from "@/lib/cs-shadow";

type NumberKey =
  | "reassure_minutes"
  | "handover_minutes"
  | "manual_handover_minutes"
  | "burst_wait_seconds"
  | "daily_cost_cap_sen"
  | "live_min_drafts"
  | "live_min_accuracy_pct";

const NUMBERS: { key: NumberKey; label: string; hint: string }[] = [
  { key: "reassure_minutes", label: "Reassure until (minutes after paid)", hint: "Below this, a paid order that has not arrived is simply reassured." },
  { key: "handover_minutes", label: "Hand over after (minutes after paid)", hint: "Past this, a paid order that has not arrived goes to staff." },
  { key: "manual_handover_minutes", label: "Manual games: hand over after (minutes)", hint: "Manual and joki games take longer, so they get their own limit." },
  { key: "burst_wait_seconds", label: "Wait for the customer to finish (seconds)", hint: "One answer for the whole burst of messages." },
  { key: "daily_cost_cap_sen", label: "Daily cost cap (sen)", hint: "Reaching it switches the assistant Off for the rest of the day." },
  { key: "live_min_drafts", label: "Ready for Live: at least this many graded drafts", hint: "Per category." },
  { key: "live_min_accuracy_pct", label: "Ready for Live: at least this % right", hint: "Same meaning, partly right or correct handover." },
];

const rm = (sen: number) => `RM${(sen / 100).toFixed(2)}`;

export default function AssistantSettingsPanel() {
  const [res, setRes] = useState<SettingsResponse | null>(null);
  const [form, setForm] = useState<AssistantSettings | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    getSettings().then(
      (r) => {
        setRes(r);
        setForm(r.data);
      },
      (err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load the settings."),
    );
  }, []);

  async function save() {
    if (!form) return;
    setBusy(true);
    try {
      const r = await saveSettings(form);
      setRes(r);
      setForm(r.data);
      notify.success("Settings saved");
    } catch (err) {
      notify.error(err instanceof ApiError ? err.message : "Could not save.");
    } finally {
      setBusy(false);
    }
  }

  if (error) return <p className="rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>;
  if (!res || !form) return <p className="p-6 text-center text-sm text-ink-muted">Loading…</p>;

  const models = res.models.map((m) => ({ value: m, label: m }));

  return (
    <div className="max-w-3xl space-y-6">
      {res.data.cost_cap_tripped_at && res.data.mode === "off" && (
        <p className="rounded-lg bg-warning-50 px-4 py-3 text-sm text-warning-600 dark:bg-warning-500/15 dark:text-warning-400">
          The daily cost cap switched the assistant Off at {new Date(res.data.cost_cap_tripped_at).toLocaleString()}. Switch it back to Shadow to resume.
        </p>
      )}
      {res.missing_keys.length > 0 && (
        <p className="rounded-lg bg-warning-50 px-4 py-3 text-sm text-warning-600 dark:bg-warning-500/15 dark:text-warning-400">
          Not set on the server: {res.missing_keys.join(", ")}. Nothing is drafted or graded until it is.
        </p>
      )}

      <div className="rounded-2xl border border-gray-200 bg-surface p-5 dark:border-gray-800">
        <div className="grid gap-4 sm:grid-cols-3">
          <div>
            <Label htmlFor="cs-mode">Mode</Label>
            <SimpleSelect
              id="cs-mode"
              value={form.mode}
              onChange={(v) => setForm({ ...form, mode: v as AssistantSettings["mode"] })}
              options={[
                { value: "off", label: "Off" },
                { value: "shadow", label: "Shadow (drafts only)" },
              ]}
            />
          </div>
          <div>
            <Label htmlFor="cs-answer">Answers with</Label>
            <SimpleSelect id="cs-answer" value={form.answer_model} onChange={(v) => setForm({ ...form, answer_model: v })} options={models} />
          </div>
          <div>
            <Label htmlFor="cs-judge">Grades and writes suggestions with</Label>
            <SimpleSelect id="cs-judge" value={form.judge_model} onChange={(v) => setForm({ ...form, judge_model: v })} options={models} />
          </div>
        </div>
        <p className="mt-3 text-theme-xs text-ink-muted">
          In Shadow the assistant writes an answer to every customer message on the support number and sends nothing. Each night its
          drafts are graded against what staff replied. Live (sending answers) comes later. Spent today: {rm(res.spent_today_sen)} of{" "}
          {rm(res.data.daily_cost_cap_sen)}.
        </p>
      </div>

      <div className="grid gap-4 rounded-2xl border border-gray-200 bg-surface p-5 sm:grid-cols-2 dark:border-gray-800">
        {NUMBERS.map(({ key, label, hint }) => (
          <div key={key}>
            <Label htmlFor={`cs-${key}`}>{label}</Label>
            <Input
              id={`cs-${key}`}
              type="number"
              min={1}
              value={String(form[key])}
              onChange={(e) => setForm({ ...form, [key]: Number(e.target.value) })}
            />
            <p className="mt-1 text-theme-xs text-ink-subtle">{hint}</p>
          </div>
        ))}
      </div>

      <div className="flex justify-end">
        <Button onClick={save} disabled={busy}>
          {busy ? "Saving…" : "Save settings"}
        </Button>
      </div>
    </div>
  );
}
