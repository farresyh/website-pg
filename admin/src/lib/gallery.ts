import { apiFetch, apiUpload } from "@/lib/api-client";

/**
 * IMG-1/IMG-2 (docs/prd.md §15 "Image Gallery" row) — Image Gallery. No FK
 * from Game/HeroSlide to a gallery image: an admin uploads here,
 * copies the resulting `url`, and pastes it into `EditGameModal` /
 * the Hero Slide modal's existing `image_url` field — same as
 * pasting any externally-hosted URL today, just uploaded here first.
 */
export interface GalleryImage {
  id: number;
  original_name: string;
  url: string;
  mime_type: string;
  size_bytes: number;
  created_at: string;
}

export interface GalleryImagePage {
  data: GalleryImage[];
  current_page: number;
  last_page: number;
  total: number;
}

export function listGalleryImages(token: string, params: { search?: string; page?: number } = {}) {
  const query = new URLSearchParams();
  if (params.search) query.set("search", params.search);
  if (params.page) query.set("page", String(params.page));
  const qs = query.toString();

  return apiFetch<GalleryImagePage>(`/api/gallery/images${qs ? `?${qs}` : ""}`, { token });
}

export function uploadGalleryImage(token: string, file: File) {
  const formData = new FormData();
  formData.append("image", file);

  return apiUpload<GalleryImage>("/api/gallery/images", formData, { token });
}

export function deleteGalleryImage(token: string, id: number) {
  return apiFetch<void>(`/api/gallery/images/${id}`, { method: "DELETE", token });
}

/**
 * ADR-095 decision 5 — pre-flight check for the delete-confirmation
 * dialog: which Game/Hero Slide/Affiliate Branding rows still paste
 * this image's URL, so the admin sees exactly what would be affected
 * instead of a generic disclaimer. Never blocks the delete itself —
 * see GalleryImageController::references()'s own doc comment.
 */
export function getGalleryImageReferences(token: string, id: number) {
  return apiFetch<{ references: string[] }>(`/api/gallery/images/${id}/references`, { token });
}
