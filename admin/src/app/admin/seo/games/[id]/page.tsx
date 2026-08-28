"use client";

/** ADR-029 addendum 2 decision 18: char counters, "Use Game Image", no_index. */

import { useEffect, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import Link from "next/link";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import { Button } from "@/components/ui/button";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { getGameSeo, updateGameSeo, type GameSeoDetail } from "@/lib/seo";

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

export default function GameSeoEditPage() {
  const router = useRouter();
  const params = useParams<{ id: string }>();
  const gameId = Number(params.id);

  const session = useClientSession();
  const [game, setGame] = useState<GameSeoDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    getGameSeo(s.token, gameId)
      .then(setGame)
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load game."));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [gameId]);

  async function handleSave() {
    if (!session || !game) return;
    setSaving(true);
    setError(null);
    setSaved(false);
    try {
      const updated = await updateGameSeo(session.token, gameId, {
        seo_title: game.seo_title || null,
        seo_title_local: game.seo_title_local || null,
        seo_description: game.seo_description || null,
        seo_description_local: game.seo_description_local || null,
        seo_keywords: game.seo_keywords || null,
        seo_og_image: game.seo_og_image || null,
        schema_brand: game.schema_brand || null,
        schema_category: game.schema_category || null,
        no_index: game.no_index,
      });
      setGame(updated);
      setSaved(true);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save game SEO.");
    } finally {
      setSaving(false);
    }
  }

  if (!session || !game) {
    return <p className="text-sm text-gray-500 dark:text-gray-400">{error ?? "Loading…"}</p>;
  }

  return (
    <div>
      <div className="mb-6">
        <Link href="/admin/seo/games" className="text-sm text-brand-500 hover:underline">← Back to Game SEO</Link>
        <h1 className="mt-2 text-xl font-semibold text-gray-800 dark:text-white/90">{game.name}</h1>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}
      {saved && !error && (
        <p className="mb-4 rounded-lg bg-success-50 px-4 py-3 text-sm text-success-600 dark:bg-success-500/15 dark:text-success-400">Saved.</p>
      )}

      <div className="space-y-6">
        <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
          <h2 className="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">Meta</h2>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <Label htmlFor="seo_title">SEO title ({(game.seo_title ?? "").length}/70)</Label>
              <input
                id="seo_title"
                maxLength={70}
                value={game.seo_title ?? ""}
                onChange={(e) => setGame({ ...game, seo_title: e.target.value })}
                className="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800"
              />
            </div>
            <div>
              <Label htmlFor="seo_title_local">SEO title (local) ({(game.seo_title_local ?? "").length}/70)</Label>
              <input
                id="seo_title_local"
                maxLength={70}
                value={game.seo_title_local ?? ""}
                onChange={(e) => setGame({ ...game, seo_title_local: e.target.value })}
                className="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800"
              />
            </div>
            <div className="sm:col-span-2">
              <Label htmlFor="seo_description">SEO description ({(game.seo_description ?? "").length}/160)</Label>
              <textarea
                id="seo_description"
                maxLength={160}
                rows={3}
                value={game.seo_description ?? ""}
                onChange={(e) => setGame({ ...game, seo_description: e.target.value })}
                className="w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
              />
            </div>
            <div className="sm:col-span-2">
              <Label htmlFor="seo_description_local">SEO description (local) ({(game.seo_description_local ?? "").length}/160)</Label>
              <textarea
                id="seo_description_local"
                maxLength={160}
                rows={3}
                value={game.seo_description_local ?? ""}
                onChange={(e) => setGame({ ...game, seo_description_local: e.target.value })}
                className="w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
              />
            </div>
            <div className="sm:col-span-2">
              <Label htmlFor="seo_keywords">Keywords</Label>
              <Input id="seo_keywords" value={game.seo_keywords ?? ""} onChange={(e) => setGame({ ...game, seo_keywords: e.target.value })} />
            </div>
          </div>
        </div>

        <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
          <h2 className="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">Open Graph Image</h2>
          <div className="flex items-end gap-3">
            <div className="flex-1">
              <Label htmlFor="seo_og_image">OG image URL</Label>
              <Input id="seo_og_image" value={game.seo_og_image ?? ""} onChange={(e) => setGame({ ...game, seo_og_image: e.target.value })} />
            </div>
            <Button
              type="button"
              variant="outlined"
              disabled={!game.image_url}
              onClick={() => game.image_url && setGame({ ...game, seo_og_image: game.image_url })}
            >
              Use Game Image
            </Button>
          </div>
        </div>

        <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
          <h2 className="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">Structured Data</h2>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <Label htmlFor="schema_brand">Brand</Label>
              <Input id="schema_brand" value={game.schema_brand ?? ""} onChange={(e) => setGame({ ...game, schema_brand: e.target.value })} />
            </div>
            <div>
              <Label htmlFor="schema_category">Category</Label>
              <Input id="schema_category" value={game.schema_category ?? ""} onChange={(e) => setGame({ ...game, schema_category: e.target.value })} />
            </div>
          </div>
          <div className="mt-4 flex items-center justify-between">
            <div>
              <span className="text-sm text-gray-700 dark:text-gray-300">Noindex this page</span>
              <p className="text-theme-xs text-gray-500 dark:text-gray-400">Tells search engines not to index this game&apos;s page.</p>
            </div>
            <Switch checked={game.no_index} onChange={(v) => setGame({ ...game, no_index: v })} />
          </div>
        </div>
      </div>

      <div className="mt-6 flex justify-end gap-3 border-t border-gray-100 pt-5 dark:border-gray-800">
        <Button type="button" onClick={handleSave} disabled={saving}>
          {saving ? "Saving…" : "Save"}
        </Button>
      </div>
    </div>
  );
}
