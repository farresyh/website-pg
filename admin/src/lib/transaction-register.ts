import { apiFetch, ApiError } from "@/lib/api-client";
import type { Paginated } from "@/lib/payment-settlements";

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://backend.test";

/**
 * ADR-083 decision 9 (+ 2026-09-28 addendum) — the Transaction Register:
 * one row per money-moving event across five otherwise-separate sources
 * (paid orders, supplier funding transfers, supplier REFUND entries,
 * Path-B vouchers, and — new — manual/void corrections against a
 * supplier transfer). Every row carries every column; unused ones are
 * null for that row's `type` rather than forcing a lossy common unit.
 * `status` is new — a `supplier_transfer` row can be `voided` (its
 * figures stay the real originally-recorded ones, never zeroed; the
 * reversal shows as its own `supplier_adjustment` row).
 */
export interface TransactionRegisterRow {
  date: string;
  type: "order" | "supplier_transfer" | "supplier_refund" | "voucher_issued" | "supplier_adjustment";
  reference: string;
  description: string;
  supplier: string | null;
  currency: string;
  gross_sen: number | null;
  fee_sen: number | null;
  cost_sen: number | null;
  net_sen: number | null;
  amount_foreign: string | null;
  status: "active" | "voided";
}

export interface TransactionRegisterFilters {
  from?: string; // Y-m-d
  to?: string; // Y-m-d
  type?: TransactionRegisterRow["type"];
  page?: number;
  per_page?: number;
}

function buildQuery(filters: TransactionRegisterFilters): string {
  const params = new URLSearchParams();
  if (filters.from) params.set("from", filters.from);
  if (filters.to) params.set("to", filters.to);
  if (filters.type) params.set("type", filters.type);
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  const qs = params.toString();

  return qs ? `?${qs}` : "";
}

/** 2026-09-28 addendum — real backend pagination (was every matching row, unbounded). */
export function getTransactionRegister(token: string, filters: TransactionRegisterFilters = {}) {
  return apiFetch<Paginated<TransactionRegisterRow>>(`/api/accounting/transactions${buildQuery(filters)}`, { token });
}

/** Bearer-token-gated binary download — same fetch-as-blob-then-object-URL pattern as exportReport(). Deliberately unpaginated — a year-end export must cover the whole filtered range, not one page. */
export async function exportTransactionRegister(token: string, filters: TransactionRegisterFilters = {}): Promise<void> {
  const response = await fetch(`${API_BASE_URL}/api/accounting/transactions/export${buildQuery({ from: filters.from, to: filters.to, type: filters.type })}`, {
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
