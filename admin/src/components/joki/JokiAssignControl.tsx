"use client";

import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { OptionSelect } from "@/components/ui/option-select";
import { ApiError } from "@/lib/api-client";
import { assignJokiOrder, listPenjoki, type Penjoki } from "@/lib/joki";

/**
 * ADR-126 decision 16: hand a Pending joki order to a penjoki (or move it).
 * Only active penjoki with a WhatsApp group can be picked; the backend
 * checks again under the lock and posts the order and a fresh login link
 * to the penjoki's group.
 */
export function JokiAssignControl({ orderId, currentSupplierId, onDone }: {
  orderId: number;
  currentSupplierId: number | null;
  onDone: () => void;
}) {
  const [penjoki, setPenjoki] = useState<Penjoki[] | null>(null);
  const [choice, setChoice] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    listPenjoki().then(setPenjoki).catch(() => setError("Could not load penjoki."));
  }, []);

  const options = (penjoki ?? []).filter((p) => p.is_active && p.has_group && p.id !== currentSupplierId);

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
    <div className="space-y-2">
      <div className="flex flex-wrap items-center gap-2">
        {penjoki !== null && options.length === 0 ? (
          <p className="text-sm text-ink-muted">
            {currentSupplierId ? "No other active penjoki with a WhatsApp group." : "No active penjoki with a WhatsApp group yet. Add one in Middleware → Suppliers."}
          </p>
        ) : (
          <>
            <OptionSelect
              ariaLabel="Penjoki"
              className="min-w-[14rem]"
              value={choice}
              options={options.map((p) => ({ label: `${p.name} · ${p.open_orders} open`, value: String(p.id) }))}
              onChange={setChoice}
              disabled={busy || penjoki === null}
              placeholder="Choose a penjoki"
            />
            <Button size="small" onClick={assign} disabled={!choice || busy}>
              {busy ? "Assigning…" : "Assign"}
            </Button>
          </>
        )}
      </div>
      {error && <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>}
    </div>
  );
}
