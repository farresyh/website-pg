"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { StatusText } from "@/components/orders/StatusText";
import { ApiError } from "@/lib/api-client";
import { notify } from "@/components/ui/toast";
import { createJokiRoute, updateJokiRoute, type JokiRank, type JokiRouteRow, type JokiTier } from "@/lib/joki";
import { RankPicker } from "@/components/joki/RankPicker";
import { JOKI_CARD, JOKI_CARD_HINT, JOKI_CARD_TITLE, JOKI_TD, JOKI_TH } from "@/components/joki/jokiStyles";

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
  const [busy, setBusy] = useState(false);

  async function run(action: () => Promise<unknown>) {
    setBusy(true);
    try {
      await action();
      notify.success("Route saved");
      await onSaved();
      return true;
    } catch (err) {
      notify.error("Could not save the route", err instanceof ApiError ? err.message : "Action failed.");
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
    <section className={JOKI_CARD}>
      <h2 className={JOKI_CARD_TITLE}>Route packages</h2>
      <p className={JOKI_CARD_HINT}>Promo prices for a fixed climb, separate from the calculator. The calculator points customers to a cheaper route for the same climb.</p>

      <div className="mt-5 overflow-hidden rounded-xl border border-gray-100 dark:border-gray-800">
        <div className="max-w-full overflow-x-auto">
          <table className="min-w-full">
            <thead className="border-b border-gray-100 dark:border-gray-800">
              <tr>
                <th className={JOKI_TH}>Route</th>
                <th className={JOKI_TH}>Climb</th>
                <th className={JOKI_TH}>Price (RM)</th>
                <th className={JOKI_TH}>Status</th>
                <th className={JOKI_TH}>Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
              {routes.length === 0 && (
                <tr><td colSpan={5} className="p-6 text-center text-sm text-ink-muted">No route packages yet.</td></tr>
              )}
              {routes.map((r) => (
                <tr key={r.id}>
                  <td className={`${JOKI_TD} font-medium text-ink`}>{r.name}</td>
                  <td className={`${JOKI_TD} whitespace-nowrap`}>{r.from.label} → {r.to.label}</td>
                  <td className={JOKI_TD}>
                    {r.is_quotation ? (
                      <span>Quotation</span>
                    ) : (
                      <div className="w-24">
                        <Input aria-label={`${r.name} price`} type="number" min={1} step={1} value={prices[r.id] ?? String(r.price_rm ?? "")} onChange={(e) => setPrices({ ...prices, [r.id]: e.target.value })} />
                      </div>
                    )}
                  </td>
                  <td className={JOKI_TD}><StatusText severity={r.is_active ? "success" : "secondary"}>{r.is_active ? "on sale" : "off"}</StatusText></td>
                  <td className={JOKI_TD}>
                    <div className="flex gap-2">
                      {!r.is_quotation && prices[r.id] !== undefined && (
                        <Button size="small" disabled={busy} onClick={() => run(() => updateJokiRoute(r.id, { price_rm: Number(prices[r.id]) }))}>Save price</Button>
                      )}
                      <Button size="small" variant="outlined" disabled={busy} onClick={() => run(() => updateJokiRoute(r.id, { is_active: !r.is_active }))}>
                        {r.is_active ? "Switch off" : "Switch on"}
                      </Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <div className="mt-6 border-t border-gray-100 pt-6 dark:border-gray-800">
        <h3 className="mb-4 text-sm font-semibold text-ink">Add a route package</h3>
        <div className="grid gap-5">
          <div>
            <Label htmlFor="route-name">Name</Label>
            <Input id="route-name" placeholder="e.g. Epic V → Mythic" value={name} onChange={(e) => setName(e.target.value)} />
          </div>
          <div className="grid gap-5 lg:grid-cols-2">
            <div>
              <Label htmlFor="route-from-tier">From</Label>
              <RankPicker idPrefix="route-from" tiers={tiers} value={from} onChange={setFrom} allowGrading />
            </div>
            <div>
              <Label htmlFor="route-to-tier">To</Label>
              <RankPicker idPrefix="route-to" tiers={tiers} value={to} onChange={(r) => setTo({ ...r, stars: r.stars ?? 0 })} />
            </div>
          </div>
          <div className="flex flex-wrap items-end gap-5">
            <div className="w-40">
              <Label htmlFor="route-price">Price (RM)</Label>
              <Input id="route-price" type="number" min={1} step={1} disabled={isQuotation} value={price} onChange={(e) => setPrice(e.target.value)} />
            </div>
            <label className="flex h-11 items-center gap-2 text-sm text-ink-muted">
              <input type="checkbox" checked={isQuotation} onChange={(e) => setIsQuotation(e.target.checked)} />
              Quotation (no price, the customer asks on WhatsApp)
            </label>
          </div>
        </div>
        <div className="mt-6 flex justify-end">
          <Button onClick={add} disabled={busy || !name.trim() || (!isQuotation && !price)}>Add route package</Button>
        </div>
      </div>
    </section>
  );
}
