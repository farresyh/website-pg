import { apiFetch } from "@/lib/api-client";

/** ADR-029 — SEO Management: Overview, Global Settings/Templates, Game SEO, Redirects, FAQ, Crawler. `Scripts` removed by ADR-101 decision 9. */

export interface SeoOverview {
  /** ADR-140 decision 8: the content status over the tracked games (active, not noindex). */
  games: {
    total: number;
    published: number;
    draft: number;
    empty: number;
  };
  redirects: { total: number };
  sitemap_url: string;
  sitemap_url_count: number;
  recommendations: { severity: "warning" | "info"; message: string; link: string }[];
}

export interface SeoSettings {
  id: number;
  affiliate_id: number;
  default_meta_title: string | null;
  default_meta_description: string | null;
  default_og_image: string | null;
  meta_title_template: string | null;
  meta_description_template: string | null;
  ga_measurement_id: string | null;
  fb_pixel_id: string | null;
  tiktok_pixel_id: string | null;
  schema_organization_enabled: boolean;
  schema_product_enabled: boolean;
  schema_breadcrumb_enabled: boolean;
  /** Merged into every allowed CrawlerRule at render time — see robots() controller's doc comment for why this can't live per-row. */
  crawler_default_disallow_paths: string[] | null;
}

export type UpdateSeoSettingsValues = Partial<
  Omit<SeoSettings, "id" | "affiliate_id">
>;

/** ADR-140 decision 6: derived on the backend from the content fields and `content_published_at`. */
export type GameContentStatus = "empty" | "draft" | "published";

export interface GameSeoListItem {
  id: number;
  name: string;
  slug: string;
  is_active: boolean;
  seo_title: string | null;
  seo_description: string | null;
  seo_og_image: string | null;
  no_index: boolean;
  content_status: GameContentStatus;
  content_published_at: string | null;
}

export interface GameSeoDetail {
  id: number;
  name: string;
  slug: string;
  image_url: string | null;
  seo_title: string | null;
  seo_description: string | null;
  seo_og_image: string | null;
  schema_brand: string | null;
  schema_category: string | null;
  no_index: boolean;
  content_summary: string | null;
  content_about: string | null;
  content_faq: GameFaqItem[] | null;
  content_published_at: string | null;
  content_status: GameContentStatus;
}

export interface GameFaqItem {
  question: string;
  answer: string;
}

/** The body of PUT /api/seo/games/{id}/content (ADR-140 decision 6). */
export interface UpdateGameContentValues {
  summary: string | null;
  about: string | null;
  faq: GameFaqItem[];
  published: boolean;
}

export type UpdateGameSeoValues = Partial<
  Pick<
    GameSeoDetail,
    | "seo_title"
    | "seo_description"
    | "seo_og_image"
    | "schema_brand"
    | "schema_category"
    | "no_index"
  >
>;

export interface Redirect {
  id: number;
  affiliate_id: number;
  from_path: string;
  to_path: string;
  status_code: 301 | 302;
  hit_count: number;
}

export type SaveRedirectValues = Pick<Redirect, "from_path" | "to_path" | "status_code">;

/** ADR-120 decision 13: platform-wide FAQ, `{store_name}` resolved per brand on the storefront. */
export interface Faq {
  id: number;
  question: string;
  answer: string;
  sort_order: number;
  is_active: boolean;
}

export type SaveFaqValues = Omit<Faq, "id">;

export interface CrawlerRule {
  id: number;
  bot_name: string;
  user_agent: string;
  is_allowed: boolean;
  crawl_delay: number | null;
  disallow_paths: string[] | null;
  is_custom: boolean;
  sort_order: number;
}

export type SaveCrawlerRuleValues = Omit<CrawlerRule, "id" | "is_custom">;

export function getSeoOverview() {
  return apiFetch<SeoOverview>("/api/seo/overview");
}

export function getSeoSettings() {
  return apiFetch<SeoSettings>("/api/seo/settings");
}

export function updateSeoSettings(values: UpdateSeoSettingsValues) {
  return apiFetch<SeoSettings>("/api/seo/settings", { method: "PUT", body: values });
}

export function listGameSeo(params?: { search?: string; filter?: GameContentStatus }) {
  const query = new URLSearchParams();
  if (params?.search) query.set("search", params.search);
  if (params?.filter) query.set("filter", params.filter);
  const qs = query.toString();
  return apiFetch<GameSeoListItem[]>(`/api/seo/games${qs ? `?${qs}` : ""}`);
}

export function getGameSeo(gameId: number) {
  return apiFetch<GameSeoDetail>(`/api/seo/games/${gameId}`);
}

export function updateGameSeo(gameId: number, values: UpdateGameSeoValues) {
  return apiFetch<GameSeoDetail>(`/api/seo/games/${gameId}`, { method: "PUT", body: values });
}

export function updateGameContent(gameId: number, values: UpdateGameContentValues) {
  return apiFetch<GameSeoDetail>(`/api/seo/games/${gameId}/content`, { method: "PUT", body: values });
}

export function listRedirects() {
  return apiFetch<Redirect[]>("/api/seo/redirects");
}

export function createRedirect(values: SaveRedirectValues) {
  return apiFetch<Redirect>("/api/seo/redirects", { method: "POST", body: values });
}

export function updateRedirect(id: number, values: SaveRedirectValues) {
  return apiFetch<Redirect>(`/api/seo/redirects/${id}`, { method: "PUT", body: values });
}

export function deleteRedirect(id: number) {
  return apiFetch<null>(`/api/seo/redirects/${id}`, { method: "DELETE" });
}

export function listFaqs() {
  return apiFetch<Faq[]>("/api/seo/faqs");
}

export function createFaq(values: SaveFaqValues) {
  return apiFetch<Faq>("/api/seo/faqs", { method: "POST", body: values });
}

export function updateFaq(id: number, values: SaveFaqValues) {
  return apiFetch<Faq>(`/api/seo/faqs/${id}`, { method: "PUT", body: values });
}

export function deleteFaq(id: number) {
  return apiFetch<null>(`/api/seo/faqs/${id}`, { method: "DELETE" });
}

export function listCrawlerRules() {
  return apiFetch<CrawlerRule[]>("/api/seo/crawler-rules");
}

export function createCrawlerRule(values: SaveCrawlerRuleValues) {
  return apiFetch<CrawlerRule>("/api/seo/crawler-rules", { method: "POST", body: values });
}

export function updateCrawlerRule(id: number, values: SaveCrawlerRuleValues) {
  return apiFetch<CrawlerRule>(`/api/seo/crawler-rules/${id}`, { method: "PUT", body: values });
}

export function deleteCrawlerRule(id: number) {
  return apiFetch<null>(`/api/seo/crawler-rules/${id}`, { method: "DELETE" });
}
