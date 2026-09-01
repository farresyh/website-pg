"use client";

import { useEffect, useState } from "react";
import { Label } from "@/components/ui/label";
import { Button } from "@/components/ui/button";
import RichTextEditor from "@/components/ui/RichTextEditor";
import { ApiError } from "@/lib/api-client";
import { updateFooterSettings, type FooterSettings } from "@/lib/settings";
import { listGames, type Game } from "@/lib/games";

const MAX_FOOTER_GAMES = 4;

/** Preview only — the stored value keeps the raw token (ADR-028 addendum decision 13). */
function resolveStoreName(html: string, storeName: string): string {
  return html.split("{store_name}").join(storeName || "{store_name}");
}

function LegalField({
  label,
  route,
  value,
  onChange,
  storeName,
}: {
  label: string;
  route: string;
  value: string;
  onChange: (html: string) => void;
  storeName: string;
}) {
  return (
    <div>
      <Label>
        {label} <span className="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-theme-xs text-gray-500 dark:bg-white/[0.05] dark:text-gray-400">{route}</span>
      </Label>
      <RichTextEditor value={value} onChange={onChange} />
      <div className="mt-1.5 flex items-start gap-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
        <span>
          Use <code className="rounded bg-brand-50 px-1 py-0.5 text-brand-600 dark:bg-brand-500/[0.15] dark:text-brand-400">{"{store_name}"}</code> to
          auto-insert the store name — resolved live on the storefront, not saved as fixed text.
        </span>
      </div>
      {value && (
        <div className="mt-2 rounded-lg border border-dashed border-gray-200 bg-gray-50 px-3 py-2 text-theme-xs text-gray-500 dark:border-gray-700 dark:bg-white/[0.02] dark:text-gray-400">
          <span className="font-medium">Preview:</span>{" "}
          <span dangerouslySetInnerHTML={{ __html: resolveStoreName(value, storeName) }} />
        </div>
      )}
    </div>
  );
}

export default function FooterSettingsSection({
  token,
  footer,
  storeName,
  onSaved,
}: {
  token: string;
  footer: FooterSettings;
  storeName: string;
  onSaved: () => void;
}) {
  const [footerText, setFooterText] = useState(footer.footer_text ?? "");
  const [terms, setTerms] = useState(footer.terms_content ?? "");
  const [privacy, setPrivacy] = useState(footer.privacy_content ?? "");
  const [aboutUs, setAboutUs] = useState(footer.about_us_content ?? "");
  const [gameIds, setGameIds] = useState<number[]>(footer.footer_game_ids ?? []);
  const [activeGames, setActiveGames] = useState<Game[]>([]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    listGames(token, { status: "active" })
      .then(setActiveGames)
      .catch(() => undefined);
  }, [token]);

  function toggleGame(id: number) {
    setGameIds((prev) => {
      if (prev.includes(id)) return prev.filter((g) => g !== id);
      if (prev.length >= MAX_FOOTER_GAMES) return prev;
      return [...prev, id];
    });
  }

  async function handleSave() {
    setSaving(true);
    setError(null);
    try {
      await updateFooterSettings(token, {
        footer_text: footerText || null,
        terms_content: terms || null,
        privacy_content: privacy || null,
        about_us_content: aboutUs || null,
        footer_game_ids: gameIds,
      });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save footer settings.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <div className="mb-6">
        <Label htmlFor="footer_text">Footer text</Label>
        <textarea
          id="footer_text"
          value={footerText}
          onChange={(e) => setFooterText(e.target.value)}
          rows={2}
          className="w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
          placeholder="© 2026 PekanGame. All rights reserved."
        />
      </div>

      <h2 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Legal pages</h2>
      <div className="mb-6 space-y-5">
        <LegalField label="Terms & Conditions content" route="/terms" value={terms} onChange={setTerms} storeName={storeName} />
        <LegalField label="Privacy Policy content" route="/privacy" value={privacy} onChange={setPrivacy} storeName={storeName} />
        <LegalField label="About Us content" route="/about-us" value={aboutUs} onChange={setAboutUs} storeName={storeName} />
      </div>

      <h2 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Footer games</h2>
      <p className="mb-3 text-theme-xs text-gray-500 dark:text-gray-400">
        Pick up to {MAX_FOOTER_GAMES} active games to feature in the storefront footer&apos;s &quot;Top Up Games&quot; section.
      </p>
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
        {activeGames.map((game) => {
          const checked = gameIds.includes(game.id);
          const disabled = !checked && gameIds.length >= MAX_FOOTER_GAMES;
          return (
            <label
              key={game.id}
              className={`flex items-center gap-2 rounded-lg border px-3 py-2 text-sm ${
                checked
                  ? "border-brand-300 bg-brand-50 text-brand-700 dark:border-brand-800 dark:bg-brand-500/[0.15] dark:text-brand-400"
                  : "border-gray-300 text-gray-700 dark:border-gray-700 dark:text-gray-300"
              } ${disabled ? "cursor-not-allowed opacity-50" : "cursor-pointer"}`}
            >
              <input type="checkbox" checked={checked} disabled={disabled} onChange={() => toggleGame(game.id)} className="accent-brand-500" />
              {game.name}
            </label>
          );
        })}
      </div>
      <p className="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
        <span className="font-medium text-gray-700 dark:text-gray-300">{gameIds.length} / {MAX_FOOTER_GAMES}</span> selected
        {gameIds.length >= MAX_FOOTER_GAMES ? " — uncheck one to pick another" : ""}
      </p>

      <div className="mt-6 flex justify-end gap-3 border-t border-gray-100 pt-5 dark:border-gray-800">
        <Button type="button" onClick={handleSave} disabled={saving}>
          {saving ? "Saving…" : "Save Footer Settings"}
        </Button>
      </div>
    </div>
  );
}
