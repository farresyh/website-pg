"use client";

/**
 * IMG-1/IMG-2 (docs/prd.md §14/§15 backlog) — Image Gallery: upload
 * (drag-drop or file picker), grid view, search, preview, copy URL,
 * delete. Feeds `Game.image_url` / `HeroSlide.image_url`, both
 * currently plain-paste text inputs — an admin uploads here, then
 * copies the resulting URL into either modal (no direct link between
 * the two; see GalleryImageController's doc comment for why).
 */

import React, { useCallback, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import {
  type GalleryImage,
  type GalleryImagePage,
  listGalleryImages,
  uploadGalleryImage,
  deleteGalleryImage,
  getGalleryImageReferences,
} from "@/lib/gallery";

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export default function GalleryPage() {
  const router = useRouter();
  const session = useClientSession();

  const [page, setPage] = useState<GalleryImagePage | null>(null);
  const [pageNumber, setPageNumber] = useState(1);
  const [search, setSearch] = useState("");
  const [error, setError] = useState<string | null>(null);

  const [isDragging, setIsDragging] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [copiedId, setCopiedId] = useState<number | null>(null);
  const [deletingImage, setDeletingImage] = useState<GalleryImage | null>(null);
  const [deletingReferences, setDeletingReferences] = useState<string[] | null>(null);
  const [loadingReferences, setLoadingReferences] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const refresh = useCallback(
    (token: string) => {
      listGalleryImages(token, { search: search || undefined, page: pageNumber })
        .then(setPage)
        .catch((err) => setError(err instanceof ApiError ? err.message : "Could not load the gallery."));
    },
    [search, pageNumber],
  );

  useEffect(() => {
    if (!session) return;
    refresh(session.token);
  }, [session, refresh]);

  async function handleFiles(files: FileList | null) {
    if (!session || !files || files.length === 0) return;

    setUploading(true);
    setError(null);
    try {
      // One request per file — matches the backend's single-file-per-
      // request contract (UploadGalleryImageRequest); a multi-file
      // drop just fires several uploads, not one multi-file endpoint.
      for (const file of Array.from(files)) {
        await uploadGalleryImage(session.token, file);
      }
      setPageNumber(1);
      refresh(session.token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Upload failed.");
    } finally {
      setUploading(false);
      if (fileInputRef.current) fileInputRef.current.value = "";
    }
  }

  function handleDeleteClick(image: GalleryImage) {
    if (!session) return;
    setDeletingImage(image);
    setDeletingReferences(null);
    setLoadingReferences(true);
    // ADR-095 decision 5 — pre-flight check, fetched right when the
    // dialog opens rather than embedded in every grid card (24+ per
    // page); a plain fetch failure here degrades to "unknown," never
    // blocks the delete action itself.
    getGalleryImageReferences(session.token, image.id)
      .then((res) => setDeletingReferences(res.references))
      .catch(() => setDeletingReferences([]))
      .finally(() => setLoadingReferences(false));
  }

  async function handleDeleteConfirmed() {
    if (!session || !deletingImage) return;
    try {
      await deleteGalleryImage(session.token, deletingImage.id);
      setDeletingImage(null);
      setDeletingReferences(null);
      refresh(session.token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not delete this image.");
    }
  }

  async function handleCopyUrl(image: GalleryImage) {
    await navigator.clipboard.writeText(image.url);
    setCopiedId(image.id);
    setTimeout(() => setCopiedId((current) => (current === image.id ? null : current)), 1500);
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Image Gallery</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Upload game covers and hero-banner images here, then copy the URL into Games & Packages or Hero Banner.
          </p>
        </div>
        <Button onClick={() => fileInputRef.current?.click()} disabled={uploading}>
          {uploading ? "Uploading…" : "Upload Image"}
        </Button>
        <input
          ref={fileInputRef}
          type="file"
          accept="image/png,image/jpeg,image/webp,image/gif"
          multiple
          className="hidden"
          onChange={(e) => handleFiles(e.target.files)}
        />
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <div
        onDragOver={(e) => {
          e.preventDefault();
          setIsDragging(true);
        }}
        onDragLeave={() => setIsDragging(false)}
        onDrop={(e) => {
          e.preventDefault();
          setIsDragging(false);
          handleFiles(e.dataTransfer.files);
        }}
        className={`mb-6 flex flex-col items-center justify-center rounded-2xl border-2 border-dashed px-6 py-10 text-center transition-colors ${
          isDragging
            ? "border-brand-500 bg-brand-50 dark:bg-brand-500/10"
            : "border-gray-300 dark:border-gray-700"
        }`}
      >
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Drag and drop images here, or use the &quot;Upload Image&quot; button above.
        </p>
        <p className="mt-1 text-theme-xs text-gray-400">JPG, PNG, WEBP, or GIF — up to 5MB each.</p>
      </div>

      <input
        type="search"
        value={search}
        onChange={(e) => {
          setPageNumber(1);
          setSearch(e.target.value);
        }}
        placeholder="Search by filename…"
        className="mb-6 w-full max-w-sm rounded-lg border border-gray-300 px-4 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white"
      />

      <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
        {page?.data.map((image) => (
          <div
            key={image.id}
            className="group relative overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]"
          >
            {/* eslint-disable-next-line @next/next/no-img-element -- externally-hosted local disk URL, not an optimizable static asset */}
            <img src={image.url} alt={image.original_name} className="aspect-square w-full object-cover" />
            <div className="p-2">
              <p className="truncate text-theme-xs font-medium text-gray-700 dark:text-gray-300" title={image.original_name}>
                {image.original_name}
              </p>
              <p className="text-theme-xs text-gray-400">{formatSize(image.size_bytes)}</p>
              <div className="mt-2 flex gap-1.5">
                <Button size="small" variant="outlined" className="flex-1" onClick={() => handleCopyUrl(image)}>
                  {copiedId === image.id ? "Copied!" : "Copy URL"}
                </Button>
                <Button size="small" severity="danger" onClick={() => handleDeleteClick(image)}>
                  Delete
                </Button>
              </div>
            </div>
          </div>
        ))}
      </div>

      {page?.data.length === 0 && (
        <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">
          {search ? "No images match your search." : "No images uploaded yet."}
        </p>
      )}
      {page === null && !error && <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>}

      {page && page.last_page > 1 && (
        <div className="mt-6 flex items-center justify-between text-theme-sm text-gray-500 dark:text-gray-400">
          <span>
            Page {page.current_page} of {page.last_page} ({page.total} total)
          </span>
          <div className="flex gap-2">
            <Button size="small" variant="outlined" disabled={page.current_page <= 1} onClick={() => setPageNumber((p) => p - 1)}>
              Previous
            </Button>
            <Button size="small" variant="outlined" disabled={page.current_page >= page.last_page} onClick={() => setPageNumber((p) => p + 1)}>
              Next
            </Button>
          </div>
        </div>
      )}

      {deletingImage && (
        <div
          className="fixed inset-0 z-99999 flex items-center justify-center bg-gray-400/50 backdrop-blur-[8px]"
          onClick={() => {
            setDeletingImage(null);
            setDeletingReferences(null);
          }}
        >
          <div
            className="w-full max-w-sm rounded-2xl bg-white p-6 dark:bg-gray-900"
            onClick={(e) => e.stopPropagation()}
          >
            <h2 className="text-lg font-semibold text-gray-800 dark:text-white/90">Delete image?</h2>
            <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">
              &quot;{deletingImage.original_name}&quot; will be permanently deleted.
            </p>
            {loadingReferences && (
              <p className="mt-3 text-theme-xs text-gray-400">Checking what still uses this image…</p>
            )}
            {!loadingReferences && deletingReferences && deletingReferences.length > 0 && (
              <div className="mt-3 rounded-lg bg-warning-50 px-3 py-2 dark:bg-warning-500/15">
                <p className="text-theme-xs font-medium text-warning-700 dark:text-warning-400">
                  Still in use — deleting will not update these, they&apos;ll be left pointing at a missing image:
                </p>
                <ul className="mt-1 list-inside list-disc text-theme-xs text-warning-700 dark:text-warning-400">
                  {deletingReferences.map((ref) => (
                    <li key={ref}>{ref}</li>
                  ))}
                </ul>
              </div>
            )}
            {!loadingReferences && deletingReferences && deletingReferences.length === 0 && (
              <p className="mt-3 text-theme-xs text-gray-400">Nothing else currently uses this image.</p>
            )}
            <div className="mt-6 flex justify-end gap-2">
              <Button
                size="small"
                variant="outlined"
                onClick={() => {
                  setDeletingImage(null);
                  setDeletingReferences(null);
                }}
              >
                Cancel
              </Button>
              <Button size="small" severity="danger" onClick={handleDeleteConfirmed}>
                {deletingReferences && deletingReferences.length > 0 ? "Delete Anyway" : "Delete"}
              </Button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
