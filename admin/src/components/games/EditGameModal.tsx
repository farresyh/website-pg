"use client";

import React, { useState } from "react";
import { Modal } from "@/components/ui/modal";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import Button from "@/components/ui/button/Button";
import type { Game, UpdateGameValues } from "@/lib/games";

interface EditGameModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (values: UpdateGameValues) => Promise<void>;
  onDelete: () => Promise<void>;
  game: Game | null;
}

/**
 * Renders as a child of <Modal>, which unmounts while closed — same
 * fresh-mount-per-open reasoning as the other *FormFields components.
 * GAME-3/GAME-4/GAME-5. Deliberately excludes `supplier_mappings`/
 * `validation_rules` (the latter is set at /middleware/product-manager's
 * category-link step, not here — it's a supplier-integration decision,
 * not a catalog-display one; see LinkCategoryModal) and SEO fields —
 * separately scoped, not part of this pass.
 */
function EditGameFields({
  onClose,
  onSubmit,
  onDelete,
  game,
}: Omit<EditGameModalProps, "isOpen" | "game"> & { game: Game }) {
  const [name, setName] = useState(game.name);
  const [slug, setSlug] = useState(game.slug);
  const [category, setCategory] = useState(game.category ?? "");
  const [imageUrl, setImageUrl] = useState(game.image_url ?? "");
  const [isActive, setIsActive] = useState(game.is_active ?? true);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [confirmingDelete, setConfirmingDelete] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await onSubmit({
        name,
        slug,
        category: category || null,
        image_url: imageUrl || null,
        is_active: isActive,
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleDelete() {
    setError(null);
    setSubmitting(true);
    try {
      await onDelete();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
      setSubmitting(false);
    }
  }

  return (
    <div className="max-w-md p-6">
      <h3 className="mb-5 text-lg font-semibold text-gray-800 dark:text-white/90">Edit Game</h3>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      {confirmingDelete ? (
        <div className="space-y-4">
          <p className="text-sm text-gray-700 dark:text-gray-300">
            Delete <span className="font-medium">{game.name}</span> and all {game.packages_count ?? 0} of its
            packages? This cannot be undone.
          </p>
          <div className="flex items-center justify-end gap-3">
            <Button type="button" variant="outline" onClick={() => setConfirmingDelete(false)} disabled={submitting}>
              Cancel
            </Button>
            <Button type="button" variant="danger" onClick={handleDelete} disabled={submitting}>
              {submitting ? "Deleting…" : "Delete Game"}
            </Button>
          </div>
        </div>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <Label htmlFor="game_name">Name</Label>
            <Input id="game_name" value={name} onChange={(e) => setName(e.target.value)} required />
          </div>
          <div>
            <Label htmlFor="game_slug">Slug</Label>
            <Input id="game_slug" value={slug} onChange={(e) => setSlug(e.target.value)} required />
          </div>
          <div>
            <Label htmlFor="game_category">Category (optional)</Label>
            <Input id="game_category" value={category} onChange={(e) => setCategory(e.target.value)} />
          </div>
          <div>
            <Label htmlFor="game_image_url">Image URL (optional)</Label>
            <Input id="game_image_url" value={imageUrl} onChange={(e) => setImageUrl(e.target.value)} />
          </div>
          <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
            <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
            Active (visible to customers once packages exist)
          </label>

          <div className="flex items-center justify-between pt-2">
            <Button type="button" variant="danger" onClick={() => setConfirmingDelete(true)} disabled={submitting}>
              Delete Game
            </Button>
            <div className="flex gap-3">
              <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
                Cancel
              </Button>
              <Button type="submit" disabled={submitting}>
                {submitting ? "Saving…" : "Save"}
              </Button>
            </div>
          </div>
        </form>
      )}
    </div>
  );
}

export default function EditGameModal({ isOpen, onClose, onSubmit, onDelete, game }: EditGameModalProps) {
  return (
    <Modal isOpen={isOpen} onClose={onClose} className="max-w-md">
      {isOpen && game && <EditGameFields onClose={onClose} onSubmit={onSubmit} onDelete={onDelete} game={game} />}
    </Modal>
  );
}
