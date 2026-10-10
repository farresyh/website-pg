import { apiFetch } from "@/lib/api-client";

/** ADR-083 decision 8, fills ADR-110 PR-B — Monthly Accounting Summary journal lines, one period at a time. */
export interface MonthlyAccountingSummary {
  sales_revenue_sen: number;
  membership_revenue_sen: number;
  cogs_sen: number;
  payment_processing_gain_loss_sen: number;
  supplier_prepaid_topup_sen: number;
  bank_transfer_fees_sen: number;
  supplier_prepaid_fx_variance_sen: number;
  affiliate_commission_expense_sen: number;
  affiliate_tier_fees_sen: number;
  voucher_breakage_sen: number;
  goodwill_vouchers_issued_sen: number;
  supplier_manual_adjustments_sen: number;
  voucher_liability_issued_sen: number;
  /** Always the CURRENT balance, never scoped to the viewed period — see MonthlyAccountingSummaryService::resellerWalletBalance()'s doc comment. */
  reseller_wallet_balance_sen: number;
  /** Information only: already taken out of their envelopes, never subtracted from operating profit. */
  envelope_manual_expenses_sen: number;
  /** ADR-083 2026-10-10 addendum, decision 11 — what the month close allocates. */
  operating_profit_sen: number;
}

export function getMonthlyAccountingSummary(token: string, year: number, month: number) {
  return apiFetch<MonthlyAccountingSummary>(`/api/accounting/summary?year=${year}&month=${month}`, { token });
}

/** ADR-083 2026-10-10 addendum, decision 13 — every figure except the cash accounts the founder types in. */
export interface CashPosition {
  assets: { supplier_prepaid_sen: number; chip_unsettled_net_sen: number };
  claims: {
    envelopes_sen: number;
    reseller_wallets_sen: number;
    affiliate_earnings_sen: number;
    affiliate_withdrawals_unpaid_sen: number;
    vouchers_outstanding_sen: number;
    orders_undelivered_sen: number;
  };
  /** What the envelopes should hold, from posting headers; differs from claims.envelopes_sen only if a posting is booked wrongly. */
  envelope_identity_sen: number;
  chip_unsettled_count: number;
  /** Present on a closed month's snapshot. */
  cash_accounts_sen?: number;
  gap_sen?: number;
}

export interface CashBalance {
  cash_account_id: number;
  name: string;
  balance_sen: number;
}

/** ADR-083 2026-10-10 addendum, decisions 9–14 — one month's close, or its preview. */
export interface MonthClose {
  period: string;
  label: string;
  /** Why this month can't be closed now; null when it can, or when it is closed. */
  blocked_reason: string | null;
  operating_profit_sen: number;
  prior_adjustment_sen: number;
  allocate_sen: number;
  envelope_expenses: { category: string; label: string; amount_sen: number }[];
  net_sen: number;
  position: CashPosition;
  close: {
    id: number;
    operating_profit_sen: number;
    drift_sen: number;
    cash_balances: CashBalance[];
    gap_sen: number;
    gap_note: string | null;
    closed_by: string | null;
    closed_at: string;
    can_reopen: boolean;
  } | null;
  envelopes: { id: number; name: string }[];
  cash_accounts: { id: number; name: string }[];
}

export interface CloseMonthValues {
  year: number;
  month: number;
  lines: { budget_envelope_id: number; amount_sen: number }[];
  cash_balances: { cash_account_id: number; balance_sen: number }[];
  gap_note: string | null;
}

export function getMonthClose(token: string, year: number, month: number) {
  return apiFetch<MonthClose>(`/api/accounting/month-close?year=${year}&month=${month}`, { token });
}

export function closeMonth(token: string, values: CloseMonthValues) {
  return apiFetch<{ close: { id: number } }>("/api/accounting/month-close", { method: "POST", token, body: values });
}

export function reopenMonthClose(token: string, closeId: number, reason: string) {
  return apiFetch<{ close: { id: number } }>(`/api/accounting/month-close/${closeId}/reopen`, { method: "POST", token, body: { reason } });
}

export function createCashAccount(token: string, name: string) {
  return apiFetch<{ cash_account: { id: number } }>("/api/accounting/cash-accounts", { method: "POST", token, body: { name } });
}

export function updateCashAccount(token: string, id: number, values: { name?: string; is_active?: boolean }) {
  return apiFetch<{ cash_account: { id: number } }>(`/api/accounting/cash-accounts/${id}`, { method: "PATCH", token, body: values });
}
