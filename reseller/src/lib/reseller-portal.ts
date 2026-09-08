import { apiFetch } from "@/lib/api-client";
import type { LedgerEntry, Paginated } from "@/lib/portal";

/**
 * ADR-072 decision 5 / PR-G: the Reseller (wallet) portal's own
 * five-screen backend calls — Wallet (balance + top-up + ledger
 * history), Profile (view-only), API Keys (full self-service). Mirrors
 * `lib/portal.ts`'s role for the Affiliate side; kept in its own file
 * since the two account types' screens don't overlap beyond Orders
 * (which stays in `portal.ts`, parameterized by ownerType).
 */

export interface WalletResponse {
  balance: number;
  entries: Paginated<LedgerEntry & { receipt_name: string | null }>;
}

export function getWallet(token: string, page = 1) {
  return apiFetch<WalletResponse>(`/api/reseller-portal/wallet?page=${page}`, { token });
}

export interface TopupResponse {
  reference: string;
  checkout_url: string | null;
  amount_sen: number;
  total_charged_sen: number;
  expires_at: string;
}

export function topupWallet(token: string, amountSen: number, channelCode: string) {
  return apiFetch<TopupResponse>("/api/reseller-portal/wallet/topup", {
    method: "POST",
    token,
    body: { amount_sen: amountSen, channel_code: channelCode },
  });
}

export interface ResellerProfile {
  business_name: string;
  contact_name: string | null;
  email: string | null;
  phone: string | null;
  tier_name: string | null;
  is_active: boolean;
}

export function getResellerProfile(token: string) {
  return apiFetch<ResellerProfile>("/api/reseller-portal/profile", { token });
}

export interface ResellerApiKeyRow {
  id: number;
  name: string;
  last_used_at: string | null;
  revoked_at: string | null;
  created_at: string | null;
}

export function listApiKeys(token: string) {
  return apiFetch<ResellerApiKeyRow[]>("/api/reseller-portal/api-keys", { token });
}

export function issueApiKey(token: string, name: string) {
  return apiFetch<ResellerApiKeyRow & { plain_text_key: string }>("/api/reseller-portal/api-keys", {
    method: "POST",
    token,
    body: { name },
  });
}

export function revokeApiKey(token: string, id: number) {
  return apiFetch<void>(`/api/reseller-portal/api-keys/${id}`, { method: "DELETE", token });
}

export interface PaymentChannel {
  channel_code: string;
  label: string;
  category: string;
}

/** Public, no-auth — same catalog the storefront checkout reads. */
export function listPaymentChannels() {
  return apiFetch<PaymentChannel[]>("/api/payment-methods");
}
