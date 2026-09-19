"use client";

import { useEffect, useState } from "react";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import {
  getStorefrontHeroSlides,
  createStorefrontHeroSlide,
  updateStorefrontHeroSlide,
  setStorefrontHeroSlideStatus,
  deleteStorefrontHeroSlide,
  type StorefrontHeroSlide,
  type StorefrontHeroSlidesResponse,
  type HeroSlideInput,
} from "@/lib/portal";
import { Panel, ErrorNote, EmptyRow } from "@/components/ui";
import { Field, inputClass, SaveButton, Toggle, InactiveNotice, TabLoading } from "./shared";

type Draft = HeroSlideInput & { id: number | null };

const BLANK: Draft = {
  id: null,
  eyebrow: "",
  title: "",
  description: "",
  primary_cta_label: "",
  primary_cta_href: "",
  secondary_cta_label: "",
  secondary_cta_href: "",
  is_active: true,
  sort_order: 0,
};

function toDraft(slide: StorefrontHeroSlide): Draft {
  return {
    id: slide.id,
    eyebrow: slide.eyebrow ?? "",
    title: slide.title ?? "",
    description: slide.description ?? "",
    price_from_sen: slide.price_from_sen,
    primary_cta_label: slide.primary_cta_label ?? "",
    primary_cta_href: slide.primary_cta_href ?? "",
    secondary_cta_label: slide.secondary_cta_label ?? "",
    secondary_cta_href: slide.secondary_cta_href ?? "",
    is_active: slide.is_active,
    sort_order: slide.sort_order,
  };
}

export default function HeroTab() {
  const [data, setData] = useState<StorefrontHeroSlidesResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<Draft | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  function refresh() {
    const session = getClientSession();
    if (!session) return;
    return getStorefrontHeroSlides(session.token)
      .then(setData)
      .catch((err: unknown) =>
        setError(err instanceof ApiError ? err.message : "Could not load your hero slides."),
      );
  }

  useEffect(() => {
    refresh();
  }, []);

  const slides = data?.slides ?? [];
  const writable = data?.writable ?? false;
  const atCap = data !== null && slides.length >= data.max_slides;

  async function toggleStatus(slide: StorefrontHeroSlide) {
    const session = getClientSession();
    if (!session) return;
    setError(null);
    setBusyId(slide.id);
    try {
      await setStorefrontHeroSlideStatus(session.token, slide.id, !slide.is_active);
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not update that slide.");
    } finally {
      setBusyId(null);
    }
  }

  async function remove(slide: StorefrontHeroSlide) {
    const session = getClientSession();
    if (!session) return;
    setError(null);
    setBusyId(slide.id);
    try {
      await deleteStorefrontHeroSlide(session.token, slide.id);
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not delete that slide.");
    } finally {
      setBusyId(null);
    }
  }

  return (
    <div className="space-y-6">
      {error && <ErrorNote message={error} />}
      {data && !writable && <InactiveNotice />}
      {!data && !error && <TabLoading />}

      {data && (
        <>
          <p className="text-theme-sm text-gray-500 dark:text-gray-400">
            {slides.length} of {data.max_slides} slides. When no slide is active, your storefront shows the
            default PekanGame hero.
          </p>

          <div className="space-y-3">
            {slides.map((slide) => (
              <Panel key={slide.id}>
                <div className="flex flex-wrap items-center gap-4 p-4">
                  <div className="h-14 w-24 shrink-0 overflow-hidden rounded-md border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900">
                    {slide.image_url && (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img src={slide.image_url} alt="" className="h-full w-full object-cover" />
                    )}
                  </div>
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-medium text-gray-800 dark:text-white/90">
                      {slide.title || <span className="italic text-gray-400">Asset-only banner</span>}
                    </p>
                    <p className="text-theme-xs text-gray-500 dark:text-gray-400">Order {slide.sort_order}</p>
                  </div>
                  <Toggle
                    checked={slide.is_active}
                    disabled={!writable || busyId === slide.id}
                    onChange={() => toggleStatus(slide)}
                  />
                  {writable && (
                    <div className="flex gap-2">
                      <button
                        type="button"
                        onClick={() => setEditing(toDraft(slide))}
                        className="rounded-lg border border-gray-200 px-3 py-1.5 text-theme-xs text-gray-600 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
                      >
                        Edit
                      </button>
                      <button
                        type="button"
                        disabled={busyId === slide.id}
                        onClick={() => remove(slide)}
                        className="rounded-lg border border-error-200 px-3 py-1.5 text-theme-xs text-error-600 hover:bg-error-50 disabled:opacity-50 dark:border-error-500/30 dark:text-error-400 dark:hover:bg-error-500/10"
                      >
                        Delete
                      </button>
                    </div>
                  )}
                </div>
              </Panel>
            ))}
            {slides.length === 0 && <EmptyRow>No slides yet. Add one to customise your storefront hero.</EmptyRow>}
          </div>

          {writable && !editing && (
            <button
              type="button"
              disabled={atCap}
              onClick={() => setEditing({ ...BLANK, sort_order: slides.length })}
              className="rounded-lg bg-brand-500 px-4 py-2 text-theme-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
            >
              {atCap ? `Maximum ${data.max_slides} slides` : "Add a slide"}
            </button>
          )}

          {editing && (
            <SlideForm
              draft={editing}
              onCancel={() => setEditing(null)}
              onSaved={async () => {
                setEditing(null);
                await refresh();
              }}
              onError={setError}
            />
          )}
        </>
      )}
    </div>
  );
}

function SlideForm({
  draft,
  onCancel,
  onSaved,
  onError,
}: {
  draft: Draft;
  onCancel: () => void;
  onSaved: () => void;
  onError: (message: string) => void;
}) {
  const [form, setForm] = useState<Draft>(draft);
  const [image, setImage] = useState<File | null>(null);
  const [saving, setSaving] = useState(false);
  const isNew = form.id === null;

  function set<K extends keyof Draft>(key: K, value: Draft[K]) {
    setForm((f) => ({ ...f, [key]: value }));
  }

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    const session = getClientSession();
    if (!session) return;

    if (isNew && !image) {
      onError("Pick an image for the new slide.");
      return;
    }

    const { id, ...input } = form;
    setSaving(true);
    try {
      if (isNew) {
        await createStorefrontHeroSlide(session.token, input, image as File);
      } else {
        await updateStorefrontHeroSlide(session.token, id as number, input, image);
      }
      onSaved();
    } catch (err) {
      onError(err instanceof ApiError ? err.message : "Could not save the slide.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Panel title={isNew ? "New slide" : "Edit slide"}>
      <form onSubmit={submit} className="space-y-4 p-5">
        <Field label="Image" hint="PNG, JPG or WebP, up to 2 MB. Landscape works best.">
          <input
            type="file"
            accept="image/jpeg,image/png,image/webp"
            onChange={(e) => setImage(e.target.files?.[0] ?? null)}
            className="block text-theme-xs text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-theme-xs file:font-medium file:text-brand-600 dark:text-gray-400 dark:file:bg-brand-500/15 dark:file:text-brand-400"
          />
        </Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Eyebrow">
            <input value={form.eyebrow} onChange={(e) => set("eyebrow", e.target.value)} className={inputClass} />
          </Field>
          <Field label="Sort order">
            <input
              type="number"
              min={0}
              max={99}
              value={form.sort_order}
              onChange={(e) => set("sort_order", Number(e.target.value))}
              className={inputClass}
            />
          </Field>
        </div>
        <Field label="Title" hint="Leave blank for an asset-only banner (all copy baked into the image).">
          <input value={form.title} onChange={(e) => set("title", e.target.value)} className={inputClass} />
        </Field>
        <Field label="Description">
          <textarea
            value={form.description}
            onChange={(e) => set("description", e.target.value)}
            rows={2}
            className={inputClass}
          />
        </Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Button label" hint="Optional, but fill both label and link or neither.">
            <input
              value={form.primary_cta_label}
              onChange={(e) => set("primary_cta_label", e.target.value)}
              className={inputClass}
            />
          </Field>
          <Field label="Button link">
            <input
              value={form.primary_cta_href}
              onChange={(e) => set("primary_cta_href", e.target.value)}
              placeholder="/order/mobile-legends"
              className={inputClass}
            />
          </Field>
        </div>
        <label className="flex items-center gap-3">
          <Toggle checked={form.is_active} onChange={(v) => set("is_active", v)} />
          <span className="text-theme-sm text-gray-700 dark:text-gray-300">Active</span>
        </label>
        <div className="flex items-center gap-3">
          <SaveButton saving={saving} saved={false} label={isNew ? "Add slide" : "Save slide"} />
          <button type="button" onClick={onCancel} className="text-theme-sm text-gray-500 hover:underline dark:text-gray-400">
            Cancel
          </button>
        </div>
      </form>
    </Panel>
  );
}
