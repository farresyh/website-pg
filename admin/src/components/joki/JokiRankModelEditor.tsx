"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { ImageUrlInput } from "@/components/gallery/ImageUrlInput";
import { ApiError } from "@/lib/api-client";
import { updateJokiTiers, type JokiTier } from "@/lib/joki";
import { JOKI_INPUT } from "@/components/joki/RankPicker";

const TH = "px-3 py-2 text-start text-theme-xs font-medium text-ink-muted";
const TD = "px-3 py-2 align-top text-theme-sm";
const NUM = `${JOKI_INPUT} w-20`;

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
      await updateJokiTiers(rows.map(({ id, name, divisions, stars_per_division, mythic_from_stars, rate_rm, express_rate_rm, icon_url }) => ({
        id, name, divisions, stars_per_division, mythic_from_stars, rate_rm, express_rate_rm, icon_url: icon_url || null,
      })));
      await onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save the rank model.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <section className="rounded-2xl border border-gray-200 bg-surface p-6 dark:border-gray-800">
      <h2 className="mb-1 text-section-title font-semibold text-ink">Rank model and rates</h2>
      <p className="mb-4 text-theme-xs text-ink-muted">Rates are RM per star, charged at the tier the star is won in. Orders keep the model they were priced with.</p>
      <div className="max-w-full overflow-x-auto">
        <table className="min-w-full">
          <thead className="border-b border-gray-100 dark:border-gray-800">
            <tr>
              <th className={TH}>Tier</th>
              <th className={TH}>Shape</th>
              <th className={TH}>Reguler RM/★</th>
              <th className={TH}>Express RM/★</th>
              <th className={TH}>Icon</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
            {rows.map((t) => (
              <tr key={t.id}>
                <td className={TD}><input aria-label="Tier name" className={`${JOKI_INPUT} w-44`} value={t.name} onChange={(e) => set(t.id, { name: e.target.value })} /></td>
                <td className={`${TD} whitespace-nowrap text-ink-muted`}>
                  {t.kind === "divisional" ? (
                    <>
                      <input aria-label="Divisions" type="number" min={1} max={8} className={NUM} value={t.divisions ?? 1} onChange={(e) => set(t.id, { divisions: num(e.target.value) })} /> div ×{" "}
                      <input aria-label="Stars per division" type="number" min={1} max={10} className={NUM} value={t.stars_per_division ?? 1} onChange={(e) => set(t.id, { stars_per_division: num(e.target.value) })} /> ★
                    </>
                  ) : (
                    <>from <input aria-label="Starts at Mythic stars" type="number" min={0} className={NUM} value={t.mythic_from_stars ?? 0} onChange={(e) => set(t.id, { mythic_from_stars: num(e.target.value) })} /> ★</>
                  )}
                </td>
                <td className={TD}><input aria-label="Reguler rate" type="number" min={1} step={1} className={NUM} value={t.rate_rm} onChange={(e) => set(t.id, { rate_rm: num(e.target.value) })} /></td>
                <td className={TD}><input aria-label="Express rate" type="number" min={1} step={1} className={NUM} value={t.express_rate_rm} onChange={(e) => set(t.id, { express_rate_rm: num(e.target.value) })} /></td>
                <td className={`${TD} min-w-[16rem]`}>
                  <ImageUrlInput id={`tier-icon-${t.id}`} value={t.icon_url ?? ""} onChange={(url) => set(t.id, { icon_url: url })} placeholder="Rank icon" />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {error && <p className="mt-3 text-sm text-error-600 dark:text-error-400">{error}</p>}
      <div className="mt-4 flex justify-end">
        <Button size="small" onClick={save} disabled={saving}>Save rank model</Button>
      </div>
    </section>
  );
}
