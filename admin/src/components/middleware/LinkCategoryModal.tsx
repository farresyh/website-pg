"use client";

import React, { useState } from "react";
import { Modal } from "@/components/ui/modal";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import Select from "@/components/form/Select";
import Button from "@/components/ui/button/Button";
import type { LinkCategoryValues } from "@/lib/supplier-products";
import type { Game } from "@/lib/games";

interface LinkCategoryModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (values: LinkCategoryValues) => Promise<void>;
  categoryRaw: string | null;
  itemCount: number;
  games: Game[];
}

/**
 * Renders as a child of <Modal>, which unmounts while closed — same
 * fresh-mount-per-open reasoning as the other *FormFields components.
 * This decision is made ONCE per category (~15-40 items), not per
 * item — see docs/prd.md §14's Price Sync Stage 2 note.
 */
function LinkCategoryFields({
  onClose,
  onSubmit,
  categoryRaw,
  itemCount,
  games,
}: Omit<LinkCategoryModalProps, "isOpen"> & { categoryRaw: string }) {
  const [gameMode, setGameMode] = useState<"existing" | "new">(games.length > 0 ? "existing" : "new");
  const [gameId, setGameId] = useState(games[0] ? String(games[0].id) : "");
  const [newGameName, setNewGameName] = useState(categoryRaw);
  const [newGameCategory, setNewGameCategory] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

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
        category_raw: categoryRaw,
        ...(gameMode === "existing"
          ? { game_id: Number(gameId) }
          : { new_game: { name: newGameName, category: newGameCategory || undefined } }),
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="max-w-md p-6">
      <h3 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">Link Category to a Game</h3>
      <p className="mb-5 text-sm text-gray-500 dark:text-gray-400">
        &quot;{categoryRaw}&quot; — {itemCount} raw item{itemCount === 1 ? "" : "s"}. Every item in this category
        will use the game you pick here — you won&apos;t be asked again per item.
      </p>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="mb-2 flex gap-4 text-sm text-gray-600 dark:text-gray-400">
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
          <Select
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

        <div className="flex items-center justify-end gap-3 pt-2">
          <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" disabled={submitting}>
            {submitting ? "Linking…" : "Link Category"}
          </Button>
        </div>
      </form>
    </div>
  );
}

export default function LinkCategoryModal({ isOpen, onClose, onSubmit, categoryRaw, itemCount, games }: LinkCategoryModalProps) {
  return (
    <Modal isOpen={isOpen} onClose={onClose} className="max-w-md">
      {isOpen && categoryRaw && (
        <LinkCategoryFields
          onClose={onClose}
          onSubmit={onSubmit}
          categoryRaw={categoryRaw}
          itemCount={itemCount}
          games={games}
        />
      )}
    </Modal>
  );
}
