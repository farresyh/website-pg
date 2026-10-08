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
  voucher_liability_issued_sen: number;
  /** Always the CURRENT balance, never scoped to the viewed period — see MonthlyAccountingSummaryService::resellerWalletBalance()'s doc comment. */
  reseller_wallet_balance_sen: number;
}

export function getMonthlyAccountingSummary(token: string, year: number, month: number) {
  return apiFetch<MonthlyAccountingSummary>(`/api/accounting/summary?year=${year}&month=${month}`, { token });
}
