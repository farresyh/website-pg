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
import { Input, inputVariants } from "@/components/ui/input";
import { SimpleSelect } from "@/components/ui/select";
import { Button } from "@/components/ui/button";
import { Tabs, TabsIndicator, TabsList, TabsPanel, TabsPanels, TabsTab } from "@/components/ui/tabs";
import { cn } from "@/lib/utils";
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

/** ADR-109 decision 2 — one line per note, split into a string array on save. */
function notesToText(notes: string[] | null | undefined): string {
  return (notes ?? []).join("\n");
}

function textToNotes(text: string): string[] | null {
  const notes = text
    .split("\n")
    .map((line) => line.trim())
    .filter((line) => line.length > 0);

  return notes.length > 0 ? notes : null;
}

const textareaClass = cn(inputVariants({ disabled: false, error: false }), "h-auto min-h-24 resize-y py-2.5 leading-relaxed");

/**
 * Renders as a child of <Modal>, which unmounts while closed — same
 * fresh-mount-per-open reasoning as the other *FormFields components.
 * GAME-3/GAME-4/GAME-5 + ADR-109's Content tab. Deliberately excludes
 * `supplier_mappings`/`validation_rules` (the latter is set at
 * /middleware/product-manager's category-link step, not here — it's a
 * supplier-integration decision, not a catalog-display one; see
 * LinkCategoryModal) and SEO fields — separately scoped, not part of
 * this pass.
 */
function EditGameFields({
  onClose,
  onSubmit,
  onDelete,
  game,
  validatorProfiles,
}: Omit<EditGameModalProps, "isOpen" | "game"> & { game: Game }) {
  const [activeTab, setActiveTab] = useState<"basic" | "content">("basic");
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
  const [description, setDescription] = useState(game.description ?? "");
  const [importantNotesText, setImportantNotesText] = useState(notesToText(game.important_notes));
  const [deliveryMode, setDeliveryMode] = useState<"instant" | "manual">(game.delivery_mode ?? "instant");
  const [deliverySubtext, setDeliverySubtext] = useState(game.delivery_subtext ?? "");
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
        description: description.trim() || null,
        important_notes: textToNotes(importantNotesText),
        delivery_mode: deliveryMode,
        delivery_subtext: deliverySubtext.trim() || null,
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

  if (confirmingDelete) {
    return (
      <div className="space-y-4">
        {error && (
          <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
            {error}
          </p>
        )}
        <p className="text-sm text-gray-700 dark:text-gray-300">
          Delete <span className="font-medium">{game.name}</span> and all {game.packages_count ?? 0} of its packages?
          This cannot be undone.
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
    );
  }

  return (
    <>
      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <form onSubmit={handleSubmit}>
        <Tabs value={activeTab} onValueChange={(e) => setActiveTab(e.value as "basic" | "content")}>
          <TabsList>
            <TabsTab value="basic">Basic Info</TabsTab>
            <TabsTab value="content">Content</TabsTab>
            <TabsIndicator />
          </TabsList>
          <TabsPanels>
            <TabsPanel value="basic">
              <div className="space-y-4">
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
              </div>
            </TabsPanel>

            <TabsPanel value="content">
              <div className="space-y-4">
                <div>
                  <Label htmlFor="game_description">Description (optional)</Label>
                  <textarea
                    id="game_description"
                    value={description}
                    onChange={(e) => setDescription(e.target.value)}
                    rows={3}
                    className={textareaClass}
                    placeholder="What this product covers and how top-up works."
                  />
                  <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                    Shown at the top of the &quot;How to Buy&quot; popup on the product page.
                  </p>
                </div>
                <div>
                  <Label htmlFor="game_important_notes">Important Notes (optional)</Label>
                  <textarea
                    id="game_important_notes"
                    value={importantNotesText}
                    onChange={(e) => setImportantNotesText(e.target.value)}
                    rows={4}
                    className={textareaClass}
                    placeholder={"One note per line, e.g.\nOnly available 10:30 AM to 12:00 AM (MYT).\nProcessing may take longer during peak hours."}
                  />
                  <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                    One line = one bullet in the popup. The popup only auto-opens on the product page when Description or
                    Important Notes has content.
                  </p>
                </div>

                <div className="border-t border-gray-100 pt-4 dark:border-gray-800">
                  <Label htmlFor="game_delivery_mode">Delivery Badge</Label>
                  <SimpleSelect
                    id="game_delivery_mode"
                    value={deliveryMode}
                    onChange={(value) => setDeliveryMode(value as "instant" | "manual")}
                    options={[
                      { value: "instant", label: "⚡ Instant Delivery" },
                      { value: "manual", label: "🕐 Manual Processing" },
                    ]}
                  />
                  <div className="mt-3">
                    <Label htmlFor="game_delivery_subtext">Delivery Subtext (optional)</Label>
                    <Input
                      id="game_delivery_subtext"
                      value={deliverySubtext}
                      onChange={(e) => setDeliverySubtext(e.target.value)}
                      placeholder="e.g. Average delivery: 1–3 minutes"
                    />
                    <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                      Shown next to the badge on the product page. Leave blank to use &quot;Average delivery: 1–3
                      minutes&quot;.
                    </p>
                  </div>
                </div>
              </div>
            </TabsPanel>
          </TabsPanels>
        </Tabs>

        <div className="mt-6 flex items-center justify-between border-t border-gray-100 pt-4 dark:border-gray-800">
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
    </>
  );
}

export default function EditGameModal({ isOpen, onClose, onSubmit, onDelete, game, validatorProfiles }: EditGameModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-2xl">
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
