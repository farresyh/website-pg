"use client";

/**
 * ADR-125 decisions 8/16: every paid order on a manual supplier still
 * waiting for a human (Pending, or NeedsReview after a worker died),
 * oldest first. Before PR 2's supplier WhatsApp group, the Stale tag (past
 * the supplier's reply SLA) is the alert. Any admin may confirm (Q10).
 */

import React, { useEffect, useState } from "react";
import Link from "next/link";
import { Tag } from "@/components/ui/tag";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api-client";
import { useClientSession } from "@/hooks/useClientSession";
import { type ManualQueueItem, listManualQueue } from "@/lib/orders";
import ManualOrderActions from "@/components/orders/ManualOrderActions";

const rm = (sen: number) => `RM ${(sen / 100).toFixed(2)}`;

export default function ManualQueuePage() {
  const session = useClientSession();
  const [items, setItems] = useState<ManualQueueItem[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  function load() {
    return listManualQueue()
      .then((list) => {
        setItems(list);
        setError(null);
      })
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load the queue."));
  }

  function refresh() {
    void load();
  }

  useEffect(() => {
    if (session) void load();
  }, [session]);

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Manual Queue</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Login top-ups waiting for the supplier. Confirm each one once the supplier tells you it is done.
          </p>
        </div>
        <Button size="small" variant="outlined" onClick={refresh}>Refresh</Button>
      </div>

      {error && <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>}
      {items === null && !error && <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>}
      {items?.length === 0 && (
        <p className="rounded-2xl border border-gray-200 bg-white p-6 text-center text-sm text-gray-500 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-400">
          Nothing waiting.
        </p>
      )}

      <ul className="space-y-3">
        {items?.map((item) => (
          <li key={item.id} className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div className="space-y-1 text-sm">
                <p className="font-medium text-gray-800 dark:text-white/90">
                  <Link href={`/admin/orders?order=${item.id}`} className="hover:underline">{item.order_number}</Link>{" "}
                  {item.delivery_status === "needs_review" && <Tag severity="warn">Needs review</Tag>}{" "}
                  {item.is_stale && <Tag severity="danger">Stale</Tag>}{" "}
                  {item.login_wiped && <Tag severity="danger">Login wiped</Tag>}
                </p>
                <p className="text-gray-600 dark:text-gray-400">
                  {item.package ?? "—"} · {item.game ?? "—"} · {item.supplier ?? "—"}
                </p>
                <p className="text-gray-600 dark:text-gray-400">
                  Login: <span className="font-mono">{item.player_id}</span> · Cost {rm(item.cost_price)} · Sold {rm(item.selling_price)}
                </p>
                <p className="text-theme-xs text-ink-subtle">Paid {item.paid_at ? new Date(item.paid_at).toLocaleString() : "—"}</p>
              </div>
              <ManualOrderActions order={item} onDone={refresh} />
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
}
