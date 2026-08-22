"use client";

/** ADR-029 decision 2/10: SEO-2 defaults + SEO-7 pixel IDs + JSON-LD toggles. */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import Button from "@/components/ui/button/Button";
import { getClientSession } from "@/lib/session";
import type { SessionPayload } from "@/lib/auth";
import { ApiError } from "@/lib/api-client";
import { getSeoSettings, updateSeoSettings, type SeoSettings } from "@/lib/seo";

/** Same inline pattern as PlatformSettingsSection.tsx — no shared Switch component exists yet. */
function Switch({ checked, onChange }: { checked: boolean; onChange: (v: boolean) => void }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      onClick={() => onChange(!checked)}
      className={`relative h-5.5 w-10 flex-shrink-0 rounded-full transition-colors ${checked ? "bg-brand-500" : "bg-gray-300 dark:bg-gray-700"}`}
    >
      <span className={`absolute top-0.5 h-4.5 w-4.5 rounded-full bg-white transition-transform ${checked ? "translate-x-[19px]" : "translate-x-0.5"}`} />
    </button>
  );
}

export default function SeoGlobalSettingsPage() {
  const router = useRouter();
  const [session, setSession] = useState<SessionPayload | null>(null);
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
    setSession(s);
    getSeoSettings(s.token)
      .then(setSettings)
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load SEO settings."));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function handleSave() {
    if (!session || !settings) return;
    setSaving(true);
    setError(null);
    setSaved(false);
    try {
      const updated = await updateSeoSettings(session.token, {
        default_meta_title: settings.default_meta_title || null,
        default_meta_description: settings.default_meta_description || null,
        default_og_image: settings.default_og_image || null,
        ga_measurement_id: settings.ga_measurement_id || null,
        fb_pixel_id: settings.fb_pixel_id || null,
        tiktok_pixel_id: settings.tiktok_pixel_id || null,
        schema_organization_enabled: settings.schema_organization_enabled,
        schema_product_enabled: settings.schema_product_enabled,
        schema_breadcrumb_enabled: settings.schema_breadcrumb_enabled,
      });
      setSettings(updated);
      setSaved(true);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save SEO settings.");
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
        <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Global SEO Settings</h1>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
          Storefront-wide defaults, tracking pixel IDs, and JSON-LD structured data toggles.
        </p>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}
      {saved && !error && (
        <p className="mb-4 rounded-lg bg-success-50 px-4 py-3 text-sm text-success-600 dark:bg-success-500/15 dark:text-success-400">Saved.</p>
      )}

      <div className="space-y-6">
        <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
          <h2 className="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">Default Meta (SEO-2)</h2>
          <p className="mb-4 text-theme-xs text-gray-500 dark:text-gray-400">
            Fallback used wherever a specific game's own SEO title/description is empty.
          </p>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="sm:col-span-2">
              <Label htmlFor="default_meta_title">Default meta title ({(settings.default_meta_title ?? "").length}/70)</Label>
              <input
                id="default_meta_title"
                maxLength={70}
                value={settings.default_meta_title ?? ""}
                onChange={(e) => setSettings({ ...settings, default_meta_title: e.target.value })}
                className="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800"
              />
            </div>
            <div className="sm:col-span-2">
              <Label htmlFor="default_meta_description">Default meta description ({(settings.default_meta_description ?? "").length}/160)</Label>
              <textarea
                id="default_meta_description"
                maxLength={160}
                rows={3}
                value={settings.default_meta_description ?? ""}
                onChange={(e) => setSettings({ ...settings, default_meta_description: e.target.value })}
                className="w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
              />
            </div>
            <div className="sm:col-span-2">
              <Label htmlFor="default_og_image">Default OG image URL</Label>
              <Input
                id="default_og_image"
                value={settings.default_og_image ?? ""}
                onChange={(e) => setSettings({ ...settings, default_og_image: e.target.value })}
              />
            </div>
          </div>
        </div>

        <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
          <h2 className="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">Tracking Pixels (SEO-7)</h2>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
              <Label htmlFor="ga">Google Analytics Measurement ID</Label>
              <Input id="ga" placeholder="G-XXXXXXX" value={settings.ga_measurement_id ?? ""} onChange={(e) => setSettings({ ...settings, ga_measurement_id: e.target.value })} />
            </div>
            <div>
              <Label htmlFor="fb">Facebook Pixel ID</Label>
              <Input id="fb" value={settings.fb_pixel_id ?? ""} onChange={(e) => setSettings({ ...settings, fb_pixel_id: e.target.value })} />
            </div>
            <div>
              <Label htmlFor="tiktok">TikTok Pixel ID</Label>
              <Input id="tiktok" value={settings.tiktok_pixel_id ?? ""} onChange={(e) => setSettings({ ...settings, tiktok_pixel_id: e.target.value })} />
            </div>
          </div>
        </div>

        <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
          <h2 className="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">Structured Data (JSON-LD)</h2>
          <div className="space-y-3">
            <div className="flex items-center justify-between">
              <span className="text-sm text-gray-700 dark:text-gray-300">Organization schema</span>
              <Switch
                checked={settings.schema_organization_enabled}
                onChange={(checked) => setSettings({ ...settings, schema_organization_enabled: checked })}
              />
            </div>
            <div className="flex items-center justify-between">
              <span className="text-sm text-gray-700 dark:text-gray-300">Product schema (per game)</span>
              <Switch
                checked={settings.schema_product_enabled}
                onChange={(checked) => setSettings({ ...settings, schema_product_enabled: checked })}
              />
            </div>
            <div className="flex items-center justify-between">
              <span className="text-sm text-gray-700 dark:text-gray-300">Breadcrumb schema</span>
              <Switch
                checked={settings.schema_breadcrumb_enabled}
                onChange={(checked) => setSettings({ ...settings, schema_breadcrumb_enabled: checked })}
              />
            </div>
          </div>
        </div>
      </div>

      <div className="mt-6 flex justify-end gap-3 border-t border-gray-100 pt-5 dark:border-gray-800">
        <Button type="button" onClick={handleSave} disabled={saving}>
          {saving ? "Saving…" : "Save Settings"}
        </Button>
      </div>
    </div>
  );
}
