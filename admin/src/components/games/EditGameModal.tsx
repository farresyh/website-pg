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
import { CloseIcon } from "@/icons";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { SimpleSelect } from "@/components/ui/select";
import { Button } from "@/components/ui/button";
import type { Game, UpdateGameValues } from "@/lib/games";
import type { PlayerValidatorProfile } from "@/lib/player-validators";

interface EditGameModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (values: UpdateGameValues) => Promise<void>;
  onDelete: () => Promise<void>;
  game: Game | null;
  /** MUI-5 follow-up — profiles are created/managed at /middleware/validators; this screen only assigns one to a Game and toggles it on/off, per the founder's own catalog-vs-supplier-integration split. */
  validatorProfiles: PlayerValidatorProfile[];
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
  validatorProfiles,
}: Omit<EditGameModalProps, "isOpen" | "game"> & { game: Game }) {
  const [name, setName] = useState(game.name);
  const [slug, setSlug] = useState(game.slug);
  const [category, setCategory] = useState(game.category ?? "");
  const [resellerCode, setResellerCode] = useState(game.reseller_code ?? "");
  const [imageUrl, setImageUrl] = useState(game.image_url ?? "");
  const [isActive, setIsActive] = useState(game.is_active ?? true);
  const [validatorProfileId, setValidatorProfileId] = useState(
    game.player_validator_profile_id != null ? String(game.player_validator_profile_id) : "",
  );
  const [validatorEnabled, setValidatorEnabled] = useState(game.player_validator_enabled ?? false);
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
        reseller_code: resellerCode || null,
        image_url: imageUrl || null,
        is_active: isActive,
        player_validator_profile_id: validatorProfileId ? Number(validatorProfileId) : null,
        player_validator_enabled: validatorProfileId ? validatorEnabled : false,
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
    <>
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
            <Button type="button" variant="outlined" onClick={() => setConfirmingDelete(false)} disabled={submitting}>
              Cancel
            </Button>
            <Button type="button" severity="danger" onClick={handleDelete} disabled={submitting}>
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
            <Label htmlFor="game_reseller_code">Reseller Code (optional)</Label>
            <Input
              id="game_reseller_code"
              value={resellerCode}
              onChange={(e) => setResellerCode(e.target.value.toUpperCase())}
              placeholder="e.g. MLMY"
              maxLength={10}
            />
            <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
              Uppercase letters only. The game segment of a Reseller API/Bot product code (e.g. <code>MLMY-14</code>). Leave
              blank to keep this game out of the Reseller catalog.
            </p>
          </div>
          <div>
            <Label htmlFor="game_image_url">Image URL (optional)</Label>
            <Input id="game_image_url" value={imageUrl} onChange={(e) => setImageUrl(e.target.value)} />
            <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
              Recommended 800×500px (16:10) — matches the product card&apos;s image ratio.
            </p>
          </div>
          <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
            <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
            Active (visible to customers once packages exist)
          </label>

          <div className="border-t border-gray-100 pt-4 dark:border-gray-800">
            <Label htmlFor="game_validator_profile">Player ID Validator (optional)</Label>
            <SimpleSelect
              id="game_validator_profile"
              value={validatorProfileId}
              onChange={(value) => {
                setValidatorProfileId(value);
                if (!value) setValidatorEnabled(false);
              }}
              options={[
                { value: "", label: "None — no validation for this game" },
                ...validatorProfiles.map((p) => ({ value: String(p.id), label: p.name })),
              ]}
            />
            <label
              className={`mt-3 flex items-center gap-2 text-sm ${
                validatorProfileId ? "text-gray-700 dark:text-gray-300" : "text-gray-400 dark:text-gray-600"
              }`}
            >
              <input
                type="checkbox"
                checked={validatorEnabled}
                disabled={!validatorProfileId}
                onChange={(e) => setValidatorEnabled(e.target.checked)}
              />
              Requires Player Validation (shows the storefront &quot;Validate Player ID&quot; button)
            </label>
          </div>

          <div className="flex items-center justify-between pt-2">
            <Button type="button" severity="danger" onClick={() => setConfirmingDelete(true)} disabled={submitting}>
              Delete Game
            </Button>
            <div className="flex gap-3">
              <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>
                Cancel
              </Button>
              <Button type="submit" disabled={submitting}>
                {submitting ? "Saving…" : "Save"}
              </Button>
            </div>
          </div>
        </form>
      )}
    </>
  );
}

export default function EditGameModal({ isOpen, onClose, onSubmit, onDelete, game, validatorProfiles }: EditGameModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-md">
            <DialogHeader>
              <DialogTitle>Edit Game</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && game && (
                <EditGameFields
                  onClose={onClose}
                  onSubmit={onSubmit}
                  onDelete={onDelete}
                  game={game}
                  validatorProfiles={validatorProfiles}
                />
              )}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
