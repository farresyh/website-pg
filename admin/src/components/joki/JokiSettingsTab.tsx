"use client";

import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import RichTextEditor from "@/components/ui/RichTextEditor";
import { StatusText } from "@/components/orders/StatusText";
import { ApiError } from "@/lib/api-client";
import { notify } from "@/components/ui/toast";
import { getJokiSettings, updateJokiSettings, type JokiSettings, type JokiSettingsResponse } from "@/lib/joki";
import { JokiRankModelEditor } from "@/components/joki/JokiRankModelEditor";
import { JokiRoutesEditor } from "@/components/joki/JokiRoutesEditor";
import { JOKI_CARD, JOKI_CARD_HINT, JOKI_CARD_TITLE, JOKI_ERROR } from "@/components/joki/jokiStyles";

type NumberKey = "payout_share_pct" | "min_stars" | "grading_price_rm" | "grading_express_price_rm" | "grading_lands_at_stars" | "max_mythic_stars" | "progress_message_interval_minutes";

const NUMBER_FIELDS: { key: NumberKey; label: string; hint: string; step?: string }[] = [
  { key: "grading_price_rm", label: "Mythic Grading (RM)", hint: "Flat price. Guarantees the star below." },
  { key: "grading_express_price_rm", label: "Mythic Grading Express (RM)", hint: "Not below Grading." },
  { key: "grading_lands_at_stars", label: "Grading lands at (Mythic ★)", hint: "If grading lands lower, the penjoki plays on at no charge." },
  { key: "min_stars", label: "Minimum stars", hint: "A smaller climb is refused unless it includes Grading." },
  { key: "max_mythic_stars", label: "Mythic star cap", hint: "The highest target a customer can pick." },
  { key: "payout_share_pct", label: "Payout share (%)", hint: "Of the customer price, to the penjoki. Fixed on each order at checkout.", step: "0.01" },
  { key: "progress_message_interval_minutes", label: "Progress WhatsApp interval (minutes)", hint: "At most one progress message per this many minutes." },
];

/**
 * ADR-126 decisions 1, 3-7, 23: joki's panel settings (super admin). Prices
 * are whole ringgit; the backend refuses sen. Switching Joki on the first
 * time sets up the joki game and calculator package. Switching it off
 * stops new sales only; paid orders carry on. A brand also needs its own
 * "Sell Joki" (Affiliates); the home banner is a Hero Slide linking to /joki.
 */
export function JokiSettingsTab() {
  const [data, setData] = useState<JokiSettingsResponse | null>(null);
  const [form, setForm] = useState<JokiSettings | null>(null);
  const [roles, setRoles] = useState("");
  const [error, setError] = useState<string | null>(null);
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
    try {
      await updateJokiSettings(values);
      await load();
      notify.success(done);
    } catch (err) {
      notify.stickyError("Could not save", err instanceof ApiError ? err.message : "Action failed.");
    } finally {
      setSaving(false);
    }
  }

  if (!data || !form) {
    return error ? <p className={JOKI_ERROR}>{error}</p> : <p className="p-6 text-center text-sm text-ink-muted">Loading…</p>;
  }

  const { settings } = data;

  return (
    <div className="space-y-6">
      {error && <p className={JOKI_ERROR}>{error}</p>}

      <section className={JOKI_CARD}>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="flex items-center gap-3">
              <h2 className={JOKI_CARD_TITLE}>Joki sales</h2>
              <StatusText severity={settings.is_enabled ? "success" : "secondary"}>{settings.is_enabled ? "on" : "off"}</StatusText>
            </div>
            <p className={JOKI_CARD_HINT}>
              {settings.is_enabled
                ? "Owned brands with \"Sell Joki\" ticked (Affiliates) sell it. Turning it off stops new sales; paid orders carry on."
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

      <section className={JOKI_CARD}>
        <h2 className={JOKI_CARD_TITLE}>Prices and rules</h2>
        <p className={JOKI_CARD_HINT}>Whole ringgit only. Each order keeps the prices and share it was sold with.</p>
        <div className="mt-5 grid gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
          {NUMBER_FIELDS.map(({ key, label, hint, step }) => (
            <div key={key}>
              <Label htmlFor={`joki-${key}`}>{label}</Label>
              <Input
                id={`joki-${key}`}
                type="number"
                min={0}
                step={step ?? "1"}
                value={String(form[key])}
                onChange={(e) => setForm({ ...form, [key]: e.target.value === "" ? 0 : Number(e.target.value) })}
              />
              <p className="mt-1.5 text-theme-xs text-ink-muted">{hint}</p>
            </div>
          ))}
        </div>

        <div className="mt-6 grid gap-5 border-t border-gray-100 pt-6 dark:border-gray-800">
          <div>
            <Label htmlFor="joki-roles">Roles</Label>
            <Input id="joki-roles" value={roles} onChange={(e) => setRoles(e.target.value)} />
            <p className="mt-1.5 text-theme-xs text-ink-muted">Comma separated. The customer picks one.</p>
          </div>
          <div>
            <Label htmlFor="joki-express">Express description</Label>
            <Input id="joki-express" maxLength={500} value={form.express_description ?? ""} onChange={(e) => setForm({ ...form, express_description: e.target.value })} />
          </div>
          <div>
            <Label>Terms</Label>
            <RichTextEditor value={form.terms_html ?? ""} onChange={(html) => setForm({ ...form, terms_html: html })} />
          </div>
        </div>

        <div className="mt-6 flex justify-end">
          <Button
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
            {saving ? "Saving…" : "Save settings"}
          </Button>
        </div>
      </section>

      <JokiRankModelEditor tiers={data.tiers} onSaved={load} />
      <JokiRoutesEditor tiers={data.tiers} routes={data.routes} onSaved={load} />
    </div>
  );
}
