import { apiFetch, apiDownload } from "@/lib/api-client";
import type { Paginated } from "@/lib/payment-settlements";


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
  type:
    | "order"
    /** ADR-127: an order recognised in a later month than it was paid; its cost and net, dated when recognised. */
    | "order_recognised"
    /** ADR-083 2026-10-18 addendum: a manual correction to an order's platform profit, dated when made; `reference` is the order number. */
    | "order_profit_correction"
    | "supplier_transfer"
    | "supplier_refund"
    | "voucher_issued"
    | "supplier_adjustment"
    | "membership_payment"
    | "reseller_wallet_topup"
    | "reseller_wallet_refund"
    /** ADR-131 decision 17: a bank-transfer credit by an admin (no CHIP attempt behind it). */
    | "reseller_wallet_manual_credit"
    /** ADR-131 decision 17: the refund sent to a reseller whose account was closed. */
    | "reseller_wallet_closure"
    | "withdrawal_payout";
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
  /** 2026-09-30 audit fix: "chip" (real bank inflow) vs "reseller_wallet" (spend from an already-collected wallet balance, or a top-up itself) — lets the Gross column be filtered/grouped instead of naively summed across two different kinds of money. Null where the distinction doesn't apply. */
  funding_source: "chip" | "reseller_wallet" | null;
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
export function getTransactionRegister(filters: TransactionRegisterFilters = {}) {
  return apiFetch<Paginated<TransactionRegisterRow>>(`/api/accounting/transactions${buildQuery(filters)}`);
}

/** Auth-gated binary download — same fetch-as-blob-then-object-URL pattern as exportReport(). Deliberately unpaginated — a year-end export must cover the whole filtered range, not one page. */
export async function exportTransactionRegister(filters: TransactionRegisterFilters = {}): Promise<void> {
  await apiDownload(`/api/accounting/transactions/export${buildQuery({ from: filters.from, to: filters.to, type: filters.type })}`, "transaction-register.csv");
}
