"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Tag } from "@/components/ui/tag";
import { ApiError } from "@/lib/api-client";
import { createJokiRoute, updateJokiRoute, type JokiRank, type JokiRouteRow, type JokiTier } from "@/lib/joki";
import { JOKI_INPUT, RankPicker } from "@/components/joki/RankPicker";

/**
 * ADR-126 decision 5: route packages — a founder-set promo price for one
 * climb (Reguler only), or a quotation the page sends to WhatsApp. A route
 * is switched off, never deleted: past orders point at its package.
 */
export function JokiRoutesEditor({ tiers, routes, onSaved }: { tiers: JokiTier[]; routes: JokiRouteRow[]; onSaved: () => Promise<unknown> }) {
  const first = tiers[0];
  const lowestMythic = tiers.find((t) => t.kind === "mythic") ?? first;
  const [name, setName] = useState("");
  const [from, setFrom] = useState<JokiRank>({ tier_id: first?.id ?? 0, division: first?.divisions ?? null, stars: 0 });
  const [to, setTo] = useState<JokiRank>({ tier_id: lowestMythic?.id ?? 0, division: null, stars: 0 });
  const [price, setPrice] = useState("");
  const [isQuotation, setIsQuotation] = useState(false);
  const [prices, setPrices] = useState<Record<number, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function run(action: () => Promise<unknown>) {
    setBusy(true);
    setError(null);
    try {
      await action();
      await onSaved();
      return true;
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save the route.");
      return false;
    } finally {
      setBusy(false);
    }
  }

  async function add() {
    const ok = await run(() => createJokiRoute({ name: name.trim(), from, to, is_quotation: isQuotation, price_rm: isQuotation ? null : Number(price) }));
    if (ok) {
      setName("");
      setPrice("");
    }
  }

  return (
    <section className="rounded-2xl border border-gray-200 bg-surface p-6 dark:border-gray-800">
      <h2 className="mb-1 text-section-title font-semibold text-ink">Route packages</h2>
      <p className="mb-4 text-theme-xs text-ink-muted">Promo prices for a fixed climb, independent of the calculator, which points customers to a cheaper route for the same climb.</p>

      <ul className="mb-6 divide-y divide-gray-100 dark:divide-gray-800">
        {routes.length === 0 && <li className="py-2 text-sm text-ink-muted">No routes yet.</li>}
        {routes.map((r) => (
          <li key={r.id} className="flex flex-wrap items-center justify-between gap-3 py-3 text-theme-sm">
            <div>
              <p className="font-medium text-ink">{r.name} {!r.is_active && <Tag severity="secondary">Off</Tag>} {r.is_quotation && <Tag severity="info">Quotation</Tag>}</p>
              <p className="text-theme-xs text-ink-muted">{r.from.label} → {r.to.label}</p>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              {!r.is_quotation && (
                <>
                  <span className="text-theme-xs text-ink-muted">RM</span>
                  <input
                    aria-label={`${r.name} price`}
                    type="number"
                    min={1}
                    step={1}
                    className={`${JOKI_INPUT} w-24`}
                    value={prices[r.id] ?? String(r.price_rm ?? "")}
                    onChange={(e) => setPrices({ ...prices, [r.id]: e.target.value })}
                  />
                  <Button size="small" variant="outlined" disabled={busy || prices[r.id] === undefined} onClick={() => run(() => updateJokiRoute(r.id, { price_rm: Number(prices[r.id]) }))}>Save price</Button>
                </>
              )}
              <Button size="small" variant="outlined" disabled={busy} onClick={() => run(() => updateJokiRoute(r.id, { is_active: !r.is_active }))}>
                {r.is_active ? "Switch off" : "Switch on"}
              </Button>
            </div>
          </li>
        ))}
      </ul>

      <div className="space-y-3 rounded-xl border border-dashed border-gray-200 p-4 dark:border-gray-800">
        <p className="text-theme-sm font-medium text-ink">Add a route</p>
        <input aria-label="Route name" placeholder="e.g. Epic V → Mythic" className={`${JOKI_INPUT} w-full`} value={name} onChange={(e) => setName(e.target.value)} />
        <div className="flex flex-wrap items-center gap-2 text-theme-sm text-ink-muted">From <RankPicker idPrefix="route-from" tiers={tiers} value={from} onChange={setFrom} allowGrading /></div>
        <div className="flex flex-wrap items-center gap-2 text-theme-sm text-ink-muted">To <RankPicker idPrefix="route-to" tiers={tiers} value={to} onChange={(r) => setTo({ ...r, stars: r.stars ?? 0 })} /></div>
        <div className="flex flex-wrap items-center gap-3">
          <label className="flex items-center gap-2 text-theme-sm text-ink-muted">
            <input type="checkbox" checked={isQuotation} onChange={(e) => setIsQuotation(e.target.checked)} /> Quotation (no price)
          </label>
          {!isQuotation && (
            <span className="flex items-center gap-2 text-theme-sm text-ink-muted">
              RM <input aria-label="Route price" type="number" min={1} step={1} className={`${JOKI_INPUT} w-24`} value={price} onChange={(e) => setPrice(e.target.value)} />
            </span>
          )}
          <Button size="small" onClick={add} disabled={busy || !name.trim() || (!isQuotation && !price)}>Add route</Button>
        </div>
        {error && <p className="text-sm text-error-600 dark:text-error-400">{error}</p>}
      </div>
    </section>
  );
}
