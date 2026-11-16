"use client";

/**
 * ADR-142 decision 26: a reseller's status with why and since when it is off,
 * and its full status history (who switched it, and the dormancy job's own
 * changes) one click away.
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
} from "@/components/ui/dialog";
import { Times as CloseIcon } from "@primeicons/react/times";
import { Tag } from "@/components/ui/tag";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api-client";
import { getReseller, type ResellerRow, type ResellerStatusChange } from "@/lib/resellers";

const REASON: Record<string, string> = {
  manual: "by an admin",
  dormant: "for inactivity",
  closed: "account closed",
};

function formatWhen(iso: string): string {
  return new Date(iso).toLocaleString("en-MY", { timeZone: "Asia/Kuala_Lumpur", day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
}

export function deactivationLabel(r: Pick<ResellerRow, "deactivation_reason">): string | null {
  return r.deactivation_reason ? REASON[r.deactivation_reason] ?? r.deactivation_reason : null;
}

export default function ResellerStatusCell({ reseller }: { reseller: ResellerRow }) {
  const [open, setOpen] = useState(false);
  const [history, setHistory] = useState<ResellerStatusChange[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const label = deactivationLabel(reseller);

  async function openHistory() {
    setOpen(true);
    setHistory(null);
    setError(null);
    try {
      setHistory((await getReseller(reseller.id)).status_history);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load the history.");
    }
  }

  return (
    <>
      <Tag severity={reseller.is_active ? "success" : reseller.deactivation_reason === "dormant" ? "warn" : "secondary"}>
        {reseller.is_active ? "active" : "inactive"}
      </Tag>
      {!reseller.is_active && label && (
        <p className="mt-1 text-theme-xs text-ink-subtle">
          {label}
          {reseller.deactivated_at && <>, {formatWhen(reseller.deactivated_at)}</>}
        </p>
      )}
      <Button size="small" variant="link" className="mt-1 block px-0 text-theme-xs" onClick={openHistory}>
        History
      </Button>

      <Dialog open={open} onOpenChange={(e) => !e.value && setOpen(false)}>
        <DialogPortal>
          <DialogBackdrop />
          <DialogPositioner>
            <DialogPopup className="max-w-lg">
              <DialogHeader>
                <DialogTitle>Status history — {reseller.business_name}</DialogTitle>
                <DialogHeaderActions>
                  <DialogClose aria-label="Close">
                    <CloseIcon />
                  </DialogClose>
                </DialogHeaderActions>
              </DialogHeader>
              <DialogContent>
                {error && <p className="rounded-lg bg-danger-surface px-3 py-2 text-theme-sm text-danger-ink">{error}</p>}
                {!error && history === null && <p className="text-theme-sm text-ink-subtle">Loading…</p>}
                {history !== null && history.length === 0 && (
                  <p className="text-theme-sm text-ink-subtle">No change since the account was created.</p>
                )}
                {history !== null && history.length > 0 && (
                  <ul className="divide-y divide-border">
                    {history.map((c, i) => (
                      <li key={i} className="flex justify-between gap-4 py-2 text-theme-sm">
                        <span className="text-ink">
                          {c.is_active ? "Activated" : `Deactivated ${c.reason ? REASON[c.reason] ?? c.reason : ""}`}
                          <span className="text-ink-subtle"> · {c.admin_name ?? "automatic"}</span>
                        </span>
                        <span className="shrink-0 text-ink-subtle">{formatWhen(c.at)}</span>
                      </li>
                    ))}
                  </ul>
                )}
              </DialogContent>
            </DialogPopup>
          </DialogPositioner>
        </DialogPortal>
      </Dialog>
    </>
  );
}
