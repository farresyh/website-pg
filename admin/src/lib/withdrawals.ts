import { apiFetch } from "@/lib/api-client";

export type WithdrawalStatus = "pending" | "approved" | "rejected" | "completed";

export interface Withdrawal {
  id: number;
  amount: number;
  bank_name: string;
  bank_account_no: string;
  bank_account_holder: string;
  status: WithdrawalStatus;
  admin_note: string | null;
  requested_by: number;
  approved_by: number | null;
  processed_at: string | null;
  created_at: string;
}

export interface WithdrawalStats {
  count: number;
  total: number;
}

export interface WithdrawalIndexResponse {
  stats: Record<WithdrawalStatus, WithdrawalStats>;
  available_balance: number;
  withdrawals: Withdrawal[];
}

export interface WithdrawalRequestValues {
  amount: number;
  bank_name: string;
  bank_account_no: string;
  bank_account_holder: string;
}

export function listWithdrawals(token: string) {
  return apiFetch<WithdrawalIndexResponse>("/api/withdrawals", { token });
}

export function requestWithdrawal(token: string, values: WithdrawalRequestValues) {
  return apiFetch<Withdrawal>("/api/withdrawals", {
    method: "POST",
    token,
    body: values,
  });
}

export function approveWithdrawal(token: string, id: number) {
  return apiFetch<Withdrawal>(`/api/withdrawals/${id}/approve`, { method: "PATCH", token });
}

export function rejectWithdrawal(token: string, id: number) {
  return apiFetch<Withdrawal>(`/api/withdrawals/${id}/reject`, { method: "PATCH", token });
}

export function completeWithdrawal(token: string, id: number) {
  return apiFetch<Withdrawal>(`/api/withdrawals/${id}/complete`, { method: "PATCH", token });
}
