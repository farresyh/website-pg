import { apiFetch, ApiError } from "@/lib/api-client";

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://backend.test";

/**
 * ADR-083 decision 9 — the Transaction Register: one row per
 * money-moving event across four otherwise-separate sources (paid
 * orders, supplier funding transfers, supplier REFUND entries, Path-B
 * vouchers). Every row carries every column; unused ones are null for
 * that row's `type` rather than forcing a lossy common unit (an order's
 * gross/fee/cost/net is MYR sen, a transfer's amount is a foreign-
 * currency decimal string).
 */
export interface TransactionRegisterRow {
  date: string;
  type: "order" | "supplier_transfer" | "supplier_refund" | "voucher_issued";
  reference: string;
  description: string;
  supplier: string | null;
  currency: string;
  gross_sen: number | null;
  fee_sen: number | null;
  cost_sen: number | null;
  net_sen: number | null;
  amount_foreign: string | null;
}

export interface TransactionRegisterFilters {
  from?: string; // Y-m-d
  to?: string; // Y-m-d
}

function buildQuery(filters: TransactionRegisterFilters): string {
  const params = new URLSearchParams();
  if (filters.from) params.set("from", filters.from);
  if (filters.to) params.set("to", filters.to);
  const qs = params.toString();

  return qs ? `?${qs}` : "";
}

export function getTransactionRegister(token: string, filters: TransactionRegisterFilters = {}) {
  return apiFetch<{ rows: TransactionRegisterRow[] }>(`/api/accounting/transactions${buildQuery(filters)}`, { token });
}

/** Bearer-token-gated binary download — same fetch-as-blob-then-object-URL pattern as exportReport(). */
export async function exportTransactionRegister(token: string, filters: TransactionRegisterFilters = {}): Promise<void> {
  const response = await fetch(`${API_BASE_URL}/api/accounting/transactions/export${buildQuery(filters)}`, {
    headers: { Authorization: `Bearer ${token}` },
  });

  if (!response.ok) {
    throw new ApiError(response.status, undefined, `Export failed (${response.status})`);
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = "transaction-register.csv";
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
