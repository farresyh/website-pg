"use client";

/**
 * ADR-139 decisions 3, 6, 12, 17 and the terms addendum's notice rule: a new
 * ladder version. It starts from the newest version (or the starting
 * thresholds), shows the month it takes effect before saving, and flags a
 * cell above the 50% paper ceiling. The server (RebateLadderService) is the
 * authority on every rule; saving needs the MFA step-up, which the api client
 * asks for, and tells every eligible reseller on WhatsApp.
 */

import { useState } from "react";
import {
  Dialog,
  DialogPortal,
  DialogBackdrop,
  DialogPositioner,
  DialogPopup,
  DialogHeader,
  DialogHeaderActions,
  DialogClose,
  DialogTitle,
  DialogContent,
  DialogFooter,
} from "@/components/ui/dialog";
import { Times as CloseIcon } from "@primeicons/react/times";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ApiError } from "@/lib/api-client";
import { notify } from "@/components/ui/toast";
import { formatRebateDate, rebateCeilingSen, saveRebateLadder, type RebateLadder, type RebateLadders, type RebateTier } from "@/lib/rebate";

/** Decision 1's starting thresholds, in RM, for a first version. */
const STARTING_THRESHOLDS = ["500", "1500", "3000", "5000", "10000", "20000"];

interface Row {
  threshold: string;
  rewards: Record<string, string>;
}

const toRm = (sen: number) => String(sen / 100);
const toSen = (rm: string) => Math.round(parseFloat(rm) * 100);

function startingRows(base: RebateLadder | undefined, tiers: RebateTier[]): Row[] {
  if (base && base.steps.length > 0) {
    return base.steps.map((s) => ({
      threshold: toRm(s.threshold_sen),
      rewards: Object.fromEntries(tiers.map((t) => [String(t.id), s.rewards[String(t.id)] === undefined ? "" : toRm(s.rewards[String(t.id)])])),
    }));
  }
  return STARTING_THRESHOLDS.map((threshold) => ({ threshold, rewards: Object.fromEntries(tiers.map((t) => [String(t.id), ""])) }));
}

interface Props {
  isOpen: boolean;
  onClose: () => void;
  data: RebateLadders;
  onSaved: () => void;
}

function Content({ data, onSaved }: Omit<Props, "isOpen" | "onClose">) {
  const tiers = data.tiers.filter((t) => t.is_active);
  const base = data.ladders[0];
  const [rows, setRows] = useState<Row[]>(() => startingRows(base, tiers));
  const [sharePct, setSharePct] = useState(base ? String(base.max_share_bp / 100) : "35");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const ceilingPct = data.hard_ceiling_bp / 100;
  // A tier column is in the version only when every step has a number for it (the server refuses a ragged grid).
  const filledTiers = tiers.filter((t) => rows.length > 0 && rows.every((r) => r.rewards[String(t.id)]?.trim() !== ""));

  function setCell(i: number, tierId: string | null, value: string) {
    setRows((current) => current.map((r, j) => (j !== i ? r : tierId === null ? { ...r, threshold: value } : { ...r, rewards: { ...r.rewards, [tierId]: value } })));
  }

  async function handleSave(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    const shareBp = Math.round(parseFloat(sharePct) * 100);
    const steps = rows.map((r) => ({
      threshold_sen: toSen(r.threshold),
      rewards: Object.fromEntries(filledTiers.map((t) => [String(t.id), toSen(r.rewards[String(t.id)])])),
    }));

    if (!Number.isFinite(shareBp) || shareBp < 1 || shareBp > data.hard_ceiling_bp) {
      setError(`The monthly cap must be between 0.01% and ${ceilingPct}% of profit.`);
      return;
    }
    if (steps.some((s) => !Number.isFinite(s.threshold_sen) || Object.values(s.rewards).some((v) => !Number.isFinite(v)))) {
      setError("Every threshold and reward must be an amount in RM.");
      return;
    }

    setSaving(true);
    try {
      const saved = await saveRebateLadder(steps, shareBp);
      notify.success("Ladder saved", `Takes effect on ${formatRebateDate(saved.effective_from)}. Eligible resellers are told on WhatsApp.`);
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save the ladder.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSave}>
      <div className="space-y-4">
        <p className="rounded-lg bg-brand-50 px-3 py-2 text-theme-sm text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
          This version takes effect on <strong>{formatRebateDate(data.next_effective_from)}</strong> (the first 1st at least 7 days away) and
          never changes a month already running. Saving tells every eligible reseller on WhatsApp.
        </p>

        <div className="max-w-xs">
          <Label htmlFor="rebate_share">Monthly cap (% of our actual profit on each reseller&apos;s orders)</Label>
          <Input id="rebate_share" type="number" step="0.01" min="0.01" max={ceilingPct} value={sharePct} onChange={(e) => setSharePct(e.target.value)} />
          <p className="mt-1 text-theme-xs text-ink-muted">At most {ceilingPct}%. Resellers never see this number.</p>
        </div>

        <div className="max-w-full overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
          <table className="min-w-full text-theme-sm">
            <thead className="border-b border-gray-100 dark:border-gray-800">
              <tr>
                <th className="px-3 py-2 text-start text-theme-xs font-medium text-ink-muted">Sales reached (RM)</th>
                {tiers.map((t) => (
                  <th key={t.id} className="px-3 py-2 text-start text-theme-xs font-medium text-ink-muted">
                    {t.name} (+{Number(t.markup_percent)}%), cumulative RM
                  </th>
                ))}
                <th className="px-3 py-2" />
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
              {rows.map((r, i) => (
                <tr key={i}>
                  <td className="px-3 py-2">
                    <Input aria-label={`Step ${i + 1} threshold`} type="number" step="0.01" min="0.01" value={r.threshold} onChange={(e) => setCell(i, null, e.target.value)} />
                  </td>
                  {tiers.map((t) => {
                    const value = r.rewards[String(t.id)] ?? "";
                    const ceiling = rebateCeilingSen(toSen(r.threshold), Number(t.markup_percent), data.hard_ceiling_bp);
                    const over = value.trim() !== "" && toSen(value) > ceiling;
                    return (
                      <td key={t.id} className="px-3 py-2">
                        <Input
                          aria-label={`Step ${i + 1} ${t.name} reward`}
                          type="number"
                          step="0.01"
                          min="0"
                          value={value}
                          error={over}
                          onChange={(e) => setCell(i, String(t.id), e.target.value)}
                        />
                        {over && <p className="mt-1 text-theme-xs text-error-600">Above the {ceilingPct}% ceiling (max RM {(ceiling / 100).toFixed(2)})</p>}
                      </td>
                    );
                  })}
                  <td className="px-3 py-2">
                    <Button type="button" size="small" variant="text" severity="danger" onClick={() => setRows((c) => c.filter((_, j) => j !== i))}>
                      Remove
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          <Button type="button" size="small" variant="outlined" onClick={() => setRows((c) => [...c, { threshold: "", rewards: Object.fromEntries(tiers.map((t) => [String(t.id), ""])) }])}>
            Add step
          </Button>
          <p className="text-theme-xs text-ink-muted">
            A tier left empty on any step gets no column and earns nothing. With no steps at all, the rebate is off from that month.
          </p>
        </div>

        {tiers.length > filledTiers.length && rows.length > 0 && (
          <p className="rounded-lg bg-warning-surface px-3 py-2 text-theme-sm text-warning-ink">
            No column (earns nothing): {tiers.filter((t) => !filledTiers.includes(t)).map((t) => t.name).join(", ")}.
          </p>
        )}
        {error && <p className="rounded-lg bg-danger-surface px-3 py-2 text-theme-sm text-danger-ink">{error}</p>}
      </div>

      <DialogFooter>
        <Button type="submit" disabled={saving}>{saving ? "Saving…" : `Save, effective ${formatRebateDate(data.next_effective_from)}`}</Button>
      </DialogFooter>
    </form>
  );
}

export default function RebateLadderEditor({ isOpen, onClose, data, onSaved }: Props) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-5xl">
            <DialogHeader>
              <DialogTitle>New rebate ladder version</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>{isOpen && <Content data={data} onSaved={onSaved} />}</DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
