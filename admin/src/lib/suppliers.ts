import { apiFetch } from "@/lib/api-client";
import { supplierBalanceLabel } from "@/lib/dashboard";

/**
 * ADR-046 — Supplier Management, trimmed to SUPP-1/CRUD/SUPP-5.
 * `api_config` is never present on the wire (SUPP-5 — backend model
 * keeps it $hidden); `has_credentials` is the only signal this screen
 * ever gets about whether it's set.
 */
export interface Supplier {
  id: number;
  name: string;
  slug: string;
  /** ADR-125 decision 1: the adapter. An API supplier's driver is its slug; every manual supplier is "manual". */
  driver: string;
  /** ADR-125 decision 18: "prepaid" for every API supplier. */
  funding_mode: "prepaid" | "pay_after";
  logo_url: string | null;
  is_active: boolean;
  /** The last API-polled balance. Read display_balance to show a balance. */
  balance: string | null;
  /** False for a manual supplier: no sync, no balance poll, no status check. */
  is_api_backed: boolean;
  /** Polled balance for an API supplier, funding-ledger balance for a manual one (negative = owed). */
  display_balance: number | null;
  currency: string;
  last_tested_at: string | null;
  last_test_result: string | null;
  circuit_state: "closed" | "open";
  /** True the moment ANY api_config key is saved — not the same as ready-to-use, see is_fully_configured. */
  has_credentials: boolean;
  /** Every SupplierConfigSchema key for this slug is present — what the "Configured" badge should actually gate on. */
  is_fully_configured: boolean;
  /** Secret-type keys (per slug) that actually have a value set — never the value itself. Drives each secret field's own badge. */
  configured_secret_keys: string[];
  /** ADR-046 decision 3 — only the non-secret api_config keys (base_url/sandbox/testing/category_whitelist/etc.), pre-fills the Edit form. */
  visible_config: Record<string, string | boolean | string[] | null>;
  /** ADR-046 addendum — null when this supplier has no sandbox/testing-mode field at all. */
  is_sandbox: boolean | null;
  reference_counts: {
    packages: number;
    supplier_products: number;
    orders: number;
  };
}

/**
 * ADR-046 decision 3 — the Edit form's field structure per supplier:
 * "secret" fields are write-only and masked (SUPP-5, never
 * redisplayed by the backend), "text"/"boolean"/"list" fields are
 * plain visible/editable operational config (base_url, sandbox/testing
 * mode, category whitelist). `api_config` itself stays schemaless on
 * the backend — this list is the one place that needs updating when a
 * supplier's real shape is confirmed. Mirror of the backend's
 * SupplierConfigSchema; keep both in sync by hand.
 */
export type SupplierFieldType = "text" | "boolean" | "secret" | "list";

export interface SupplierField {
  key: string;
  label: string;
  type: SupplierFieldType;
  /** Format hint shown as the input's placeholder — text/list/boolean fields only; secrets are opaque tokens with no format to hint at. */
  placeholder?: string;
}

export const SUPPLIER_FIELD_DEFINITIONS: Record<string, SupplierField[]> = {
  // ADR-125 decisions 2-3: keyed by driver, so every manual supplier shares this.
  manual: [
    { key: "reply_sla_minutes", label: "Reply SLA (minutes)", type: "text", placeholder: "30 (blank = 30) — a Pending order counts as stuck after this" },
  ],
  gamevion: [
    { key: "base_url", label: "Base URL", type: "text", placeholder: "https://api.gamevion.com (no trailing slash)" },
    { key: "bearer_token", label: "Bearer Token", type: "secret" },
    { key: "api_key", label: "API Key", type: "secret" },
    { key: "sandbox", label: "Sandbox Mode", type: "boolean" },
    // ADR-069 decision 13: a bare number in the supplier's balance
    // currency — drives the daily low-balance warning + Health chip.
    { key: "low_balance_threshold", label: "Low-balance Threshold", type: "text", placeholder: "e.g. 50 (blank = no warning)" },
    // ADR-083 decision 6: max acceptable gap between the polled balance
    // above and this supplier's own funding-ledger sum before the
    // Dashboard Health drift chip lights up. Blank = never checked.
    { key: "drift_threshold", label: "Drift Threshold", type: "text", placeholder: "e.g. 50 (blank = drift never checked)" },
    // ADR-015 2026-10-17 addendum: email admins when this supplier's prices
    // haven't synced for this many minutes. Blank = 90, 0 = off.
    { key: "price_sync_stale_alert_minutes", label: "Price-sync Stale Alert (minutes)", type: "text", placeholder: "90 (blank = 90; 0 = no alert)" },
  ],
  digiflazz: [
    { key: "base_url", label: "Base URL", type: "text", placeholder: "https://api.digiflazz.com (no trailing slash)" },
    { key: "username", label: "Username", type: "secret" },
    { key: "api_key", label: "API Key", type: "secret" },
    { key: "testing", label: "Testing Mode", type: "boolean" },
    { key: "customer_no_separator", label: "Customer No. Separator", type: "text", placeholder: "| (default — joins player ID and server ID)" },
    // ADR-067 decision 2: Digiflazz's price-list spans Games/Data/Pulsa/PLN/etc.
    // Blank = sync every category into Product Manager.
    { key: "category_whitelist", label: "Category Whitelist", type: "list", placeholder: "Games (comma-separated; blank = sync all categories)" },
    // ADR-069 decision 8: the HMAC-SHA1 secret set in Digiflazz's panel
    // (Atur Koneksi > API > Webhook). Optional for outbound calls;
    // DigiflazzWebhookController rejects every callback until it's set.
    { key: "webhook_secret", label: "Webhook Secret", type: "secret" },
    // ADR-069 decision 13: a bare number in IDR — drives the daily
    // low-balance warning + the Dashboard Health chip.
    { key: "low_balance_threshold", label: "Low-balance Threshold", type: "text", placeholder: "e.g. 100000 (IDR; blank = no warning)" },
    // ADR-083 decision 6: max acceptable gap (IDR) between the polled
    // balance above and this supplier's own funding-ledger sum.
    { key: "drift_threshold", label: "Drift Threshold", type: "text", placeholder: "e.g. 50000 (IDR; blank = drift never checked)" },
    // ADR-015 2026-10-17 addendum: email admins when this supplier's prices
    // haven't synced for this many minutes. Blank = 90, 0 = off.
    { key: "price_sync_stale_alert_minutes", label: "Price-sync Stale Alert (minutes)", type: "text", placeholder: "90 (blank = 90; 0 = no alert)" },
  ],
};

/**
 * ADR-069 decision 8 — Digiflazz-only: the inbound webhook is inert
 * until `webhook_secret` is saved. `configured_secret_keys` is the
 * only signal this screen gets about which secrets are actually set.
 */
export function isWebhookConfigured(supplier: Supplier): boolean {
  return supplier.slug !== "digiflazz" || supplier.configured_secret_keys.includes("webhook_secret");
}

/** ADR-125 decision 1: an API driver's slug is set by the backend; only a manual supplier sends its own. */
/** ADR-125 decision 18 — the balance to show for a /middleware/suppliers row. */
export function supplierDisplayBalance(supplier: Supplier): string {
  return supplierBalanceLabel(supplier.display_balance, supplier.currency, !supplier.is_api_backed && supplier.funding_mode === "pay_after");
}

/** ADR-125 decision 1 (+ its 2026-10-17 addendum): a manual supplier's funding mode, in words. */
export function fundingModeLabel(supplier: Pick<Supplier, "is_api_backed" | "funding_mode">): string {
  if (supplier.is_api_backed) return "Prepaid (API)";

  return supplier.funding_mode === "pay_after" ? "Pay after delivery" : "Prepaid";
}

/** ADR-125 decision 1: an API driver's slug is set by the backend; only a manual supplier sends its own. */
export interface CreateSupplierValues {
  name: string;
  driver: string;
  slug?: string;
  currency: string;
  funding_mode: "prepaid" | "pay_after";
  logo_url?: string;
}

export interface UpdateSupplierValues {
  name?: string;
  logo_url?: string | null;
  currency?: string;
  funding_mode?: "prepaid" | "pay_after";
  api_config?: Record<string, unknown>;
}

export function listSuppliers() {
  return apiFetch<Supplier[]>("/api/middleware/suppliers");
}

export function listAvailableSupplierDrivers() {
  return apiFetch<string[]>("/api/middleware/suppliers/available-drivers");
}

export function createSupplier(values: CreateSupplierValues) {
  return apiFetch<Supplier>("/api/middleware/suppliers", { method: "POST", body: values });
}

/**
 * ADR-069 decision 11 — after any `api_config` change, the backend
 * runs a `checkBalance()` probe and returns the result, so a
 * silently-broken credential rotation shows up immediately rather than
 * only at the next order.
 */
export interface ConnectionProbe {
  connection_ok: boolean;
  balance: string | number | null;
  error: string | null;
  /** ADR-069 stress-test Q3 — the check was skipped because the breaker is open, not a credential failure. */
  breaker_open?: boolean;
}

export function updateSupplier(supplierId: number, values: UpdateSupplierValues) {
  return apiFetch<Supplier & { connection_probe?: ConnectionProbe }>(
    `/api/middleware/suppliers/${supplierId}`,
    { method: "PUT", body: values },
  );
}

/** ADR-046 decision 5 — the confirm warning itself lives in the caller (this is a plain toggle call). */
export function updateSupplierStatus(supplierId: number, isActive: boolean) {
  return apiFetch<Supplier>(`/api/middleware/suppliers/${supplierId}/status`, {
    method: "PATCH",
    body: { is_active: isActive },
  });
}

export function deleteSupplier(supplierId: number) {
  return apiFetch<null>(`/api/middleware/suppliers/${supplierId}`, { method: "DELETE" });
}

export function refreshSupplierBalance(supplierId: number) {
  return apiFetch<Supplier>(`/api/middleware/suppliers/${supplierId}/refresh-balance`, { method: "POST" });
}

export interface UpdateSupplierPackagesStatusValues {
  is_active: boolean;
  game_id?: number;
  reason?: string;
}

/** ADR-046 decisions 9/10 — "Deactivate All"/"Deactivate by Game" and their paired "Reactivate". */
export function updateSupplierPackagesStatus(
  supplierId: number,
  values: UpdateSupplierPackagesStatusValues,
) {
  return apiFetch<{ updated: number }>(`/api/middleware/suppliers/${supplierId}/packages/status`, {
    method: "PATCH",
    body: values,
  });
}
