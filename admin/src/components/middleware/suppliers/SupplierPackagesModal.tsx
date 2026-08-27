"use client";

import React, { useState } from "react";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogPortal,
  DialogBackdrop,
  DialogPositioner,
  DialogPopup,
  DialogHeader,
  DialogTitle,
  DialogContent,
  DialogFooter,
} from "@/components/ui/dialog";
import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectIndicator,
  SelectPortal,
  SelectPositioner,
  SelectPopup,
  SelectList,
  SelectOption,
} from "@/components/ui/select";
import type { Game } from "@/lib/games";
import type { Supplier, UpdateSupplierPackagesStatusValues } from "@/lib/suppliers";

/**
 * ADR-046 decisions 9/10 — one modal, both directions. "Deactivate"
 * covers the founder's own real scenario: a supplier delay/outage
 * (All packages) or a problem isolated to a single game whose cheap
 * package happens to win ADR-034's storefront dedup (one Game).
 * "Reactivate" is scoped server-side to only the packages this same
 * mechanism turned off (deactivated_reason='supplier_issue') — this
 * modal doesn't need to know that, it's a backend guard.
 */
interface SupplierPackagesModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (values: UpdateSupplierPackagesStatusValues) => Promise<void>;
  supplier: Supplier | null;
  games: Game[];
}

function SupplierPackagesFields({
  onClose,
  onSubmit,
  supplier,
  games,
}: Omit<SupplierPackagesModalProps, "isOpen"> & { supplier: Supplier }) {
  const [mode, setMode] = useState<"deactivate" | "reactivate">("deactivate");
  const [scope, setScope] = useState<"all" | "game">("all");
  const [gameId, setGameId] = useState<string>(games[0] ? String(games[0].id) : "");
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const gameOptions = games.map((g) => ({ label: g.name, value: String(g.id) }));

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);

    if (mode === "deactivate" && !reason.trim()) {
      setError("A reason helps the audit trail (deactivation_logs) explain why later.");
      return;
    }
    if (scope === "game" && !gameId) {
      setError("Pick a game.");
      return;
    }

    setSubmitting(true);
    try {
      const values: UpdateSupplierPackagesStatusValues = {
        is_active: mode === "reactivate",
        ...(scope === "game" ? { game_id: Number(gameId) } : {}),
        ...(mode === "deactivate" ? { reason: reason.trim() } : {}),
      };
      await onSubmit(values);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>Manage {supplier.name}&apos;s packages</DialogTitle>
      </DialogHeader>
      <DialogContent>
        {error && (
          <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
            {error}
          </p>
        )}
        <form id="supplier-packages-form" onSubmit={handleSubmit} className="space-y-4">
          <div className="flex gap-4 text-sm text-gray-600 dark:text-gray-400">
            <label className="flex items-center gap-1.5">
              <input type="radio" checked={mode === "deactivate"} onChange={() => setMode("deactivate")} />
              Deactivate
            </label>
            <label className="flex items-center gap-1.5">
              <input type="radio" checked={mode === "reactivate"} onChange={() => setMode("reactivate")} />
              Reactivate
            </label>
          </div>

          {mode === "reactivate" && (
            <p className="rounded-lg bg-brand-50 px-3 py-2 text-theme-xs text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
              Only restores packages this same tool previously deactivated — a package Price Sync turned off for an
              unrelated reason (e.g. a price anomaly) is never swept up here.
            </p>
          )}

          <div className="flex gap-4 text-sm text-gray-600 dark:text-gray-400">
            <label className="flex items-center gap-1.5">
              <input type="radio" checked={scope === "all"} onChange={() => setScope("all")} />
              All packages
            </label>
            <label className="flex items-center gap-1.5">
              <input type="radio" checked={scope === "game"} onChange={() => setScope("game")} disabled={games.length === 0} />
              One game
            </label>
          </div>

          {scope === "game" && (
            <Select value={gameId} options={gameOptions} optionLabel="label" optionValue="value" onValueChange={(e) => setGameId(e.value as string)}>
              <SelectTrigger>
                <SelectValue />
                <SelectIndicator />
              </SelectTrigger>
              <SelectPortal>
                <SelectPositioner>
                  <SelectPopup>
                    <SelectList>
                      {gameOptions.map((option, index) => (
                        <SelectOption key={option.value} index={index}>
                          {option.label}
                        </SelectOption>
                      ))}
                    </SelectList>
                  </SelectPopup>
                </SelectPositioner>
              </SelectPortal>
            </Select>
          )}

          {mode === "deactivate" && (
            <div>
              <label className="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">
                Reason
              </label>
              <textarea
                className="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-theme-sm text-gray-800 shadow-theme-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 dark:border-gray-800 dark:bg-white/[0.03] dark:text-white/90"
                rows={2}
                placeholder="e.g. Gamevion delay reported by customers, 2026-08-27"
                value={reason}
                onChange={(e) => setReason(e.target.value)}
              />
            </div>
          )}
        </form>
      </DialogContent>
      <DialogFooter>
        <Button variant="outlined" onClick={onClose} disabled={submitting}>
          Close
        </Button>
        <Button
          type="submit"
          form="supplier-packages-form"
          severity={mode === "deactivate" ? "danger" : undefined}
          disabled={submitting}
        >
          {submitting ? "Working…" : mode === "deactivate" ? "Deactivate" : "Reactivate"}
        </Button>
      </DialogFooter>
    </>
  );
}

export default function SupplierPackagesModal({ isOpen, onClose, onSubmit, supplier, games }: SupplierPackagesModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => !e.value && onClose()}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup>
            {isOpen && supplier && (
              <SupplierPackagesFields onClose={onClose} onSubmit={onSubmit} supplier={supplier} games={games} />
            )}
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
