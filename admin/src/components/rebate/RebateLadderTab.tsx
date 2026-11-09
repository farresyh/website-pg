"use client";

/**
 * ADR-139 decisions 6, 17, 44: the ladder in force, the one waiting to start,
 * and every earlier version. Versions are never edited, so this list is the
 * ladder's audit log: who saved what, and when it took effect.
 */

import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { StatusText } from "@/components/orders/StatusText";
import { ApiError } from "@/lib/api-client";
import { formatRebateDate, getRebateLadders, type RebateLadder, type RebateLadders } from "@/lib/rebate";
import { RebateLadderGrid } from "@/components/rebate/RebateLadderGrid";
import { RebateTable, TD, TD_STRONG } from "@/components/rebate/RebateTable";
import RebateLadderEditor from "@/components/rebate/RebateLadderEditor";

const savedAt = (iso: string) => new Date(iso).toLocaleString("en-MY", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });

function VersionCard({ title, ladder, data }: { title: string; ladder: RebateLadder; data: RebateLadders }) {
  return (
    <RebateLadderGrid
      ladder={ladder}
      tiers={data.tiers}
      title={
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-section-title font-semibold text-ink">
            {title} <span className="font-normal text-ink-muted">from {formatRebateDate(ladder.effective_from)}</span>
          </h2>
          <p className="text-theme-xs text-ink-muted">
            Monthly cap {ladder.max_share_bp / 100}% of actual profit · saved by {ladder.saved_by ?? "—"}, {savedAt(ladder.saved_at)}
          </p>
        </div>
      }
    />
  );
}

export function RebateLadderTab({ reloadKey }: { reloadKey: number }) {
  const [data, setData] = useState<RebateLadders | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState(false);

  function load() {
    return getRebateLadders()
      .then((d) => {
        setData(d);
        setError(null);
      })
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load the ladder."));
  }

  useEffect(() => {
    load();
  }, [reloadKey]);

  if (error) return <p className="rounded-lg bg-danger-surface px-4 py-3 text-sm text-danger-ink">{error}</p>;
  if (!data) return <p className="text-sm text-ink-muted">Loading…</p>;

  const inForce = data.ladders.find((l) => l.status === "in_force");
  const upcoming = data.ladders.filter((l) => l.status === "upcoming");
  const noColumn = data.tiers.filter((t) => data.tiers_without_column.includes(t.id));

  return (
    <div className="pt-4">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p className="max-w-3xl text-theme-sm text-ink-muted">
          Resellers reach a step on their recognised sales in a Malaysia month and claim its fixed reward into Purchase Balance. Rewards are capped
          at a share of our actual profit on their orders. Resellers see only their own tier&apos;s &quot;reach RM X, get RM Y&quot;.
        </p>
        <Button onClick={() => setEditing(true)}>New version</Button>
      </div>

      {noColumn.length > 0 && (
        <p className="mb-4 rounded-lg bg-warning-surface px-4 py-3 text-theme-sm text-warning-ink">
          No column in the newest version, so these tiers earn nothing: {noColumn.map((t) => t.name).join(", ")}.
        </p>
      )}

      {upcoming.map((l) => (
        <VersionCard key={l.id} title="Starts next" ladder={l} data={data} />
      ))}
      {inForce ? (
        <VersionCard title="In force" ladder={inForce} data={data} />
      ) : (
        <p className="mb-6 rounded-lg border border-dashed border-gray-300 px-5 py-6 text-center text-theme-sm text-ink-muted dark:border-gray-700">
          No ladder is in force: the rebate is off. A saved version starts on {formatRebateDate(data.next_effective_from)}.
        </p>
      )}

      <RebateTable
        title={<h2 className="text-section-title font-semibold text-ink">All versions</h2>}
        headers={["Effective from", "Status", "Steps", "Monthly cap", "Saved by", "Saved at"]}
        rows={data.ladders}
        empty="No version saved yet."
        cells={(l) => [
          [TD_STRONG, formatRebateDate(l.effective_from)],
          [TD, <StatusText key="s" severity={l.status === "in_force" ? "success" : l.status === "upcoming" ? "info" : "secondary"}>{l.status === "in_force" ? "in force" : l.status}</StatusText>],
          [TD, l.steps.length === 0 ? "none (off)" : l.steps.length],
          [TD, `${l.max_share_bp / 100}%`],
          [TD, l.saved_by ?? "—"],
          [TD, savedAt(l.saved_at)],
        ]}
      />

      <RebateLadderEditor
        isOpen={editing}
        onClose={() => setEditing(false)}
        data={data}
        onSaved={() => {
          setEditing(false);
          load();
        }}
      />
    </div>
  );
}
