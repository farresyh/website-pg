import { apiFetch } from "@/lib/api-client";

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
  logo_url: string | null;
  is_active: boolean;
  balance: string | null;
  currency: string;
  last_tested_at: string | null;
  last_test_result: string | null;
  circuit_state: "closed" | "open";
  has_credentials: boolean;
  /** ADR-046 decision 3 — only the non-secret api_config keys (base_url/sandbox/testing/etc.), pre-fills the Edit form. */
  visible_config: Record<string, string | boolean | null>;
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
 * redisplayed by the backend), "text"/"boolean" fields are plain
 * visible/editable operational config (base_url, sandbox/testing
 * mode). `api_config` itself stays schemaless on the backend — this
 * list is the one place that needs updating when a supplier's real
 * shape is confirmed (e.g. Digiflazz, once its account is verified).
 */
export type SupplierFieldType = "text" | "boolean" | "secret";

export interface SupplierField {
  key: string;
  label: string;
  type: SupplierFieldType;
}

export const SUPPLIER_FIELD_DEFINITIONS: Record<string, SupplierField[]> = {
  gamevion: [
    { key: "base_url", label: "Base URL", type: "text" },
    { key: "bearer_token", label: "Bearer Token", type: "secret" },
    { key: "api_key", label: "API Key", type: "secret" },
    { key: "sandbox", label: "Sandbox Mode", type: "boolean" },
  ],
  digiflazz: [
    { key: "base_url", label: "Base URL", type: "text" },
    { key: "username", label: "Username", type: "secret" },
    { key: "api_key", label: "API Key", type: "secret" },
    { key: "testing", label: "Testing Mode", type: "boolean" },
    { key: "customer_no_separator", label: "Customer No. Separator", type: "text" },
  ],
};

export interface CreateSupplierValues {
  name: string;
  slug: string;
  currency: string;
  logo_url?: string;
}

export interface UpdateSupplierValues {
  name?: string;
  logo_url?: string | null;
  currency?: string;
  api_config?: Record<string, unknown>;
}

export function listSuppliers(token: string) {
  return apiFetch<Supplier[]>("/api/middleware/suppliers", { token });
}

export function listAvailableSupplierSlugs(token: string) {
  return apiFetch<string[]>("/api/middleware/suppliers/available-slugs", { token });
}

export function createSupplier(token: string, values: CreateSupplierValues) {
  return apiFetch<Supplier>("/api/middleware/suppliers", { method: "POST", body: values, token });
}

export function updateSupplier(token: string, supplierId: number, values: UpdateSupplierValues) {
  return apiFetch<Supplier>(`/api/middleware/suppliers/${supplierId}`, { method: "PUT", body: values, token });
}

/** ADR-046 decision 5 — the confirm warning itself lives in the caller (this is a plain toggle call). */
export function updateSupplierStatus(token: string, supplierId: number, isActive: boolean) {
  return apiFetch<Supplier>(`/api/middleware/suppliers/${supplierId}/status`, {
    method: "PATCH",
    body: { is_active: isActive },
    token,
  });
}

export function deleteSupplier(token: string, supplierId: number) {
  return apiFetch<null>(`/api/middleware/suppliers/${supplierId}`, { method: "DELETE", token });
}

export function refreshSupplierBalance(token: string, supplierId: number) {
  return apiFetch<Supplier>(`/api/middleware/suppliers/${supplierId}/refresh-balance`, { method: "POST", token });
}

export interface UpdateSupplierPackagesStatusValues {
  is_active: boolean;
  game_id?: number;
  reason?: string;
}

/** ADR-046 decisions 9/10 — "Deactivate All"/"Deactivate by Game" and their paired "Reactivate". */
export function updateSupplierPackagesStatus(
  token: string,
  supplierId: number,
  values: UpdateSupplierPackagesStatusValues,
) {
  return apiFetch<{ updated: number }>(`/api/middleware/suppliers/${supplierId}/packages/status`, {
    method: "PATCH",
    body: values,
    token,
  });
}
