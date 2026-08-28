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
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import { Button } from "@/components/ui/button";
import type { HeroSlide, SaveHeroSlideValues } from "@/lib/hero-slides";

interface HeroSlideModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (values: SaveHeroSlideValues) => Promise<void>;
  onDelete?: () => Promise<void>;
  /** null = create mode (no delete action, sort_order defaults to end of list). */
  slide: HeroSlide | null;
  nextSortOrder: number;
}

const textareaClasses =
  "w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800";

/** RM string (what admin types) <-> integer sen (what the backend stores, ORD-9 convention). */
function rmToSen(rm: string): number | null {
  if (!rm.trim()) return null;
  const parsed = Math.round(parseFloat(rm) * 100);
  return Number.isFinite(parsed) && parsed >= 0 ? parsed : null;
}
function senToRm(sen: number | null): string {
  return sen != null ? (sen / 100).toFixed(2) : "";
}

/** `starts_at`/`ends_at` <-> <input type="datetime-local"> (no timezone suffix). */
function isoToLocalInput(iso: string | null): string {
  if (!iso) return "";
  return iso.slice(0, 16);
}
function localInputToIso(value: string): string | null {
  return value ? new Date(value).toISOString() : null;
}

function HeroSlideFields({
  onClose,
  onSubmit,
  onDelete,
  slide,
  nextSortOrder,
}: Omit<HeroSlideModalProps, "isOpen">) {
  const [eyebrow, setEyebrow] = useState(slide?.eyebrow ?? "");
  const [title, setTitle] = useState(slide?.title ?? "");
  const [description, setDescription] = useState(slide?.description ?? "");
  const [imageUrl, setImageUrl] = useState(slide?.image_url ?? "");
  const [priceFromRm, setPriceFromRm] = useState(senToRm(slide?.price_from_sen ?? null));
  const [primaryCtaLabel, setPrimaryCtaLabel] = useState(slide?.primary_cta_label ?? "");
  const [primaryCtaHref, setPrimaryCtaHref] = useState(slide?.primary_cta_href ?? "");
  const [secondaryCtaLabel, setSecondaryCtaLabel] = useState(slide?.secondary_cta_label ?? "");
  const [secondaryCtaHref, setSecondaryCtaHref] = useState(slide?.secondary_cta_href ?? "");
  const [isActive, setIsActive] = useState(slide?.is_active ?? true);
  const [sortOrder, setSortOrder] = useState(String(slide?.sort_order ?? nextSortOrder));
  const [startsAt, setStartsAt] = useState(isoToLocalInput(slide?.starts_at ?? null));
  const [endsAt, setEndsAt] = useState(isoToLocalInput(slide?.ends_at ?? null));

  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [confirmingDelete, setConfirmingDelete] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await onSubmit({
        eyebrow: eyebrow || null,
        title,
        description: description || null,
        image_url: imageUrl || null,
        price_from_sen: rmToSen(priceFromRm),
        primary_cta_label: primaryCtaLabel,
        primary_cta_href: primaryCtaHref,
        secondary_cta_label: secondaryCtaLabel || null,
        secondary_cta_href: secondaryCtaHref || null,
        is_active: isActive,
        sort_order: parseInt(sortOrder, 10) || 0,
        starts_at: localInputToIso(startsAt),
        ends_at: localInputToIso(endsAt),
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleDelete() {
    if (!onDelete) return;
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
            Delete <span className="font-medium">{slide?.title}</span>? This cannot be undone.
          </p>
          <div className="flex items-center justify-end gap-3">
            <Button type="button" variant="outlined" onClick={() => setConfirmingDelete(false)} disabled={submitting}>
              Cancel
            </Button>
            <Button type="button" severity="danger" onClick={handleDelete} disabled={submitting}>
              {submitting ? "Deleting…" : "Delete Slide"}
            </Button>
          </div>
        </div>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <Label htmlFor="slide_eyebrow">Eyebrow (optional)</Label>
            <Input id="slide_eyebrow" value={eyebrow} onChange={(e) => setEyebrow(e.target.value)} placeholder="e.g. Limited Offer" />
          </div>
          <div>
            <Label htmlFor="slide_title">Title</Label>
            <Input id="slide_title" value={title} onChange={(e) => setTitle(e.target.value)} required />
          </div>
          <div>
            <Label htmlFor="slide_description">Description (optional)</Label>
            <textarea
              id="slide_description"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              rows={3}
              className={textareaClasses}
            />
          </div>
          <div>
            <Label htmlFor="slide_image_url">Image URL (optional)</Label>
            <Input id="slide_image_url" value={imageUrl} onChange={(e) => setImageUrl(e.target.value)} placeholder="Paste a hosted image URL" />
            <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
              Recommended 1600×800px (2:1) — matches the hero slider&apos;s display ratio at desktop width.
              No banner falls back to the storefront&apos;s default gradient treatment.
            </p>
          </div>
          <div>
            <Label htmlFor="slide_price_from">&quot;Starting at RM&quot; (optional display copy)</Label>
            <input
              id="slide_price_from"
              type="number"
              step="0.01"
              min="0"
              value={priceFromRm}
              onChange={(e) => setPriceFromRm(e.target.value)}
              placeholder="e.g. 4.00"
              className="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800"
            />
            <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
              Marketing copy only — not linked to any real package price.
            </p>
          </div>

          <div className="grid grid-cols-2 gap-4 border-t border-gray-100 pt-4 dark:border-gray-800">
            <div>
              <Label htmlFor="slide_primary_cta_label">Primary button label</Label>
              <Input id="slide_primary_cta_label" value={primaryCtaLabel} onChange={(e) => setPrimaryCtaLabel(e.target.value)} required />
            </div>
            <div>
              <Label htmlFor="slide_primary_cta_href">Primary button link</Label>
              <Input id="slide_primary_cta_href" value={primaryCtaHref} onChange={(e) => setPrimaryCtaHref(e.target.value)} required placeholder="/order/free-fire" />
            </div>
            <div>
              <Label htmlFor="slide_secondary_cta_label">Secondary button label (optional)</Label>
              <Input id="slide_secondary_cta_label" value={secondaryCtaLabel} onChange={(e) => setSecondaryCtaLabel(e.target.value)} />
            </div>
            <div>
              <Label htmlFor="slide_secondary_cta_href">Secondary button link (optional)</Label>
              <Input id="slide_secondary_cta_href" value={secondaryCtaHref} onChange={(e) => setSecondaryCtaHref(e.target.value)} />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-4 border-t border-gray-100 pt-4 dark:border-gray-800">
            <div>
              <Label htmlFor="slide_sort_order">Sort order</Label>
              <input
                id="slide_sort_order"
                type="number"
                min="0"
                value={sortOrder}
                onChange={(e) => setSortOrder(e.target.value)}
                className="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800"
              />
            </div>
            <label className="flex items-center gap-2 self-end pb-2.5 text-sm text-gray-700 dark:text-gray-300">
              <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
              Active
            </label>
            <div>
              <Label htmlFor="slide_starts_at">Starts at (optional)</Label>
              <input
                id="slide_starts_at"
                type="datetime-local"
                value={startsAt}
                onChange={(e) => setStartsAt(e.target.value)}
                className="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800"
              />
            </div>
            <div>
              <Label htmlFor="slide_ends_at">Ends at (optional)</Label>
              <input
                id="slide_ends_at"
                type="datetime-local"
                value={endsAt}
                onChange={(e) => setEndsAt(e.target.value)}
                className="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800"
              />
            </div>
          </div>

          <div className="flex items-center justify-between pt-2">
            {slide && onDelete ? (
              <Button type="button" severity="danger" onClick={() => setConfirmingDelete(true)} disabled={submitting}>
                Delete Slide
              </Button>
            ) : (
              <span />
            )}
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

export default function HeroSlideModal({ isOpen, onClose, onSubmit, onDelete, slide, nextSortOrder }: HeroSlideModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-lg">
            <DialogHeader>
              <DialogTitle>{slide ? "Edit Hero Slide" : "Add Hero Slide"}</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && (
                <HeroSlideFields onClose={onClose} onSubmit={onSubmit} onDelete={onDelete} slide={slide} nextSortOrder={nextSortOrder} />
              )}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
