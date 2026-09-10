import { apiFetch, apiUpload, ApiError } from "@/lib/api-client";

/**
 * ADR-072/073 PR-B — admin Reseller (prepaid-wallet) account management:
 * register account, assign tier, activate/deactivate + the reseller_tiers
 * CRUD (ADR-073 decision 1). All endpoints are super_admin-only on the
 * backend. Distinct from `Affiliate` (@/lib/affiliates) — a `Reseller`
 * only ever spends against a deposited wallet balance, never earns. No
 * order-placing logic yet (PR-D). API key issuance (PR-E) and portal
 * login (PR-G) shipped since — see the `ResellerApiKey`/`ResellerUserRow`
 * sections below.
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

/** ADR-074 decision 1: a Reseller API credential. `plain_text_key` only ever appears in issueResellerApiKey()'s own response. */
export interface ResellerApiKey {
  id: number;
  name: string;
  last_used_at: string | null;
  revoked_at: string | null;
  created_at: string;
}

export function listResellerApiKeys(token: string, resellerId: number) {
  return apiFetch<ResellerApiKey[]>(`/api/resellers/${resellerId}/api-keys`, { token });
}

export function issueResellerApiKey(token: string, resellerId: number, name: string) {
  return apiFetch<ResellerApiKey & { plain_text_key: string }>(`/api/resellers/${resellerId}/api-keys`, {
    method: "POST",
    token,
    body: { name },
  });
}

export function revokeResellerApiKey(token: string, resellerId: number, apiKeyId: number) {
  return apiFetch<void>(`/api/resellers/${resellerId}/api-keys/${apiKeyId}`, { method: "DELETE", token });
}

/**
 * ADR-084 PR-3 decision 4/10: a Reseller's single delivery-webhook
 * endpoint. `secret` is in a response ONLY when it was just generated
 * (first set, or a rotate) — never refetchable. The reseller
 * self-manages the same from the portal; admin has it for support.
 */
export interface ResellerWebhook {
  url: string;
  is_active: boolean;
  created_at: string | null;
  updated_at: string | null;
}

export interface ResellerWebhookDelivery {
  id: number;
  event: string;
  event_id: string;
  order_number: string | null;
  status: "pending" | "delivered" | "failed" | "exhausted";
  attempts: number;
  last_response_code: number | null;
  next_retry_at: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface ResellerWebhookDeliveryPage {
  data: ResellerWebhookDelivery[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export function getResellerWebhook(token: string, resellerId: number) {
  return apiFetch<{ webhook: ResellerWebhook | null }>(`/api/resellers/${resellerId}/webhook`, { token });
}

export function setResellerWebhook(token: string, resellerId: number, url: string) {
  return apiFetch<{ webhook: ResellerWebhook; secret: string | null }>(`/api/resellers/${resellerId}/webhook`, {
    method: "POST",
    token,
    body: { url },
  });
}

export function rotateResellerWebhookSecret(token: string, resellerId: number) {
  return apiFetch<{ secret: string }>(`/api/resellers/${resellerId}/webhook/rotate-secret`, { method: "POST", token });
}

export function setResellerWebhookActive(token: string, resellerId: number, isActive: boolean) {
  return apiFetch<{ webhook: ResellerWebhook }>(`/api/resellers/${resellerId}/webhook/status`, {
    method: "PATCH",
    token,
    body: { is_active: isActive },
  });
}

export function deleteResellerWebhook(token: string, resellerId: number) {
  return apiFetch<void>(`/api/resellers/${resellerId}/webhook`, { method: "DELETE", token });
}

export function listResellerWebhookDeliveries(token: string, resellerId: number) {
  return apiFetch<ResellerWebhookDeliveryPage>(`/api/resellers/${resellerId}/webhook/deliveries`, { token });
}

/**
 * ADR-075 / PR-F build addendum decision 3: the Reseller Bot channel's
 * group-linking UX. A pending row is platform-wide (a captured group
 * isn't yet attributed to any Reseller) — the admin picks one and links
 * it to a specific Reseller from that Reseller's own modal.
 */
export interface ResellerWhatsAppPendingLink {
  whatsapp_group_id: string;
  last_message_preview: string | null;
  last_message_at: string | null;
}

export interface ResellerWhatsAppGroup {
  id: number;
  whatsapp_group_id: string;
  is_active: boolean;
  created_at: string;
}

export function listPendingWhatsAppGroups(token: string) {
  return apiFetch<ResellerWhatsAppPendingLink[]>("/api/reseller-whatsapp-groups/pending", { token });
}

export function listResellerWhatsAppGroups(token: string, resellerId: number) {
  return apiFetch<ResellerWhatsAppGroup[]>(`/api/resellers/${resellerId}/whatsapp-groups`, { token });
}

export function linkResellerWhatsAppGroup(token: string, resellerId: number, whatsappGroupId: string) {
  return apiFetch<ResellerWhatsAppGroup>(`/api/resellers/${resellerId}/whatsapp-groups`, {
    method: "POST",
    token,
    body: { whatsapp_group_id: whatsappGroupId },
  });
}

export function updateResellerWhatsAppGroupStatus(token: string, resellerId: number, groupId: number, isActive: boolean) {
  return apiFetch<ResellerWhatsAppGroup>(`/api/resellers/${resellerId}/whatsapp-groups/${groupId}/status`, {
    method: "PATCH",
    token,
    body: { is_active: isActive },
  });
}

/**
 * PR-G: this Reseller's own portal login(s) (ADR-072 decision 5). Mirrors
 * `AffiliateUserRow`/`addAffiliateUser`/`resendAffiliateInvite` (@/lib/
 * affiliates) exactly — same `AffiliateUser` table underneath, distinguished
 * by `owner_type='reseller'`. `getResellerDetail()` is the one fetch that
 * carries `users` (the plain `ResellerRow` from `listResellers()` doesn't
 * eager-load it, per `Admin\ResellerController::index()`).
 */
export interface ResellerUserRow {
  id: number;
  name: string;
  email: string;
  is_active: boolean;
  last_login_at: string | null;
  invite_pending: boolean;
}

export function getResellerDetail(token: string, id: number) {
  return apiFetch<ResellerRow & { users: ResellerUserRow[] }>(`/api/resellers/${id}`, { token });
}

export function addResellerUser(token: string, id: number, values: { name: string; email: string }) {
  return apiFetch<ResellerRow & { users: ResellerUserRow[] }>(`/api/resellers/${id}/users`, {
    method: "POST",
    token,
    body: values,
  });
}

export function resendResellerUserInvite(token: string, id: number, userId: number) {
  return apiFetch<{ message: string }>(`/api/resellers/${id}/users/${userId}/resend-invite`, {
    method: "POST",
    token,
  });
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
