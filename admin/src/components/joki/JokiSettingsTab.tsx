"use client";

import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import RichTextEditor from "@/components/ui/RichTextEditor";
import { ApiError } from "@/lib/api-client";
import { getJokiSettings, updateJokiSettings, type JokiSettings, type JokiSettingsResponse } from "@/lib/joki";
import { JOKI_INPUT } from "@/components/joki/RankPicker";
import { JokiRankModelEditor } from "@/components/joki/JokiRankModelEditor";
import { JokiRoutesEditor } from "@/components/joki/JokiRoutesEditor";

const LABEL = "mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300";
const CARD = "rounded-2xl border border-gray-200 bg-surface p-6 dark:border-gray-800";

type NumberKey = "payout_share_pct" | "min_stars" | "grading_price_rm" | "grading_express_price_rm" | "grading_lands_at_stars" | "max_mythic_stars" | "progress_message_interval_minutes";

const NUMBER_FIELDS: { key: NumberKey; label: string; hint: string; step?: string }[] = [
  { key: "payout_share_pct", label: "Payout share (%)", hint: "Of the customer price, paid to the penjoki. Fixed on each order at checkout.", step: "0.01" },
  { key: "min_stars", label: "Minimum stars", hint: "A smaller climb is refused unless it includes Grading." },
  { key: "grading_price_rm", label: "Mythic Grading (RM)", hint: "Flat price; guarantees the star below." },
  { key: "grading_express_price_rm", label: "Mythic Grading Express (RM)", hint: "Not below Grading." },
  { key: "grading_lands_at_stars", label: "Grading lands at (Mythic ★)", hint: "If grading lands lower, the penjoki plays on to here at no charge." },
  { key: "max_mythic_stars", label: "Mythic star cap", hint: "The highest target a customer can pick." },
  { key: "progress_message_interval_minutes", label: "Progress WhatsApp interval (min)", hint: "At most one progress message per this many minutes." },
];

/**
 * ADR-126 decisions 1, 3-7, 23: joki's panel settings (super admin). Prices
 * are whole ringgit; the backend refuses sen. Switching Joki on the first
 * time sets up the joki game and calculator package. Switching it off
 * stops new sales only — paid orders carry on. A brand also needs its own
 * "Sell Joki" (Affiliates); the home banner is a Hero Slide linking to /joki.
 */
export function JokiSettingsTab() {
  const [data, setData] = useState<JokiSettingsResponse | null>(null);
  const [form, setForm] = useState<JokiSettings | null>(null);
  const [roles, setRoles] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  function load() {
    return getJokiSettings()
      .then((res) => {
        setData(res);
        setForm(res.settings);
        setRoles(res.settings.roles.join(", "));
        setError(null);
      })
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load joki settings."));
  }

  useEffect(() => {
    void load();
  }, []);

  async function save(values: Partial<JokiSettings>, done: string) {
    setSaving(true);
    setError(null);
    setNotice(null);
    try {
      await updateJokiSettings(values);
      await load();
      setNotice(done);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save.");
    } finally {
      setSaving(false);
    }
  }

  if (!data || !form) return error ? <p className="text-sm text-error-600 dark:text-error-400">{error}</p> : <p className="text-sm text-ink-muted">Loading…</p>;

  const { settings } = data;

  return (
    <div className="space-y-6">
      {error && <p className="rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>}
      {notice && <p className="rounded-lg bg-success-50 px-4 py-3 text-sm text-success-600 dark:bg-success-500/15 dark:text-success-400">{notice}</p>}

      <section className={CARD}>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 className="text-section-title font-semibold text-ink">Joki is {settings.is_enabled ? "on" : "off"}</h2>
            <p className="mt-1 text-theme-xs text-ink-muted">
              {settings.is_enabled
                ? "Owned brands with \"Sell Joki\" ticked sell it. Turning it off stops new sales; paid orders carry on."
                : data.is_set_up
                  ? "Not sold anywhere. Paid orders still carry on."
                  : "Not set up yet. Turning it on creates the joki game and its calculator."}
            </p>
          </div>
          <Button
            size="small"
            severity={settings.is_enabled ? "danger" : undefined}
            variant={settings.is_enabled ? "outlined" : undefined}
            disabled={saving}
            onClick={() => save({ is_enabled: !settings.is_enabled }, settings.is_enabled ? "Joki switched off." : "Joki switched on.")}
          >
            {settings.is_enabled ? "Switch off" : "Switch on"}
          </Button>
        </div>
      </section>

      <section className={CARD}>
        <h2 className="mb-4 text-section-title font-semibold text-ink">Prices and rules</h2>
        <div className="grid gap-4 sm:grid-cols-2">
          {NUMBER_FIELDS.map(({ key, label, hint, step }) => (
            <div key={key}>
              <label htmlFor={`joki-${key}`} className={LABEL}>{label}</label>
              <input
                id={`joki-${key}`}
                type="number"
                min={0}
                step={step ?? "1"}
                className={`${JOKI_INPUT} w-full`}
                value={form[key]}
                onChange={(e) => setForm({ ...form, [key]: e.target.value === "" ? 0 : Number(e.target.value) })}
              />
              <p className="mt-1 text-theme-xs text-ink-subtle">{hint}</p>
            </div>
          ))}
          <div className="sm:col-span-2">
            <label htmlFor="joki-roles" className={LABEL}>Roles (comma separated)</label>
            <input id="joki-roles" className={`${JOKI_INPUT} w-full`} value={roles} onChange={(e) => setRoles(e.target.value)} />
          </div>
          <div className="sm:col-span-2">
            <label htmlFor="joki-express" className={LABEL}>Express description</label>
            <input id="joki-express" className={`${JOKI_INPUT} w-full`} maxLength={500} value={form.express_description ?? ""} onChange={(e) => setForm({ ...form, express_description: e.target.value })} />
          </div>
          <div className="sm:col-span-2">
            <span className={LABEL}>Terms</span>
            <RichTextEditor value={form.terms_html ?? ""} onChange={(html) => setForm({ ...form, terms_html: html })} />
          </div>
        </div>
        <div className="mt-4 flex justify-end">
          <Button
            size="small"
            disabled={saving}
            onClick={() =>
              save(
                {
                  ...Object.fromEntries(NUMBER_FIELDS.map(({ key }) => [key, form[key]])),
                  roles: roles.split(",").map((r) => r.trim()).filter(Boolean),
                  express_description: form.express_description || null,
                  terms_html: form.terms_html || null,
                },
                "Settings saved.",
              )
            }
          >
            Save
          </Button>
        </div>
      </section>

      <JokiRankModelEditor tiers={data.tiers} onSaved={load} />
      <JokiRoutesEditor tiers={data.tiers} routes={data.routes} onSaved={load} />
    </div>
  );
}
