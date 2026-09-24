"use client";

import { useEffect, useState } from "react";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import {
  getStorefrontBranding,
  updateStorefrontBranding,
  type StorefrontBrandingResponse,
} from "@/lib/portal";
import { THEME_PRESETS, getThemePreset } from "@/lib/theme-presets";
import { Panel, ErrorNote } from "@/components/ui";
import { SaveButton, InactiveNotice, TabLoading } from "./shared";

export default function ThemeTab() {
  const [data, setData] = useState<StorefrontBrandingResponse | null>(null);
  const [selectedPreset, setSelectedPreset] = useState<string>("default");
  const [selectedMode, setSelectedMode] = useState<"light" | "dark">("light");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    const session = getClientSession();
    if (!session) return;
    let cancelled = false;
    getStorefrontBranding(session.token)
      .then((result) => {
        if (cancelled) return;
        setData(result);
        setSelectedPreset(result.branding.theme_preset || "default");
        setSelectedMode(result.branding.theme_mode || "light");
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : "Could not load theme settings.");
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const writable = data?.writable ?? false;
  const currentActivePreset = data?.branding.theme_preset || "default";
  const currentActiveMode = data?.branding.theme_mode || "light";
  const activePresetConfig = getThemePreset(selectedPreset);
  // ADR-090: only `default` ships a dark palette today — selecting Dark
  // on a preset without one isn't offered, rather than silently no-op-ing.
  const darkAvailable = Boolean(activePresetConfig.tokensDark);
  const previewTokens = selectedMode === "dark" && activePresetConfig.tokensDark ? activePresetConfig.tokensDark : activePresetConfig.tokens;
  const previewSurface = previewTokens["--color-surface"] ?? "#f7f4ec";
  const previewOnSurface = previewTokens["--color-on-surface"] ?? "#19192f";
  const previewInk = previewTokens["--color-ink"] ?? "#19192f";
  const previewCardBg = previewTokens["--color-surface-container-lowest"] ?? "#ffffff";

  async function handleSave(e: React.FormEvent) {
    e.preventDefault();
    if (!data) return;
    const session = getClientSession();
    if (!session) return;

    setError(null);
    setSaving(true);
    try {
      const result = await updateStorefrontBranding(session.token, {
        store_name: data.branding.store_name,
        theme_preset: selectedPreset,
        theme_mode: darkAvailable ? selectedMode : "light",
      });
      setData(result);
      setSaved(true);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not update theme.");
    } finally {
      setSaving(false);
    }
  }

  if (!data) return <TabLoading />;

  return (
    <div className="space-y-6">
      {!writable && <InactiveNotice />}
      {error && <ErrorNote message={error} />}

      <form onSubmit={handleSave} className="grid grid-cols-1 gap-8 lg:grid-cols-12">
        {/* Left Column: Preset Selection */}
        <div className="lg:col-span-7 space-y-4">
          <Panel title="Site Mode">
            <div className="p-5">
              <p className="text-xs text-gray-500 dark:text-gray-400 mb-4">
                Light or dark background for your whole storefront — a fixed choice for every visitor, not a toggle they switch themselves.
              </p>
              <div className="flex gap-3">
                {(["light", "dark"] as const).map((mode) => {
                  const disabled = mode === "dark" && !darkAvailable;
                  return (
                    <button
                      key={mode}
                      type="button"
                      disabled={!writable || disabled}
                      onClick={() => {
                        setSelectedMode(mode);
                        setSaved(false);
                      }}
                      title={disabled ? "This preset doesn't have a dark palette yet" : undefined}
                      className={`rounded-lg border-2 px-4 py-2 text-sm font-semibold capitalize transition-all disabled:cursor-not-allowed disabled:opacity-40 ${
                        selectedMode === mode
                          ? "border-brand-500 bg-brand-50/50 text-brand-700 dark:border-brand-400 dark:bg-brand-950/20 dark:text-brand-300"
                          : "border-gray-200 text-gray-600 hover:border-gray-300 dark:border-gray-800 dark:text-gray-400 dark:hover:border-gray-700"
                      }`}
                    >
                      {mode}
                    </button>
                  );
                })}
              </div>
              {!darkAvailable && (
                <p className="mt-3 text-xs text-gray-400 dark:text-gray-500">Dark mode is only available on the Digital Architect (default) preset for now — more presets are getting a dark palette over time.</p>
              )}
            </div>
          </Panel>

          <Panel title="Theme Presets">
            <div className="p-5">
              <p className="text-xs text-gray-500 dark:text-gray-400 mb-4">
                Choose a curated Neubrutalist color palette for your customer storefront. Click a preset to preview.
              </p>
              <div className="space-y-3">
              {Object.values(THEME_PRESETS).map((preset) => {
                const isSelected = selectedPreset === preset.id;
                const isSavedActive = currentActivePreset === preset.id;

                return (
                  <div
                    key={preset.id}
                    onClick={() => {
                      if (!writable) return;
                      setSelectedPreset(preset.id);
                      if (!preset.tokensDark) setSelectedMode("light");
                      setSaved(false);
                    }}
                    className={`cursor-pointer rounded-xl border-2 p-4 transition-all ${
                      isSelected
                        ? "border-brand-500 bg-brand-50/50 shadow-sm dark:border-brand-400 dark:bg-brand-950/20"
                        : "border-gray-200 hover:border-gray-300 dark:border-gray-800 dark:hover:border-gray-700 bg-white dark:bg-gray-900"
                    }`}
                  >
                    <div className="flex items-start justify-between gap-3">
                      <div>
                        <div className="flex items-center gap-2">
                          <h4 className="font-semibold text-gray-900 dark:text-white">
                            {preset.name}
                          </h4>
                          {isSavedActive && (
                            <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[10.5px] font-bold text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400">
                              Active in Storefront
                            </span>
                          )}
                          {isSelected && !isSavedActive && (
                            <span className="rounded-full bg-brand-100 px-2 py-0.5 text-[10.5px] font-bold text-brand-700 dark:bg-brand-950/50 dark:text-brand-300">
                              Selected for Preview
                            </span>
                          )}
                        </div>
                        <p className="mt-0.5 text-xs font-medium text-gray-500 dark:text-gray-400">
                          {preset.tagline}
                        </p>
                        <p className="mt-1.5 text-xs text-gray-600 dark:text-gray-400">
                          {preset.description}
                        </p>
                      </div>

                      {/* Swatch chips */}
                      <div className="flex items-center gap-1.5 shrink-0">
                        <span
                          title={`Primary: ${preset.primaryHex}`}
                          className="h-6 w-6 rounded-full border border-black/20 shadow-xs"
                          style={{ backgroundColor: preset.primaryHex }}
                        />
                        <span
                          title={`Secondary: ${preset.secondaryHex}`}
                          className="h-6 w-6 rounded-full border border-black/20 shadow-xs"
                          style={{ backgroundColor: preset.secondaryHex }}
                        />
                        <span
                          title={`Accent: ${preset.accentHex}`}
                          className="h-6 w-6 rounded-full border border-black/20 shadow-xs"
                          style={{ backgroundColor: preset.accentHex }}
                        />
                      </div>
                    </div>
                  </div>
                );
              })}
              </div>

              <div className="mt-6 border-t border-gray-100 dark:border-gray-800 pt-4">
                <SaveButton
                  saving={saving}
                  saved={saved}
                  disabled={!writable || (selectedPreset === currentActivePreset && selectedMode === currentActiveMode)}
                  label="Apply &amp; Save Theme"
                />
              </div>
            </div>
          </Panel>
        </div>

        {/* Right Column: Live Interactive Mockup Preview */}
        <div className="lg:col-span-5">
          <div className="sticky top-6">
            <Panel title="Live Storefront Preview">
              <div className="p-5">
                <p className="text-xs text-gray-500 dark:text-gray-400 mb-3">
                  See how your theme colors look on actual storefront components.
                </p>
                {/* Mockup Frame — inline-styled from the preset's live tokens (light or dark), not the portal's own static `bg-surface`/`border-ink`, so the preview actually shows the background swap the preset now makes. */}
                <div
                  className="overflow-hidden rounded-xl border-2 p-4 shadow-[4px_4px_0_#19192f]"
                  style={{ backgroundColor: previewSurface, borderColor: previewInk }}
                >
                {/* Mockup Header */}
                <div className="flex items-center justify-between border-b-2 pb-3 mb-4" style={{ borderColor: previewInk }}>
                  <div className="flex items-center gap-2">
                    <div
                      className="h-7 w-7 rounded-md border-2 flex items-center justify-center font-bold text-xs shadow-[1.5px_1.5px_0_#19192f]"
                      style={{
                        backgroundColor: activePresetConfig.primaryHex,
                        color: activePresetConfig.tokens["--color-on-primary"],
                        borderColor: previewInk,
                      }}
                    >
                      PG
                    </div>
                    <span className="font-bold text-sm tracking-tight" style={{ color: previewOnSurface }}>
                      {data.branding.store_name || "Your Store"}
                    </span>
                  </div>
                  <span
                    className="rounded-md border-2 px-2.5 py-1 text-[11px] font-bold shadow-[1.5px_1.5px_0_#19192f]"
                    style={{
                      backgroundColor: activePresetConfig.primaryHex,
                      color: activePresetConfig.tokens["--color-on-primary"],
                      borderColor: previewInk,
                    }}
                  >
                    Track Order
                  </span>
                </div>

                {/* Mockup Product Card */}
                <div className="rounded-lg border-2 p-3.5 shadow-[3px_3px_0_#19192f]" style={{ backgroundColor: previewCardBg, borderColor: previewInk }}>
                  <div className="flex items-start justify-between gap-2">
                    <div>
                      <span className="inline-block rounded border border-ink/20 bg-gray-100 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider text-gray-700">
                        Popular Pick
                      </span>
                      <h5 className="mt-1 font-bold text-sm" style={{ color: previewOnSurface }}>
                        Mobile Legends: Bang Bang
                      </h5>
                      <p className="text-xs text-gray-600">Moonton • Instant Delivery</p>
                    </div>
                    <span
                      className="rounded border-2 border-ink px-1.5 py-0.5 text-[10px] font-bold shadow-[1px_1px_0_#19192f]"
                      style={{
                        backgroundColor: activePresetConfig.tokens["--color-primary-fixed"],
                        color: activePresetConfig.tokens["--color-on-primary-fixed"],
                      }}
                    >
                      Active
                    </span>
                  </div>

                  <div className="mt-3.5 flex items-center justify-between border-t-2 border-ink/10 pt-2.5">
                    <div>
                      <span className="text-[10px] uppercase font-bold text-gray-500">From</span>
                      <p
                        className="font-bold text-base"
                        style={{ color: activePresetConfig.tokens["--color-primary-on-surface"] }}
                      >
                        RM 5.00
                      </p>
                    </div>
                    <button
                      type="button"
                      className="rounded-md border-2 border-ink px-3 py-1.5 text-xs font-bold shadow-[2px_2px_0_#19192f] transition-transform active:translate-x-0.5 active:translate-y-0.5"
                      style={{
                        backgroundColor: activePresetConfig.primaryHex,
                        color: activePresetConfig.tokens["--color-on-primary"],
                      }}
                    >
                      Top Up Now
                    </button>
                  </div>
                </div>

                {/* Mockup Payment Strip */}
                <div className="mt-3 flex items-center justify-between rounded-md border-2 border-ink/15 bg-white/70 p-2 text-[10.5px]">
                  <span className="font-semibold text-gray-700">Official Partners:</span>
                  <div className="flex items-center gap-1.5">
                    <span className="rounded bg-[#003B70] px-1.5 py-0.5 font-bold text-white text-[9.5px]">
                      FPX
                    </span>
                    <span className="rounded bg-[#ED0080] px-1.5 py-0.5 font-bold text-white text-[9.5px]">
                      DuitNow
                    </span>
                    <span
                      className="rounded px-1.5 py-0.5 font-bold text-[9.5px] border border-ink/20"
                      style={{
                        backgroundColor: activePresetConfig.tokens["--color-primary-fixed"],
                        color: activePresetConfig.tokens["--color-on-primary-fixed"],
                      }}
                    >
                      CHIP Secured
                    </span>
                  </div>
                </div>
              </div>

              <div className="mt-3 flex items-center justify-center gap-1.5 text-center text-xs text-gray-500">
                <span>Theme:</span>
                <strong className="text-gray-800 dark:text-gray-200">{activePresetConfig.name}</strong>
              </div>
            </div>
          </Panel>
          </div>
        </div>
      </form>
    </div>
  );
}
