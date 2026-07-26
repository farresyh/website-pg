"use client";

/**
 * docs/prd.md §14/§15 backlog: "Hero Banner / Campaign management" —
 * admin CRUD for the storefront homepage's rotating hero banner.
 * Deliberately separate from a future Promotions feature (founder
 * decision, 2026-07-26) — see lib/hero-slides.ts's doc comment.
 */

import React, { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Table, TableHeader, TableBody, TableRow, TableCell } from "@/components/ui/table";
import Button from "@/components/ui/button/Button";
import { getClientSession } from "@/lib/session";
import type { SessionPayload } from "@/lib/auth";
import { ApiError } from "@/lib/api-client";
import {
  type HeroSlide,
  type SaveHeroSlideValues,
  listHeroSlides,
  createHeroSlide,
  updateHeroSlide,
  updateHeroSlideStatus,
  deleteHeroSlide,
} from "@/lib/hero-slides";
import HeroSlideModal from "@/components/hero-slides/HeroSlideModal";

export default function HeroSlidesPage() {
  const router = useRouter();
  const [session, setSession] = useState<SessionPayload | null>(null);

  const [slides, setSlides] = useState<HeroSlide[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  const [creating, setCreating] = useState(false);
  const [editingSlide, setEditingSlide] = useState<HeroSlide | null>(null);

  async function refresh(token: string) {
    try {
      setSlides(await listHeroSlides(token));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load hero slides.");
    }
  }

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    setSession(s);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!session) return;
    refresh(session.token);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session]);

  async function handleCreateSubmit(values: SaveHeroSlideValues) {
    if (!session) return;
    await createHeroSlide(session.token, values);
    setCreating(false);
    await refresh(session.token);
  }

  async function handleEditSubmit(values: SaveHeroSlideValues) {
    if (!session || !editingSlide) return;
    await updateHeroSlide(session.token, editingSlide.id, values);
    setEditingSlide(null);
    await refresh(session.token);
  }

  async function handleDelete() {
    if (!session || !editingSlide) return;
    await deleteHeroSlide(session.token, editingSlide.id);
    setEditingSlide(null);
    await refresh(session.token);
  }

  async function handleToggleStatus(slide: HeroSlide) {
    if (!session) return;
    setError(null);
    try {
      await updateHeroSlideStatus(session.token, slide.id, !slide.is_active);
      setSlides((prev) => prev?.map((s) => (s.id === slide.id ? { ...s, is_active: !s.is_active } : s)) ?? null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not update status.");
    }
  }

  const nextSortOrder = slides && slides.length > 0 ? Math.max(...slides.map((s) => s.sort_order)) + 1 : 0;

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Hero Banner</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            The storefront homepage&apos;s rotating hero banner — image, copy, and CTA links, editable without a code deploy.
          </p>
        </div>
        <Button onClick={() => setCreating(true)}>Add Slide</Button>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <Table>
            <TableHeader className="border-b border-gray-100 dark:border-gray-800">
              <TableRow>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Order</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Title</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Schedule</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</TableCell>
              </TableRow>
            </TableHeader>
            <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
              {slides?.map((slide) => (
                <TableRow key={slide.id}>
                  <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{slide.sort_order}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">
                    <span className="font-medium text-gray-800 dark:text-white/90">{slide.title}</span>
                    {slide.eyebrow && <p className="text-theme-xs text-gray-400">{slide.eyebrow}</p>}
                  </TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                    {slide.starts_at || slide.ends_at ? (
                      <>
                        {slide.starts_at ? new Date(slide.starts_at).toLocaleDateString() : "—"}
                        {" → "}
                        {slide.ends_at ? new Date(slide.ends_at).toLocaleDateString() : "—"}
                      </>
                    ) : (
                      "Always on"
                    )}
                  </TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">
                    <button
                      role="switch"
                      aria-checked={slide.is_active}
                      onClick={() => handleToggleStatus(slide)}
                      className={`h-6 w-11 rounded-full transition ${slide.is_active ? "bg-brand-500" : "bg-gray-300 dark:bg-gray-700"}`}
                    >
                      <span
                        className={`block h-5 w-5 translate-x-0.5 rounded-full bg-white transition ${slide.is_active ? "translate-x-[22px]" : ""}`}
                      />
                    </button>
                  </TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">
                    <Button size="sm" variant="outline" onClick={() => setEditingSlide(slide)}>Edit</Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
          {slides?.length === 0 && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">
              No hero slides yet — add one to populate the homepage banner.
            </p>
          )}
          {slides === null && !error && <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>}
        </div>
      </div>

      <HeroSlideModal
        isOpen={creating}
        onClose={() => setCreating(false)}
        onSubmit={handleCreateSubmit}
        slide={null}
        nextSortOrder={nextSortOrder}
      />
      <HeroSlideModal
        isOpen={editingSlide !== null}
        onClose={() => setEditingSlide(null)}
        onSubmit={handleEditSubmit}
        onDelete={handleDelete}
        slide={editingSlide}
        nextSortOrder={nextSortOrder}
      />
    </div>
  );
}
