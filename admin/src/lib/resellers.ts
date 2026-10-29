import { apiFetch, apiUpload, apiDownload } from "@/lib/api-client";
import type { WhatsAppGroup } from "@/lib/whatsapp-groups";

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
  /** ADR-131 decision 4: this reseller's own cap, or null to use the platform default. */
  balance_cap_sen: number | null;
  effective_balance_cap_sen: number;
}

export interface ResellerTier {
  id: number;
  name: string;
  markup_percent: string;
  is_active: boolean;
  sort_order: number;
  /** ADR-091: opts this tier into the public Reseller Price List page — at most 3 tiers across the ladder. */
  show_on_price_list: boolean;
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

export function listResellers() {
  return apiFetch<{ resellers: ResellerRow[] }>("/api/resellers");
}

export function createReseller(values: CreateResellerValues) {
  return apiFetch<ResellerRow>("/api/resellers", { method: "POST", body: values });
}

export function updateReseller(id: number, values: UpdateResellerValues) {
  return apiFetch<ResellerRow>(`/api/resellers/${id}`, { method: "PUT", body: values });
}

export function updateResellerStatus(id: number, isActive: boolean) {
  return apiFetch<ResellerRow>(`/api/resellers/${id}/status`, {
    method: "PATCH",
    body: { is_active: isActive },
  });
}

export function assignResellerTier(id: number, resellerTierId: number) {
  return apiFetch<ResellerRow>(`/api/resellers/${id}/tier`, {
    method: "POST",
    body: { reseller_tier_id: resellerTierId },
  });
}

/** ADR-131 decision 4 — behind MFA step-up; api-client shows the prompt. `null` = platform default. */
export function setResellerBalanceCap(id: number, balanceCapSen: number | null) {
  return apiFetch<ResellerRow>(`/api/resellers/${id}/balance-cap`, { method: "PUT", body: { balance_cap_sen: balanceCapSen } });
}

/** ADR-131 decision 12: what a closure would refund and what still blocks it. No side effects. */
export interface ResellerCloseCheck {
  balance_sen: number;
  blockers: string[];
  can_close: boolean;
}

export function getResellerCloseCheck(id: number) {
  return apiFetch<ResellerCloseCheck>(`/api/resellers/${id}/close-check`);
}

/**
 * ADR-131 decisions 12-16 — the only way to remove a reseller (there is no DELETE). The refund
 * was sent by bank transfer first. Behind MFA step-up; api-client shows the prompt and retries.
 */
export function closeReseller(id: number, values: { transferred_on: string; bank_reference: string }) {
  return apiFetch<{ message: string; refunded_sen: number }>(`/api/resellers/${id}/close`, { method: "POST", body: values });
}

export function listResellerTiers() {
  return apiFetch<{ tiers: ResellerTier[] }>("/api/reseller-tiers");
}

export interface ResellerTierValues {
  name: string;
  markup_percent: number;
  is_active: boolean;
  sort_order: number;
  show_on_price_list: boolean;
}

export function createResellerTier(values: ResellerTierValues) {
  return apiFetch<ResellerTier>("/api/reseller-tiers", { method: "POST", body: values });
}

export function updateResellerTier(id: number, values: Partial<ResellerTierValues>) {
  return apiFetch<ResellerTier>(`/api/reseller-tiers/${id}`, { method: "PUT", body: values });
}

export function deleteResellerTier(id: number) {
  return apiFetch<{ message: string }>(`/api/reseller-tiers/${id}`, { method: "DELETE" });
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
  /** Only set when reference_type is "order" — the customer-facing order number, not the internal id. */
  order_number: string | null;
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

export function getResellerWallet(id: number, page?: number) {
  const qs = page ? `?page=${page}` : "";

  return apiFetch<ResellerWallet>(`/api/resellers/${id}/wallet${qs}`);
}

export interface CreditResellerWalletValues {
  amount_sen: number;
  note?: string | null;
  receipt?: File | null;
  /** One per opened credit form — a double-submit replays instead of crediting twice. */
  idempotency_key: string;
}

export function creditResellerWallet(id: number, values: CreditResellerWalletValues) {
  const formData = new FormData();
  formData.append("amount_sen", String(values.amount_sen));
  formData.append("idempotency_key", values.idempotency_key);
  if (values.note) formData.append("note", values.note);
  if (values.receipt) formData.append("receipt", values.receipt);

  return apiUpload<{ balance_sen: number; entry: WalletLedgerEntry }>(
    `/api/resellers/${id}/wallet/credit`,
    formData,
  );
}

/** ADR-074 decision 1: a Reseller API credential. `plain_text_key` only ever appears in issueResellerApiKey()'s own response. */
export interface ResellerApiKey {
  id: number;
  name: string;
  allowed_ips: string[];
  last_used_at: string | null;
  last_used_ip: string | null;
  revoked_at: string | null;
  created_at: string;
}

export function listResellerApiKeys(resellerId: number) {
  return apiFetch<ResellerApiKey[]>(`/api/resellers/${resellerId}/api-keys`);
}

export function issueResellerApiKey(resellerId: number, name: string) {
  return apiFetch<ResellerApiKey & { plain_text_key: string }>(`/api/resellers/${resellerId}/api-keys`, {
    method: "POST",
    body: { name },
  });
}

export function revokeResellerApiKey(resellerId: number, apiKeyId: number) {
  return apiFetch<void>(`/api/resellers/${resellerId}/api-keys/${apiKeyId}`, { method: "DELETE" });
}

/** ADR-084 PR-4: admin edits one key's IP allowlist for support (empty array = any IP). */
export function updateResellerApiKeyAllowedIps(resellerId: number, apiKeyId: number, allowedIps: string[]) {
  return apiFetch<ResellerApiKey>(`/api/resellers/${resellerId}/api-keys/${apiKeyId}`, {
    method: "PATCH",
    body: { allowed_ips: allowedIps },
  });
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

export function getResellerWebhook(resellerId: number) {
  return apiFetch<{ webhook: ResellerWebhook | null }>(`/api/resellers/${resellerId}/webhook`);
}

export function setResellerWebhook(resellerId: number, url: string) {
  return apiFetch<{ webhook: ResellerWebhook; secret: string | null }>(`/api/resellers/${resellerId}/webhook`, {
    method: "POST",
    body: { url },
  });
}

export function rotateResellerWebhookSecret(resellerId: number) {
  return apiFetch<{ secret: string }>(`/api/resellers/${resellerId}/webhook/rotate-secret`, { method: "POST" });
}

export function setResellerWebhookActive(resellerId: number, isActive: boolean) {
  return apiFetch<{ webhook: ResellerWebhook }>(`/api/resellers/${resellerId}/webhook/status`, {
    method: "PATCH",
    body: { is_active: isActive },
  });
}

export function deleteResellerWebhook(resellerId: number) {
  return apiFetch<void>(`/api/resellers/${resellerId}/webhook`, { method: "DELETE" });
}

export function listResellerWebhookDeliveries(resellerId: number) {
  return apiFetch<ResellerWebhookDeliveryPage>(`/api/resellers/${resellerId}/webhook/deliveries`);
}

export function listResellerWhatsAppGroups(resellerId: number) {
  return apiFetch<WhatsAppGroup[]>(`/api/resellers/${resellerId}/whatsapp-groups`);
}

export function linkResellerWhatsAppGroup(resellerId: number, whatsappGroupId: string) {
  return apiFetch<WhatsAppGroup>(`/api/resellers/${resellerId}/whatsapp-groups`, {
    method: "POST",
    body: { whatsapp_group_id: whatsappGroupId },
  });
}

export function updateResellerWhatsAppGroupStatus(resellerId: number, groupId: number, isActive: boolean) {
  return apiFetch<WhatsAppGroup>(`/api/resellers/${resellerId}/whatsapp-groups/${groupId}/status`, {
    method: "PATCH",
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

export function getResellerDetail(id: number) {
  return apiFetch<ResellerRow & { users: ResellerUserRow[] }>(`/api/resellers/${id}`);
}

export function addResellerUser(id: number, values: { name: string; email: string }) {
  return apiFetch<ResellerRow & { users: ResellerUserRow[] }>(`/api/resellers/${id}/users`, {
    method: "POST",
    body: values,
  });
}

export function resendResellerUserInvite(id: number, userId: number) {
  return apiFetch<{ message: string }>(`/api/resellers/${id}/users/${userId}/resend-invite`, {
    method: "POST",
  });
}


/**
 * Auth-gated route (`wallet_receipts_disk` is deliberately
 * private, ADR-073 decision 3(b)) — not a plain `<a href>`, same Blob +
 * object-URL pattern as `downloadBackupRun`.
 */
export async function downloadWalletTopupReceipt(receiptId: number, filename: string): Promise<void> {
  await apiDownload(`/api/wallet-topup-receipts/${receiptId}/download`, filename);
}
