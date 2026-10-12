"use client";

import { useEffect, useState } from "react";
import { Times as CloseIcon } from "@primeicons/react/times";
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
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { useClientSession } from "@/hooks/useClientSession";
import { useDebouncedValue } from "@/hooks/useDebouncedValue";
import { useLatestRequest } from "@/hooks/useLatestRequest";
import { ApiError } from "@/lib/api-client";
import { listGalleryImages, type GalleryImagePage } from "@/lib/gallery";

/**
 * A plain URL text field plus a "Choose from gallery" button. The gallery
 * has no FK to what uses it (ADR-095): picking an image just writes its `url`
 * into the field, so the delete-references check keeps working and an
 * externally-hosted URL can still be pasted.
 */
export function ImageUrlInput({
  id,
  value,
  onChange,
  placeholder,
}: {
  id: string;
  value: string;
  onChange: (url: string) => void;
  placeholder?: string;
}) {
  const [pickerOpen, setPickerOpen] = useState(false);

  return (
    <>
      <div className="flex items-center gap-2">
        <div className="min-w-0 flex-1">
          <Input id={id} value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} />
        </div>
        <Button type="button" variant="outlined" className="shrink-0" onClick={() => setPickerOpen(true)}>
          Choose from gallery
        </Button>
      </div>
      <Dialog open={pickerOpen} onOpenChange={(e) => setPickerOpen(!!e.value)}>
        <DialogPortal>
          <DialogBackdrop />
          <DialogPositioner>
            <DialogPopup className="w-full max-w-3xl">
              <DialogHeader>
                <DialogTitle>Choose from gallery</DialogTitle>
                <DialogHeaderActions>
                  <DialogClose aria-label="Close">
                    <CloseIcon className="h-5 w-5" />
                  </DialogClose>
                </DialogHeaderActions>
              </DialogHeader>
              <DialogContent>
                {pickerOpen && (
                  <GalleryGrid
                    selectedUrl={value}
                    onPick={(url) => {
                      onChange(url);
                      setPickerOpen(false);
                    }}
                  />
                )}
              </DialogContent>
            </DialogPopup>
          </DialogPositioner>
        </DialogPortal>
      </Dialog>
    </>
  );
}

function GalleryGrid({ selectedUrl, onPick }: { selectedUrl: string; onPick: (url: string) => void }) {
  const session = useClientSession();
  const [search, setSearch] = useState("");
  const debouncedSearch = useDebouncedValue(search);
  const [pageNumber, setPageNumber] = useState(1);
  // Adjusted during render (see orders/page.tsx): back to page 1 once the
  // debounced term changes.
  const [paginationSearchKey, setPaginationSearchKey] = useState(debouncedSearch);
  if (paginationSearchKey !== debouncedSearch) {
    setPaginationSearchKey(debouncedSearch);
    setPageNumber(1);
  }
  const [page, setPage] = useState<GalleryImagePage | null>(null);
  const [error, setError] = useState<string | null>(null);
  const beginLoad = useLatestRequest();

  useEffect(() => {
    if (!session) return;
    const isLatest = beginLoad();
    listGalleryImages(session.token, { search: debouncedSearch || undefined, page: pageNumber })
      .then((p) => isLatest() && setPage(p))
      .catch((err: unknown) => isLatest() && setError(err instanceof ApiError ? err.message : "Could not load the gallery."));
  }, [session, debouncedSearch, pageNumber, beginLoad]);

  return (
    <div>
      <Input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search by filename…" className="mb-4 max-w-sm" />

      {error && <p className="mb-3 text-sm text-error-600 dark:text-error-400">{error}</p>}

      <div className="grid max-h-[60vh] grid-cols-2 gap-3 overflow-y-auto sm:grid-cols-3 lg:grid-cols-4">
        {page?.data.map((image) => (
          <button
            key={image.id}
            type="button"
            onClick={() => onPick(image.url)}
            aria-pressed={image.url === selectedUrl}
            className={`overflow-hidden rounded-xl border bg-white text-left transition hover:border-brand-400 dark:bg-white/[0.03] ${
              image.url === selectedUrl ? "border-brand-500 ring-2 ring-brand-500/30" : "border-gray-200 dark:border-gray-800"
            }`}
          >
            {/* eslint-disable-next-line @next/next/no-img-element -- R2-hosted gallery URL, not an optimizable static asset */}
            <img src={image.url} alt={image.original_name} className="aspect-square w-full object-cover" />
            <p className="truncate p-2 text-theme-xs font-medium text-gray-700 dark:text-gray-300" title={image.original_name}>
              {image.original_name}
            </p>
          </button>
        ))}
      </div>

      {page?.data.length === 0 && (
        <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">
          {debouncedSearch ? "No images match your search." : "No images uploaded yet — upload one in Image Gallery first."}
        </p>
      )}
      {page === null && !error && <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>}

      {page && page.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-theme-sm text-gray-500 dark:text-gray-400">
          <span>
            Page {page.current_page} of {page.last_page}
          </span>
          <div className="flex gap-2">
            <Button type="button" size="small" variant="outlined" disabled={page.current_page <= 1} onClick={() => setPageNumber((p) => p - 1)}>
              Previous
            </Button>
            <Button type="button" size="small" variant="outlined" disabled={page.current_page >= page.last_page} onClick={() => setPageNumber((p) => p + 1)}>
              Next
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
