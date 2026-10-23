"use client";

import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api-client";
import { assignJokiOrder, listPenjoki, type Penjoki } from "@/lib/joki";
import { JOKI_INPUT } from "@/components/joki/RankPicker";

/**
 * ADR-126 decision 16: hand a Pending joki order to a penjoki (or move it).
 * Only active penjoki with a WhatsApp group can be picked; the backend
 * checks again under the lock and posts the order and a fresh login link
 * to the penjoki's group.
 */
export function JokiAssignControl({ orderId, currentSupplierId, onDone, penjoki: given }: {
  orderId: number;
  currentSupplierId: number | null;
  onDone: () => void;
  /** A list page passes the list it already has; Order Detail loads it. */
  penjoki?: Penjoki[];
}) {
  const [loaded, setLoaded] = useState<Penjoki[] | null>(null);
  const [choice, setChoice] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (given) return;
    listPenjoki().then(setLoaded).catch(() => setError("Could not load penjoki."));
  }, [given]);

  const options = (given ?? loaded ?? []).filter((p) => p.is_active && p.has_group && p.id !== currentSupplierId);

  async function assign() {
    if (!choice) return;
    setBusy(true);
    setError(null);
    try {
      await assignJokiOrder(orderId, Number(choice));
      setChoice("");
      onDone();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not assign.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-1">
      <div className="flex flex-wrap items-center gap-2">
        <select aria-label="Penjoki" className={JOKI_INPUT} value={choice} onChange={(e) => setChoice(e.target.value)} disabled={busy}>
          <option value="">{options.length === 0 ? "No penjoki available" : "Choose a penjoki…"}</option>
          {options.map((p) => <option key={p.id} value={p.id}>{p.name} ({p.open_orders} open)</option>)}
        </select>
        <Button size="small" onClick={assign} disabled={!choice || busy}>Assign</Button>
      </div>
      {error && <p className="text-theme-xs text-error-600 dark:text-error-400">{error}</p>}
    </div>
  );
}
