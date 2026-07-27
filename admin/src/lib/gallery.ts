import { apiFetch, apiUpload } from "@/lib/api-client";

/**
 * IMG-1/IMG-2 (docs/prd.md §14/§15 backlog) — Image Gallery. No FK
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
