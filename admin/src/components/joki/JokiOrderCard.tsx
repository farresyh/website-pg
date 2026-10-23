"use client";

import { useState } from "react";
import { Trophy } from "@primeicons/react/trophy";
import { Button } from "@/components/ui/button";
import { Tag } from "@/components/ui/tag";
import { ApiError } from "@/lib/api-client";
import { updateJokiProgress, type JokiOrderDetail, type JokiRank } from "@/lib/joki";
import { JokiAssignControl } from "@/components/joki/JokiAssignControl";
import { RankPicker } from "@/components/joki/RankPicker";
import { JOKI_ERROR } from "@/components/joki/jokiStyles";

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString() : "—");

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex justify-between gap-4">
      <dt className="text-ink-muted">{label}</dt>
      <dd className="text-right text-ink">{children}</dd>
    </div>
  );
}

/**
 * ADR-126 decisions 10, 16: Order Detail's Joki card, laid out like its
 * neighbours (OrderDetailCards): the climb and the customer's requests,
 * who has it, Assign (Pending only), a progress update the penjoki gave
 * another way (with a penjoki only), and every Assign and update so far.
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
        <Trophy className="h-4 w-4 text-ink-muted" />
        Joki
        {joki.is_express && <Tag severity="warn">Express</Tag>}
      </h2>

      <div className="grid gap-x-10 gap-y-2 lg:grid-cols-2">
        <dl className="space-y-2 text-sm">
          <Row label="Climb">{joki.from_rank?.label ?? "—"} → {joki.to_rank?.label ?? "—"}</Row>
          <Row label="Rank now">
            {joki.current_rank?.label ?? "Not started"}
            {joki.current_rank_at && <span className="block text-theme-xs text-ink-muted">{when(joki.current_rank_at)}</span>}
          </Row>
          <Row label="Penjoki">{joki.is_with_penjoki ? supplierName : <span className="text-purple-ink">Unassigned</span>}</Row>
          <Row label="Payout share">{joki.payout_share_pct ?? "—"}%</Row>
        </dl>
        <dl className="space-y-2 text-sm">
          <Row label="Role">{joki.role ?? "—"}</Row>
          <Row label="Hero request">{joki.hero_request ?? "—"}</Row>
          <Row label="Play window">{joki.play_window ?? "—"}</Row>
        </dl>
      </div>

      {isPending && (
        <div className="mt-6 grid gap-6 border-t border-gray-100 pt-6 lg:grid-cols-2 dark:border-gray-800">
          <div>
            <h3 className="mb-1 text-sm font-semibold text-ink">{joki.is_with_penjoki ? "Move to another penjoki" : "Assign a penjoki"}</h3>
            <p className="mb-3 text-theme-xs text-ink-muted">They get the order and a fresh login link in their WhatsApp group.</p>
            <JokiAssignControl orderId={orderId} currentSupplierId={supplierId} onDone={onChanged} />
          </div>
          {joki.is_with_penjoki && (
            <div>
              <h3 className="mb-1 text-sm font-semibold text-ink">Update progress</h3>
              <p className="mb-3 text-theme-xs text-ink-muted">When the penjoki reports a rank outside the group&apos;s <code>.progress</code>. Opted-in customers get a paced WhatsApp update.</p>
              <div className="flex flex-wrap items-center gap-2">
                <RankPicker idPrefix={`progress-${orderId}`} tiers={joki.tiers} value={rank} onChange={setRank} />
                <Button size="small" variant="outlined" onClick={saveProgress} disabled={saving}>{saving ? "Saving…" : "Save progress"}</Button>
              </div>
              {error && <p className={`mt-3 ${JOKI_ERROR}`}>{error}</p>}
            </div>
          )}
        </div>
      )}

      <div className="mt-6 grid gap-6 border-t border-gray-100 pt-6 text-sm lg:grid-cols-2 dark:border-gray-800">
        <div>
          <h3 className="mb-2 text-sm font-semibold text-ink">Assignments</h3>
          {joki.assignments.length === 0 ? (
            <p className="text-ink-muted">None yet.</p>
          ) : (
            <ul className="space-y-1.5">
              {joki.assignments.map((a, i) => (
                <li key={i} className="text-ink-muted">
                  <span className="text-ink">{a.from} → {a.to}</span>
                  <span className="block text-theme-xs">{when(a.at)} · by {a.by}</span>
                </li>
              ))}
            </ul>
          )}
        </div>
        <div>
          <h3 className="mb-2 text-sm font-semibold text-ink">Progress</h3>
          {joki.progress.length === 0 ? (
            <p className="text-ink-muted">None yet.</p>
          ) : (
            <ul className="space-y-1.5">
              {joki.progress.map((p, i) => (
                <li key={i} className="text-ink-muted">
                  <span className="text-ink">{p.rank}</span>
                  <span className="block text-theme-xs">{when(p.at)} · {p.channel}, {p.by}</span>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </div>
  );
}
