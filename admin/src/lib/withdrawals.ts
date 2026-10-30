import { apiFetch } from "@/lib/api-client";

export type WithdrawalStatus = "pending" | "approved" | "rejected" | "completed";

export interface Withdrawal {
  id: number;
  owner_type: "platform" | "affiliate";
  /** ADR-132: who the ticket is for; null on a platform withdrawal. */
  affiliate: { id: number; name: string | null } | null;
  /** ADR-132: `YYYY-MM` of the monthly payout that opened it; null on a manual-era ticket. */
  payout_period: string | null;
  amount: number;
  bank_name: string;
  bank_account_no: string;
  bank_account_holder: string;
  status: WithdrawalStatus;
  admin_note: string | null;
  requested_by: number | null;
  approved_by: number | null;
  processed_at: string | null;
  created_at: string;
  /**
   * ADR-059 addendum, 2026-09-26: true when this withdrawal's bank details
   * differ from the affiliate's last admin-approved/completed payout —
   * `null` for a platform withdrawal or an affiliate's first-ever one
   * (no prior approval to compare against).
   */
  bank_details_changed_since_last_approval: boolean | null;
}

export interface WithdrawalStats {
  count: number;
  total: number;
}

export interface WithdrawalIndexResponse {
  stats: Record<WithdrawalStatus, WithdrawalStats>;
  withdrawals: Withdrawal[];
}

export type PayoutSkip = "no_bank" | "below_min" | "open_ticket";

/** ADR-132 decision 10: the admin worklist beyond the tickets themselves. */
export interface PayoutRunResponse {
  period: string;
  skipped: { affiliate_id: number; name: string; balance: number; reserve: number; amount: number; skip: PayoutSkip }[];
  stale_tickets: { id: number; affiliate_id: number; name: string | null; amount: number; payout_period: string }[];
}

export function getPayoutRun() {
  return apiFetch<PayoutRunResponse>("/api/withdrawals/payout-run");
}

export function listWithdrawals() {
  return apiFetch<WithdrawalIndexResponse>("/api/withdrawals");
}

export function approveWithdrawal(id: number) {
  return apiFetch<Withdrawal>(`/api/withdrawals/${id}/approve`, { method: "PATCH" });
}

export function rejectWithdrawal(id: number) {
  return apiFetch<Withdrawal>(`/api/withdrawals/${id}/reject`, { method: "PATCH" });
}

export function completeWithdrawal(id: number) {
  return apiFetch<Withdrawal>(`/api/withdrawals/${id}/complete`, { method: "PATCH" });
}
