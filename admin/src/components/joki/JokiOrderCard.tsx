"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Tag } from "@/components/ui/tag";
import { ApiError } from "@/lib/api-client";
import { updateJokiProgress, type JokiOrderDetail, type JokiRank } from "@/lib/joki";
import { JokiAssignControl } from "@/components/joki/JokiAssignControl";
import { RankPicker } from "@/components/joki/RankPicker";

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString() : "—");

/**
 * ADR-126 decisions 10, 16: Order Detail's Joki card — the climb and the
 * customer's requests, who has it, Assign (Pending only), a progress
 * update the penjoki gave another way (with a penjoki only), and every
 * Assign and progress update so far.
 */
export function JokiOrderCard({ orderId, joki, supplierId, supplierName, isPending, onChanged }: {
  orderId: number;
  joki: JokiOrderDetail;
  supplierId: number | null;
  supplierName: string | null;
  isPending: boolean;
  onChanged: () => void;
}) {
  const start = joki.current_rank ?? joki.from_rank;
  const [rank, setRank] = useState<JokiRank>({ tier_id: start?.tier_id ?? joki.tiers[0]?.id ?? 0, division: start?.division ?? null, stars: start?.stars ?? 0 });
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function saveProgress() {
    setSaving(true);
    setError(null);
    try {
      await updateJokiProgress(orderId, { tier_id: rank.tier_id, division: rank.division, stars: rank.stars ?? 0 });
      onChanged();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save the progress.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="rounded-2xl border border-gray-200 bg-surface p-6 dark:border-gray-800">
      <h2 className="mb-4 flex items-center gap-2 text-section-title font-semibold text-ink">
        Joki {joki.is_express && <Tag severity="warn">Express</Tag>}
      </h2>

      <dl className="grid gap-x-6 gap-y-2 text-theme-sm sm:grid-cols-2">
        <div><dt className="text-ink-muted">Climb</dt><dd className="text-ink">{joki.from_rank?.label ?? "—"} → {joki.to_rank?.label ?? "—"}</dd></div>
        <div><dt className="text-ink-muted">Rank now</dt><dd className="text-ink">{joki.current_rank?.label ?? "Not started"}{joki.current_rank_at && <span className="text-theme-xs text-ink-subtle"> · {when(joki.current_rank_at)}</span>}</dd></div>
        <div><dt className="text-ink-muted">Penjoki</dt><dd className="text-ink">{joki.is_with_penjoki ? supplierName : "Waiting for a penjoki"}</dd></div>
        <div><dt className="text-ink-muted">Payout share</dt><dd className="text-ink">{joki.payout_share_pct ?? "—"}%</dd></div>
        <div><dt className="text-ink-muted">Role</dt><dd className="text-ink">{joki.role ?? "—"}</dd></div>
        <div><dt className="text-ink-muted">Hero request</dt><dd className="text-ink">{joki.hero_request ?? "—"}</dd></div>
        <div className="sm:col-span-2"><dt className="text-ink-muted">Play window</dt><dd className="text-ink">{joki.play_window ?? "—"}</dd></div>
      </dl>

      {isPending && (
        <div className="mt-5 space-y-4 border-t border-gray-100 pt-4 dark:border-gray-800">
          <div>
            <p className="mb-2 text-theme-sm font-medium text-ink">{joki.is_with_penjoki ? "Move to another penjoki" : "Assign a penjoki"}</p>
            <JokiAssignControl orderId={orderId} currentSupplierId={supplierId} onDone={onChanged} />
          </div>
          {joki.is_with_penjoki && (
            <div>
              <p className="mb-2 text-theme-sm font-medium text-ink">Update progress</p>
              <p className="mb-2 text-theme-xs text-ink-muted">When the penjoki reports a rank outside the group&apos;s <code>.progress</code>. Opted-in customers get a paced WhatsApp update.</p>
              <div className="flex flex-wrap items-center gap-2">
                <RankPicker idPrefix={`progress-${orderId}`} tiers={joki.tiers} value={rank} onChange={setRank} />
                <Button size="small" variant="outlined" onClick={saveProgress} disabled={saving}>Save progress</Button>
              </div>
              {error && <p className="mt-1 text-theme-xs text-error-600 dark:text-error-400">{error}</p>}
            </div>
          )}
        </div>
      )}

      {(joki.assignments.length > 0 || joki.progress.length > 0) && (
        <div className="mt-5 grid gap-4 border-t border-gray-100 pt-4 text-theme-xs text-ink-muted sm:grid-cols-2 dark:border-gray-800">
          <div>
            <p className="mb-1 font-medium text-ink">Assignments</p>
            {joki.assignments.length === 0 ? <p>None yet.</p> : (
              <ul className="space-y-1">{joki.assignments.map((a, i) => <li key={i}>{when(a.at)}: {a.from} → {a.to} (by {a.by})</li>)}</ul>
            )}
          </div>
          <div>
            <p className="mb-1 font-medium text-ink">Progress</p>
            {joki.progress.length === 0 ? <p>None yet.</p> : (
              <ul className="space-y-1">{joki.progress.map((p, i) => <li key={i}>{when(p.at)}: {p.rank} ({p.channel}, {p.by})</li>)}</ul>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
