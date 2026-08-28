"use client";

/**
 * ADR-029 decision 2 (SEO-3) / addendum decision 8: template placeholder
 * syntax reuses ADR-028 addendum decision 13's exact {store_name}/
 * {game_name} convention — substituted at storefront render time only,
 * an unrecognized token left verbatim, no error.
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { getSeoSettings, updateSeoSettings, type SeoSettings } from "@/lib/seo";

export default function SeoTemplatesPage() {
  const router = useRouter();
  const session = useClientSession();
  const [settings, setSettings] = useState<SeoSettings | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    getSeoSettings(s.token)
      .then(setSettings)
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load templates."));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function handleSave() {
    if (!session || !settings) return;
    setSaving(true);
    setError(null);
    setSaved(false);
    try {
      const updated = await updateSeoSettings(session.token, {
        meta_title_template: settings.meta_title_template || null,
        meta_description_template: settings.meta_description_template || null,
      });
      setSettings(updated);
      setSaved(true);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save templates.");
    } finally {
      setSaving(false);
    }
  }

  if (!session || !settings) {
    return <p className="text-sm text-gray-500 dark:text-gray-400">{error ?? "Loading…"}</p>;
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Meta Templates</h1>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
          Pattern used for every game page&apos;s meta title/description. Supported tokens: <code>{"{game_name}"}</code>, <code>{"{store_name}"}</code> — any other brace sequence is left as-is.
        </p>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}
      {saved && !error && (
        <p className="mb-4 rounded-lg bg-success-50 px-4 py-3 text-sm text-success-600 dark:bg-success-500/15 dark:text-success-400">Saved.</p>
      )}

      <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="space-y-4">
          <div>
            <Label htmlFor="title_template">Meta title template</Label>
            <Input
              id="title_template"
              placeholder="{game_name} Top Up — {store_name}"
              value={settings.meta_title_template ?? ""}
              onChange={(e) => setSettings({ ...settings, meta_title_template: e.target.value })}
            />
          </div>
          <div>
            <Label htmlFor="description_template">Meta description template</Label>
            <textarea
              id="description_template"
              rows={3}
              placeholder="Top up {game_name} instantly at {store_name}. Fast, secure, guest checkout."
              value={settings.meta_description_template ?? ""}
              onChange={(e) => setSettings({ ...settings, meta_description_template: e.target.value })}
              className="w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
            />
          </div>
        </div>
      </div>

      <div className="mt-6 flex justify-end gap-3 border-t border-gray-100 pt-5 dark:border-gray-800">
        <Button type="button" onClick={handleSave} disabled={saving}>
          {saving ? "Saving…" : "Save Templates"}
        </Button>
      </div>
    </div>
  );
}
