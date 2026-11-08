"use client";

import React, { useState } from "react";
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
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { SimpleSelect } from "@/components/ui/select";
import { Button } from "@/components/ui/button";
import type { LinkCategoryValues } from "@/lib/supplier-products";
import { EXTRA_FIELD_OPTIONS, type Game } from "@/lib/games";

interface LinkCategoryModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (values: LinkCategoryValues) => Promise<void>;
  supplierId: number | null;
  groupLabel: string | null;
  itemCount: number;
  games: Game[];
  /** The group's current game, when this corrects a link. */
  currentGameId?: number;
}

/**
 * Renders as a child of <Modal>, which unmounts while closed — same
 * fresh-mount-per-open reasoning as the other *FormFields components.
 * This decision is made ONCE per (supplier, group_label) group
 * (~15-40 items), not per item — see docs/prd.md §14's Price Sync
 * Stage 2 note and ADR-067 decision 6.
 */
function LinkCategoryFields({
  onClose,
  onSubmit,
  supplierId,
  groupLabel,
  itemCount,
  games,
  currentGameId,
}: Omit<LinkCategoryModalProps, "isOpen" | "supplierId" | "groupLabel"> & { supplierId: number; groupLabel: string }) {
  const initialGame = games.find((g) => g.id === currentGameId) ?? games[0];
  const [gameMode, setGameMode] = useState<"existing" | "new">(games.length > 0 ? "existing" : "new");
  const [gameId, setGameId] = useState(initialGame ? String(initialGame.id) : "");
  const [newGameName, setNewGameName] = useState(groupLabel);
  const [newGameCategory, setNewGameCategory] = useState("");
  // A new game only. An existing game's checkout rule applies to every
  // supplier selling it, so it changes in the Checkout Input tab, never here.
  const [extraField, setExtraField] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const selectedGame = games.find((g) => String(g.id) === gameId);
  const currentInput = EXTRA_FIELD_OPTIONS.find((o) => o.value === (selectedGame?.validation_rules?.extra_field ?? ""));

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);

    if (gameMode === "existing" && !gameId) {
      setError("Select an existing game.");
      return;
    }
    if (gameMode === "new" && !newGameName.trim()) {
      setError("Enter a name for the new game.");
      return;
    }

    setSubmitting(true);
    try {
      await onSubmit({
        supplier_id: supplierId,
        group_label: groupLabel,
        ...(gameMode === "existing"
          ? { game_id: Number(gameId) }
          : {
              new_game: { name: newGameName, category: newGameCategory || undefined },
              validation_rules: { extra_field: extraField === "" ? null : (extraField as "server_id" | "zone_id") },
            }),
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      <p className="mb-5 text-sm text-ink-muted">
        &quot;{groupLabel}&quot; — {itemCount} raw item{itemCount === 1 ? "" : "s"}. Every item in this group
        will use the game you pick here — you won&apos;t be asked again per item.
      </p>

      {error && (
        <p className="mb-4 rounded-lg bg-danger-surface px-3 py-2 text-sm text-danger-ink">
          {error}
        </p>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="mb-2 flex gap-4 text-sm text-ink-muted">
          <label className="flex items-center gap-1.5">
            <input
              type="radio"
              checked={gameMode === "existing"}
              onChange={() => setGameMode("existing")}
              disabled={games.length === 0}
            />
            Existing game
          </label>
          <label className="flex items-center gap-1.5">
            <input type="radio" checked={gameMode === "new"} onChange={() => setGameMode("new")} />
            New game
          </label>
        </div>

        {gameMode === "existing" ? (
          <SimpleSelect
            value={gameId}
            onChange={setGameId}
            options={games.map((g) => ({ value: String(g.id), label: g.name }))}
          />
        ) : (
          <div className="space-y-3">
            <div>
              <Label htmlFor="new_game_name">Game Name</Label>
              <Input id="new_game_name" value={newGameName} onChange={(e) => setNewGameName(e.target.value)} required />
            </div>
            <div>
              <Label htmlFor="new_game_category">Category (optional)</Label>
              <Input
                id="new_game_category"
                placeholder="e.g. MOBA, Battle Royale"
                value={newGameCategory}
                onChange={(e) => setNewGameCategory(e.target.value)}
              />
            </div>
          </div>
        )}

        {gameMode === "new" ? (
          <div>
            <Label htmlFor="extra_field">Checkout input needed</Label>
            <SimpleSelect id="extra_field" value={extraField} onChange={setExtraField} options={EXTRA_FIELD_OPTIONS} />
            <p className="mt-1 text-theme-xs text-ink-subtle">
              What the supplier needs beyond Player ID for orders in this category.
            </p>
          </div>
        ) : (
          <p className="text-theme-xs text-ink-subtle">
            Checkout input: {currentInput?.label}. Linking keeps it. To change it for the whole game, use this group&apos;s
            Checkout Input tab.
          </p>
        )}

        <div className="flex items-center justify-end gap-3 pt-2">
          <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" disabled={submitting}>
            {submitting ? "Linking…" : "Link Category"}
          </Button>
        </div>
      </form>
    </>
  );
}

export default function LinkCategoryModal({ isOpen, onClose, onSubmit, supplierId, groupLabel, itemCount, games, currentGameId }: LinkCategoryModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-md">
            <DialogHeader>
              <DialogTitle>Link Category to a Game</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && supplierId !== null && groupLabel !== null && (
                <LinkCategoryFields
                  onClose={onClose}
                  onSubmit={onSubmit}
                  supplierId={supplierId}
                  groupLabel={groupLabel}
                  itemCount={itemCount}
                  games={games}
                  currentGameId={currentGameId}
                />
              )}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
