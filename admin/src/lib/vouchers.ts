import { apiFetch } from "@/lib/api-client";

export type VoucherStatus = "active" | "exhausted" | "expired" | "revoked";

export interface Voucher {
  id: number;
  order_id: number | null;
  code: string;
  customer_email: string;
  amount: number;
  remaining: number;
  status: VoucherStatus;
  expires_at: string | null;
  reason: string;
  created_by: number;
  approved_by: number | null;
  created_at: string;
}

export interface VoucherStat {
  count: number;
  total: number;
}

export interface VoucherIndexResponse {
  stats: {
    active: VoucherStat;
    total_issued: VoucherStat;
    total_used: VoucherStat;
    expired: VoucherStat;
  };
  vouchers: Voucher[];
}

export interface CreateVoucherValues {
  customer_email: string;
  amount: number;
  reason: string;
  expires_at?: string | null;
}

export function listVouchers(token: string) {
  return apiFetch<VoucherIndexResponse>("/api/vouchers", { token });
}

export function createVoucher(token: string, values: CreateVoucherValues) {
  return apiFetch<Voucher>("/api/vouchers", { method: "POST", token, body: values });
}

export function revokeVoucher(token: string, id: number) {
  return apiFetch<Voucher>(`/api/vouchers/${id}/revoke`, { method: "PATCH", token });
}
