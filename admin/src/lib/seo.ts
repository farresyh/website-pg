import { apiFetch } from "@/lib/api-client";

/** ADR-029 — SEO Management: Overview, Global Settings/Templates, Game SEO, Redirects, Scripts, Crawler. */

export interface SeoOverview {
  games: {
    total: number;
    complete: number;
    missing_title: number;
    missing_description: number;
    missing_og_image: number;
  };
  redirects: { total: number };
  sitemap_url: string;
  sitemap_url_count: number;
  recommendations: { severity: "warning" | "info"; message: string; link: string }[];
}

export interface SeoSettings {
  id: number;
  reseller_id: number;
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
  Omit<SeoSettings, "id" | "reseller_id">
>;

export type GameSeoStatus = "complete" | "incomplete" | "missing";

export interface GameSeoListItem {
  id: number;
  name: string;
  slug: string;
  is_active: boolean;
  seo_title: string | null;
  seo_description: string | null;
  seo_og_image: string | null;
  no_index: boolean;
  seo_status: GameSeoStatus;
}

export interface GameSeoDetail {
  id: number;
  name: string;
  slug: string;
  image_url: string | null;
  seo_title: string | null;
  seo_title_local: string | null;
  seo_description: string | null;
  seo_description_local: string | null;
  seo_keywords: string | null;
  seo_og_image: string | null;
  schema_brand: string | null;
  schema_category: string | null;
  no_index: boolean;
}

export type UpdateGameSeoValues = Partial<
  Pick<
    GameSeoDetail,
    | "seo_title"
    | "seo_title_local"
    | "seo_description"
    | "seo_description_local"
    | "seo_keywords"
    | "seo_og_image"
    | "schema_brand"
    | "schema_category"
    | "no_index"
  >
>;

export interface Redirect {
  id: number;
  reseller_id: number;
  from_path: string;
  to_path: string;
  status_code: 301 | 302;
  hit_count: number;
}

export type SaveRedirectValues = Pick<Redirect, "from_path" | "to_path" | "status_code">;

export interface SeoScript {
  id: number;
  reseller_id: number | null;
  name: string;
  location: "head" | "body_end";
  code: string;
  priority: number;
  is_active: boolean;
}

export type SaveSeoScriptValues = Omit<SeoScript, "id">;

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

export function getSeoOverview(token: string) {
  return apiFetch<SeoOverview>("/api/seo/overview", { token });
}

export function getSeoSettings(token: string) {
  return apiFetch<SeoSettings>("/api/seo/settings", { token });
}

export function updateSeoSettings(token: string, values: UpdateSeoSettingsValues) {
  return apiFetch<SeoSettings>("/api/seo/settings", { method: "PUT", token, body: values });
}

export function listGameSeo(token: string, params?: { search?: string; filter?: GameSeoStatus }) {
  const query = new URLSearchParams();
  if (params?.search) query.set("search", params.search);
  if (params?.filter) query.set("filter", params.filter);
  const qs = query.toString();
  return apiFetch<GameSeoListItem[]>(`/api/seo/games${qs ? `?${qs}` : ""}`, { token });
}

export function getGameSeo(token: string, gameId: number) {
  return apiFetch<GameSeoDetail>(`/api/seo/games/${gameId}`, { token });
}

export function updateGameSeo(token: string, gameId: number, values: UpdateGameSeoValues) {
  return apiFetch<GameSeoDetail>(`/api/seo/games/${gameId}`, { method: "PUT", token, body: values });
}

export function listRedirects(token: string) {
  return apiFetch<Redirect[]>("/api/seo/redirects", { token });
}

export function createRedirect(token: string, values: SaveRedirectValues) {
  return apiFetch<Redirect>("/api/seo/redirects", { method: "POST", token, body: values });
}

export function updateRedirect(token: string, id: number, values: SaveRedirectValues) {
  return apiFetch<Redirect>(`/api/seo/redirects/${id}`, { method: "PUT", token, body: values });
}

export function deleteRedirect(token: string, id: number) {
  return apiFetch<null>(`/api/seo/redirects/${id}`, { method: "DELETE", token });
}

export function listSeoScripts(token: string) {
  return apiFetch<SeoScript[]>("/api/seo/scripts", { token });
}

export function createSeoScript(token: string, values: SaveSeoScriptValues) {
  return apiFetch<SeoScript>("/api/seo/scripts", { method: "POST", token, body: values });
}

export function updateSeoScript(token: string, id: number, values: SaveSeoScriptValues) {
  return apiFetch<SeoScript>(`/api/seo/scripts/${id}`, { method: "PUT", token, body: values });
}

export function deleteSeoScript(token: string, id: number) {
  return apiFetch<null>(`/api/seo/scripts/${id}`, { method: "DELETE", token });
}

export function listCrawlerRules(token: string) {
  return apiFetch<CrawlerRule[]>("/api/seo/crawler-rules", { token });
}

export function createCrawlerRule(token: string, values: SaveCrawlerRuleValues) {
  return apiFetch<CrawlerRule>("/api/seo/crawler-rules", { method: "POST", token, body: values });
}

export function updateCrawlerRule(token: string, id: number, values: SaveCrawlerRuleValues) {
  return apiFetch<CrawlerRule>(`/api/seo/crawler-rules/${id}`, { method: "PUT", token, body: values });
}

export function deleteCrawlerRule(token: string, id: number) {
  return apiFetch<null>(`/api/seo/crawler-rules/${id}`, { method: "DELETE", token });
}
