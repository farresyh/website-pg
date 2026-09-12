import { apiFetch, apiUpload } from "@/lib/api-client";

/**
 * ADR-059 59a read layer. Mirrors
 * `backend/app/Http/Controllers/Affiliate/*` + `AffiliateEarningsService`.
 * This client only forwards the bearer token and renders whatever the
 * backend returns — no money math here (foundation-security.md).
 */

export type PaymentStatus = "pending" | "paid" | "failed";
export type DeliveryStatus =
  | "not_started"
  | "processing"
  | "delivered"
  | "failed"
  | "refunded";
export type SubscriptionStatus = "active" | "grace" | "lapsed";

export interface SubscriptionSnapshot {
  tier_name: string;
  status: SubscriptionStatus;
  monthly_fee_sen: number;
  next_charge_at: string | null;
  grace_until: string | null;
}

export interface DashboardStats {
  earnings_balance: number;
  today: { orders: number; sales: number };
  this_month: { orders: number; sales: number };
  subscription: SubscriptionSnapshot | null;
}

/**
 * ADR-072 decision 5 / PR-G: the reseller-portal (wallet) `Order`
 * screens reuse this exact same shape, over a narrower backend response
 * (`ResellerPortal\OrderController`) — every affiliate-only field
 * (`reference_number`, `affiliate_profit`, the customer/money detail
 * fields) is simply absent from that response, never present-but-zero.
 */
export interface OrderListItem {
  order_number: string;
  reference_number?: string | null;
  game: { name: string; slug: string } | null;
  package_name: string | null;
  final_amount: number;
  affiliate_profit?: number;
  payment_status: PaymentStatus;
  delivery_status: DeliveryStatus;
  paid_at?: string | null;
  created_at: string | null;
}

export interface OrderDetail extends OrderListItem {
  customer_email?: string | null;
  customer_name?: string | null;
  customer_phone?: string | null;
  player_id: string | null;
  server_id: string | null;
  affiliate_markup_pct?: number;
  voucher_discount?: number;
  transaction_fee?: number;
  payment_method?: string | null;
  delivered_at: string | null;
}

export interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface LedgerEntry {
  id: number;
  type: string;
  amount: number;
  reference_type: string | null;
  reference_id: number | null;
  /** Only present on the Reseller wallet ledger (reference_type "order") — not the Affiliate earnings ledger. */
  order_number?: string | null;
  reason: string | null;
  created_at: string | null;
}

export interface EarningsResponse {
  balance: number;
  entries: Paginated<LedgerEntry>;
}

export interface SubscriptionResponse {
  subscription:
    | (SubscriptionSnapshot & {
        current_period_started_at: string | null;
      })
    | null;
  charge_history: Array<{ id: number; amount: number; charged_at: string | null }>;
}

export interface OrderFilters {
  payment_status?: PaymentStatus;
  delivery_status?: DeliveryStatus;
  search?: string;
  page?: number;
}

export function getDashboard(token: string) {
  return apiFetch<DashboardStats>("/api/affiliate/dashboard", { token });
}

/**
 * ADR-072 decision 5 / PR-G: both account types have an Orders screen,
 * over two different (but response-compatible) backend endpoints —
 * `ownerType` picks which one. `search` has no reseller-portal
 * equivalent (the backend endpoint doesn't accept it) — silently
 * ignored rather than sent for a reseller session.
 */
export function listOrders(
  token: string,
  ownerType: "affiliate" | "reseller",
  filters: OrderFilters = {},
) {
  const params = new URLSearchParams();
  if (filters.payment_status) params.set("payment_status", filters.payment_status);
  if (filters.delivery_status) params.set("delivery_status", filters.delivery_status);
  if (filters.search && ownerType === "affiliate") params.set("search", filters.search);
  if (filters.page) params.set("page", String(filters.page));
  const query = params.toString();

  const base = ownerType === "affiliate" ? "/api/affiliate/orders" : "/api/reseller-portal/orders";

  return apiFetch<Paginated<OrderListItem>>(`${base}${query ? `?${query}` : ""}`, { token });
}

export function getOrder(token: string, ownerType: "affiliate" | "reseller", orderNumber: string) {
  const base = ownerType === "affiliate" ? "/api/affiliate/orders" : "/api/reseller-portal/orders";

  return apiFetch<OrderDetail>(`${base}/${encodeURIComponent(orderNumber)}`, { token });
}

export function getEarnings(token: string, page = 1) {
  return apiFetch<EarningsResponse>(`/api/affiliate/earnings?page=${page}`, { token });
}

export function getSubscription(token: string) {
  return apiFetch<SubscriptionResponse>("/api/affiliate/subscription", { token });
}

// --- 59c: Profile + Withdrawal + Impersonation ---

export interface AffiliateProfile {
  business_name: string;
  contact_name: string | null;
  email: string | null;
  phone: string | null;
  bank_name: string | null;
  bank_account_no: string | null;
  bank_account_holder: string | null;
}

export type WithdrawalStatus = "pending" | "approved" | "rejected" | "completed";

export interface WithdrawalRow {
  id: number;
  amount: number;
  bank_name: string;
  bank_account_no: string;
  bank_account_holder: string;
  status: WithdrawalStatus;
  admin_note: string | null;
  created_at: string | null;
  processed_at: string | null;
}

export interface WithdrawalsResponse {
  balance: number;
  prefill: {
    bank_name: string | null;
    bank_account_no: string | null;
    bank_account_holder: string | null;
  };
  withdrawals: WithdrawalRow[];
}

export interface ImpersonationContext {
  session_id: number;
  admin_name: string | null;
  started_at: string | null;
}

export interface MeResponse {
  affiliate_user: {
    id: number;
    owner_type: "affiliate" | "reseller";
    owner_id: number;
    name: string;
    email: string;
    last_login_at: string | null;
  };
  affiliate: { id: number; business_name: string; status: string } | null;
  reseller: { id: number; business_name: string; is_active: boolean } | null;
  impersonation: ImpersonationContext | null;
}

export function getMe(token: string) {
  return apiFetch<MeResponse>("/api/affiliate/me", { token });
}

export function getProfile(token: string) {
  return apiFetch<AffiliateProfile>("/api/affiliate/profile", { token });
}

export function updateProfile(
  token: string,
  body: Partial<
    Pick<
      AffiliateProfile,
      | "contact_name"
      | "phone"
      | "bank_name"
      | "bank_account_no"
      | "bank_account_holder"
    >
  >,
) {
  return apiFetch<AffiliateProfile>("/api/affiliate/profile", {
    method: "PUT",
    token,
    body,
  });
}

export function getWithdrawals(token: string) {
  return apiFetch<WithdrawalsResponse>("/api/affiliate/withdrawals", { token });
}

export function createWithdrawal(
  token: string,
  body: {
    amount: number;
    bank_name?: string;
    bank_account_no?: string;
    bank_account_holder?: string;
  },
) {
  return apiFetch<{ id: number; amount: number; status: WithdrawalStatus }>(
    "/api/affiliate/withdrawals",
    { method: "POST", token, body },
  );
}

export function endImpersonation(token: string) {
  return apiFetch<{ message: string }>("/api/affiliate/impersonation/end", {
    method: "POST",
    token,
  });
}

/**
 * ADR-060 PR-5 — the affiliate's branded-storefront custom domains.
 * Provider-opaque: the backend already sanitised every string, the UI
 * just renders `dns.cname_target` (a platform alias) and any extra
 * `verification` records. Never names a hosting provider.
 */
export type AffiliateDomainStatus = "pending" | "active" | "failed" | "suspended";

export interface AffiliateDomainRow {
  id: number;
  hostname: string;
  status: AffiliateDomainStatus;
  is_primary: boolean;
  verification: { type: string; domain: string; value: string }[];
  last_checked_at: string | null;
  verified_at: string | null;
  last_error: string | null;
}

export interface DomainsResponse {
  domains: AffiliateDomainRow[];
  max_domains: number;
  writable: boolean;
  dns: { cname_target: string; apex_a_record: string };
}

export function getDomains(token: string) {
  return apiFetch<DomainsResponse>("/api/affiliate/domains", { token });
}

export function addDomain(token: string, hostname: string) {
  return apiFetch<AffiliateDomainRow>("/api/affiliate/domains", {
    method: "POST",
    token,
    body: { hostname },
  });
}

export function recheckDomain(token: string, id: number) {
  return apiFetch<AffiliateDomainRow>(`/api/affiliate/domains/${id}/recheck`, {
    method: "POST",
    token,
  });
}

export function setPrimaryDomain(token: string, id: number) {
  return apiFetch<AffiliateDomainRow>(`/api/affiliate/domains/${id}/primary`, {
    method: "POST",
    token,
  });
}

export function removeDomain(token: string, id: number) {
  return apiFetch<void>(`/api/affiliate/domains/${id}`, { method: "DELETE", token });
}

/**
 * ADR-060 PR-6 — the portal "Storefront" screen (Branding / Hero /
 * Catalog / Pricing tabs). Every write is `assertWritable`-gated on the
 * backend; each response carries `writable` so the UI degrades to
 * read-only for a deactivated affiliate. Money math (the markup preview)
 * is computed server-side and only rendered here (foundation-security.md).
 */

export interface StorefrontBrandingResponse {
  branding: {
    store_name: string;
    description: string | null;
    logo_url: string | null;
    favicon_url: string | null;
    theme_preset?: string | null;
    theme_mode?: "light" | "dark" | null;
    support_email: string | null;
    support_phone: string | null;
    telegram_contact_link: string | null;
    social_links: Record<string, string> | null;
  };
  seo: {
    ga_measurement_id: string | null;
    fb_pixel_id: string | null;
    tiktok_pixel_id: string | null;
  };
  writable: boolean;
}

export function getStorefrontBranding(token: string) {
  return apiFetch<StorefrontBrandingResponse>("/api/affiliate/storefront/branding", { token });
}

export function updateStorefrontBranding(
  token: string,
  body: {
    store_name: string;
    theme_preset?: string | null;
    theme_mode?: "light" | "dark" | null;
    description?: string | null;
    support_email?: string | null;
    support_phone?: string | null;
    telegram_contact_link?: string | null;
    social_links?: Record<string, string>;
  },
) {
  return apiFetch<StorefrontBrandingResponse>("/api/affiliate/storefront/branding", {
    method: "PUT",
    token,
    body,
  });
}

export function updateStorefrontSeo(
  token: string,
  body: { ga_measurement_id?: string | null; fb_pixel_id?: string | null; tiktok_pixel_id?: string | null },
) {
  return apiFetch<{ ga_measurement_id: string | null; fb_pixel_id: string | null; tiktok_pixel_id: string | null }>(
    "/api/affiliate/storefront/seo",
    { method: "PUT", token, body },
  );
}

export function uploadStorefrontLogo(token: string, file: File) {
  const form = new FormData();
  form.append("image", file);
  return apiUpload<StorefrontBrandingResponse>("/api/affiliate/storefront/branding/logo", form, { token });
}

export function deleteStorefrontLogo(token: string) {
  return apiFetch<StorefrontBrandingResponse>("/api/affiliate/storefront/branding/logo", {
    method: "DELETE",
    token,
  });
}

export function uploadStorefrontFavicon(token: string, file: File) {
  const form = new FormData();
  form.append("image", file);
  return apiUpload<StorefrontBrandingResponse>("/api/affiliate/storefront/branding/favicon", form, { token });
}

export function deleteStorefrontFavicon(token: string) {
  return apiFetch<StorefrontBrandingResponse>("/api/affiliate/storefront/branding/favicon", {
    method: "DELETE",
    token,
  });
}

export interface StorefrontHeroSlide {
  id: number;
  eyebrow: string | null;
  title: string;
  description: string | null;
  image_url: string | null;
  price_from_sen: number | null;
  primary_cta_label: string | null;
  primary_cta_href: string | null;
  secondary_cta_label: string | null;
  secondary_cta_href: string | null;
  is_active: boolean;
  sort_order: number;
}

export interface StorefrontHeroSlidesResponse {
  slides: StorefrontHeroSlide[];
  max_slides: number;
  writable: boolean;
}

export interface HeroSlideInput {
  eyebrow?: string;
  title: string;
  description?: string;
  price_from_sen?: number | null;
  primary_cta_label?: string;
  primary_cta_href?: string;
  secondary_cta_label?: string;
  secondary_cta_href?: string;
  is_active: boolean;
  sort_order: number;
}

function heroFormData(input: HeroSlideInput, image: File | null): FormData {
  const form = new FormData();
  for (const [key, value] of Object.entries(input)) {
    if (value !== undefined && value !== null) form.append(key, String(value));
  }
  // The backend `boolean` rule needs a literal "1"/"0", not "true".
  form.set("is_active", input.is_active ? "1" : "0");
  if (image) form.append("image", image);
  return form;
}

export function getStorefrontHeroSlides(token: string) {
  return apiFetch<StorefrontHeroSlidesResponse>("/api/affiliate/storefront/hero-slides", { token });
}

export function createStorefrontHeroSlide(token: string, input: HeroSlideInput, image: File) {
  return apiUpload<StorefrontHeroSlide>(
    "/api/affiliate/storefront/hero-slides",
    heroFormData(input, image),
    { token },
  );
}

export function updateStorefrontHeroSlide(
  token: string,
  id: number,
  input: HeroSlideInput,
  image: File | null,
) {
  const form = heroFormData(input, image);
  form.append("_method", "PUT"); // Laravel method spoofing — multipart can't be a real PUT
  return apiUpload<StorefrontHeroSlide>(`/api/affiliate/storefront/hero-slides/${id}`, form, { token });
}

export function setStorefrontHeroSlideStatus(token: string, id: number, isActive: boolean) {
  return apiFetch<StorefrontHeroSlide>(`/api/affiliate/storefront/hero-slides/${id}/status`, {
    method: "PATCH",
    token,
    body: { is_active: isActive },
  });
}

export function deleteStorefrontHeroSlide(token: string, id: number) {
  return apiFetch<void>(`/api/affiliate/storefront/hero-slides/${id}`, { method: "DELETE", token });
}

export interface StorefrontGame {
  id: number;
  name: string;
  slug: string;
  category: string | null;
  is_visible: boolean;
}

export interface StorefrontGamesResponse {
  games: StorefrontGame[];
  writable: boolean;
}

export function getStorefrontGames(token: string, search = "") {
  const query = search ? `?search=${encodeURIComponent(search)}` : "";
  return apiFetch<StorefrontGamesResponse>(`/api/affiliate/storefront/games${query}`, { token });
}

export function setStorefrontGameVisibility(token: string, gameId: number, isVisible: boolean) {
  return apiFetch<StorefrontGamesResponse>(`/api/affiliate/storefront/games/${gameId}`, {
    method: "PUT",
    token,
    body: { is_visible: isVisible },
  });
}

export interface StorefrontPricing {
  markup_pct: number;
  effective_markup_pct: number;
  max_markup_pct: number;
  wholesale_rate_active: boolean;
}

export interface MarkupPreviewRow {
  game_name: string | null;
  package_name: string;
  customer_price_sen: number;
  your_margin_sen: number;
}

export interface MarkupPreview {
  markup_pct: number;
  rows: MarkupPreviewRow[];
  wholesale_rate_active: boolean;
}

export function getStorefrontPricing(token: string) {
  return apiFetch<StorefrontPricing>("/api/affiliate/storefront/pricing", { token });
}

export function updateStorefrontMarkup(token: string, markupPct: number) {
  return apiFetch<StorefrontPricing>("/api/affiliate/storefront/pricing", {
    method: "PUT",
    token,
    body: { markup_pct: markupPct },
  });
}

export function previewStorefrontMarkup(token: string, markupPct: number) {
  return apiFetch<MarkupPreview>("/api/affiliate/storefront/pricing/preview", {
    method: "POST",
    token,
    body: { markup_pct: markupPct },
  });
}
