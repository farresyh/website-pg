import { apiFetch, apiUpload, ApiError } from "@/lib/api-client";

/**
 * ADR-072/073 PR-B — admin Reseller (prepaid-wallet) account management:
 * register account, assign tier, activate/deactivate + the reseller_tiers
 * CRUD (ADR-073 decision 1). All endpoints are super_admin-only on the
 * backend. Distinct from `Affiliate` (@/lib/affiliates) — a `Reseller`
 * only ever spends against a deposited wallet balance, never earns. No
 * order-placing logic yet (PR-D), no API key issuance yet (PR-E), no
 * portal login yet (PR-G).
 */

export interface ResellerRow {
  id: number;
  business_name: string;
  contact_name: string | null;
  email: string | null;
  phone: string | null;
  reseller_tier_id: number | null;
  tier_name: string | null;
  markup_percent: string | null;
  is_active: boolean;
  notes: string | null;
  deleted_at: string | null;
  wallet_balance_sen: number;
}

export interface ResellerTier {
  id: number;
  name: string;
  markup_percent: string;
  is_active: boolean;
  sort_order: number;
  resellers_count: number;
}

export interface CreateResellerValues {
  business_name: string;
  contact_name: string | null;
  email: string | null;
  phone: string | null;
  reseller_tier_id: number | null;
  notes: string | null;
}

export type UpdateResellerValues = Omit<CreateResellerValues, "reseller_tier_id">;

export function listResellers(token: string) {
  return apiFetch<{ resellers: ResellerRow[] }>("/api/resellers", { token });
}

export function createReseller(token: string, values: CreateResellerValues) {
  return apiFetch<ResellerRow>("/api/resellers", { method: "POST", token, body: values });
}

export function updateReseller(token: string, id: number, values: UpdateResellerValues) {
  return apiFetch<ResellerRow>(`/api/resellers/${id}`, { method: "PUT", token, body: values });
}

export function updateResellerStatus(token: string, id: number, isActive: boolean) {
  return apiFetch<ResellerRow>(`/api/resellers/${id}/status`, {
    method: "PATCH",
    token,
    body: { is_active: isActive },
  });
}

export function assignResellerTier(token: string, id: number, resellerTierId: number) {
  return apiFetch<ResellerRow>(`/api/resellers/${id}/tier`, {
    method: "POST",
    token,
    body: { reseller_tier_id: resellerTierId },
  });
}

export function deleteReseller(token: string, id: number) {
  return apiFetch<{ message: string }>(`/api/resellers/${id}`, { method: "DELETE", token });
}

export function listResellerTiers(token: string) {
  return apiFetch<{ tiers: ResellerTier[] }>("/api/reseller-tiers", { token });
}

export interface ResellerTierValues {
  name: string;
  markup_percent: number;
  is_active: boolean;
  sort_order: number;
}

export function createResellerTier(token: string, values: ResellerTierValues) {
  return apiFetch<ResellerTier>("/api/reseller-tiers", { method: "POST", token, body: values });
}

export function updateResellerTier(token: string, id: number, values: Partial<ResellerTierValues>) {
  return apiFetch<ResellerTier>(`/api/reseller-tiers/${id}`, { method: "PUT", token, body: values });
}

export function deleteResellerTier(token: string, id: number) {
  return apiFetch<{ message: string }>(`/api/reseller-tiers/${id}`, { method: "DELETE", token });
}

/**
 * ADR-073 decision 3(b) (PR-C, re-scoped): admin manual-credit only.
 * Self-serve CHIP top-up is deferred to whichever PR first gives a
 * Reseller its own entry point to trigger it from (see the ADR-073
 * build addendum) — no client-facing checkout flow exists yet.
 */
export interface WalletLedgerEntry {
  id: number;
  type: string;
  amount: number;
  reference_type: string | null;
  reference_id: number | null;
  receipt_name: string | null;
  reason: string | null;
  created_at: string;
}

export interface WalletLedgerPage {
  data: WalletLedgerEntry[];
  current_page: number;
  last_page: number;
  total: number;
}

export interface ResellerWallet {
  balance_sen: number;
  entries: WalletLedgerPage;
}

export function getResellerWallet(token: string, id: number, page?: number) {
  const qs = page ? `?page=${page}` : "";

  return apiFetch<ResellerWallet>(`/api/resellers/${id}/wallet${qs}`, { token });
}

export interface CreditResellerWalletValues {
  amount_sen: number;
  note?: string | null;
  receipt?: File | null;
}

export function creditResellerWallet(token: string, id: number, values: CreditResellerWalletValues) {
  const formData = new FormData();
  formData.append("amount_sen", String(values.amount_sen));
  if (values.note) formData.append("note", values.note);
  if (values.receipt) formData.append("receipt", values.receipt);

  return apiUpload<{ balance_sen: number; entry: WalletLedgerEntry }>(
    `/api/resellers/${id}/wallet/credit`,
    formData,
    { token },
  );
}

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://backend.test";

/**
 * Bearer-token-gated route (`wallet_receipts_disk` is deliberately
 * private, ADR-073 decision 3(b)) — not a plain `<a href>`, same Blob +
 * object-URL pattern as `downloadBackupRun`.
 */
export async function downloadWalletTopupReceipt(token: string, receiptId: number, filename: string): Promise<void> {
  const response = await fetch(`${API_BASE_URL}/api/wallet-topup-receipts/${receiptId}/download`, {
    headers: { Authorization: `Bearer ${token}` },
  });

  if (!response.ok) {
    throw new ApiError(response.status, undefined, `Download failed (${response.status})`);
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
