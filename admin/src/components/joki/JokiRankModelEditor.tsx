"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { ImageUrlInput } from "@/components/gallery/ImageUrlInput";
import { ApiError } from "@/lib/api-client";
import { updateJokiTiers, type JokiTier } from "@/lib/joki";
import { tierUpdate } from "@/lib/joki-tiers";
import { JOKI_CARD, JOKI_CARD_HINT, JOKI_CARD_TITLE, JOKI_ERROR, JOKI_TD, JOKI_TH } from "@/components/joki/jokiStyles";

/**
 * ADR-126 decisions 3-4: the rank model, bottom first. Rates are whole
 * ringgit per star (Express not below Reguler). A divisional tier has
 * divisions × stars; a Mythic tier starts at a Mythic star count. Icons
 * come from the Gallery. The backend refuses a change that leaves any
 * route package off the ladder.
 */
export function JokiRankModelEditor({ tiers, onSaved }: { tiers: JokiTier[]; onSaved: () => Promise<unknown> }) {
  const [rows, setRows] = useState(tiers);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const set = (id: number, patch: Partial<JokiTier>) => setRows((all) => all.map((t) => (t.id === id ? { ...t, ...patch } : t)));
  const num = (v: string) => (v === "" ? 0 : Number(v));

  async function save() {
    setSaving(true);
    setError(null);
    try {
      await updateJokiTiers(rows.map(tierUpdate));
      await onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save the rank model.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <section className={JOKI_CARD}>
      <h2 className={JOKI_CARD_TITLE}>Rank model and rates</h2>
      <p className={JOKI_CARD_HINT}>RM per star, charged at the tier the star is won in. Orders keep the model they were priced with.</p>

      <div className="mt-5 overflow-hidden rounded-xl border border-gray-100 dark:border-gray-800">
        <div className="max-w-full overflow-x-auto">
          <table className="min-w-full">
            <thead className="border-b border-gray-100 dark:border-gray-800">
              <tr>
                <th className={JOKI_TH}>Tier</th>
                <th className={JOKI_TH}>Shape</th>
                <th className={JOKI_TH}>Reguler (RM/★)</th>
                <th className={JOKI_TH}>Express (RM/★)</th>
                <th className={JOKI_TH}>Icon</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
              {rows.map((t) => (
                <tr key={t.id}>
                  <td className={`${JOKI_TD} min-w-[12rem]`}><Input aria-label="Tier name" value={t.name} onChange={(e) => set(t.id, { name: e.target.value })} /></td>
                  <td className={`${JOKI_TD} whitespace-nowrap`}>
                    {t.kind === "divisional" ? (
                      <div className="flex items-center gap-2">
                        <div className="w-16"><Input aria-label="Divisions" type="number" min={1} max={8} value={String(t.divisions ?? 1)} onChange={(e) => set(t.id, { divisions: num(e.target.value) })} /></div>
                        <span>div ×</span>
                        <div className="w-16"><Input aria-label="Stars per division" type="number" min={1} max={10} value={String(t.stars_per_division ?? 1)} onChange={(e) => set(t.id, { stars_per_division: num(e.target.value) })} /></div>
                        <span>★</span>
                      </div>
                    ) : (
                      <div className="flex items-center gap-2">
                        <span>from</span>
                        <div className="w-20"><Input aria-label="Starts at Mythic stars" type="number" min={0} value={String(t.mythic_from_stars ?? 0)} onChange={(e) => set(t.id, { mythic_from_stars: num(e.target.value) })} /></div>
                        <span>★</span>
                      </div>
                    )}
                  </td>
                  <td className={JOKI_TD}><div className="w-20"><Input aria-label="Reguler rate" type="number" min={1} step={1} value={String(t.rate_rm)} onChange={(e) => set(t.id, { rate_rm: num(e.target.value) })} /></div></td>
                  <td className={JOKI_TD}><div className="w-20"><Input aria-label="Express rate" type="number" min={1} step={1} value={String(t.express_rate_rm)} onChange={(e) => set(t.id, { express_rate_rm: num(e.target.value) })} /></div></td>
                  <td className={`${JOKI_TD} min-w-[18rem]`}>
                    <ImageUrlInput id={`tier-icon-${t.id}`} value={t.icon_url ?? ""} onChange={(url) => set(t.id, { icon_url: url })} placeholder="Rank icon" />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {error && <p className={`mt-4 ${JOKI_ERROR}`}>{error}</p>}
      <div className="mt-6 flex justify-end">
        <Button onClick={save} disabled={saving}>{saving ? "Saving…" : "Save rank model"}</Button>
      </div>
    </section>
  );
}
