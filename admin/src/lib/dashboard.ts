import { apiFetch } from "@/lib/api-client";
import type { AttentionItem } from "@/lib/dashboard-briefing";

/**
 * DASH-2 (ADR-045) system health, plus the ADR-104 2026-10-15 `attention`
 * block the Dashboard status strip reads. Mirrors
 * backend/app/Services/Dashboard/DashboardService.php; no calculation here.
 * The Dashboard's business figures come from `@/lib/reports` (one definition).
 */

/** ADR-083 decision 6 — null means drift_threshold isn't configured (not watched), never "in sync". */
export interface SupplierFundingDrift {
  ledger_balance: number;
  polled_balance: number;
  variance: number;
  threshold: number;
  is_drifted: boolean;
}

export interface DashboardHealthSupplier {
  id: number;
  name: string;
  slug: string;
  /** In this supplier's own currency — never assume MYR (Digiflazz is IDR). */
  balance: number;
  currency: string;
  /** Display-only conversion (CurrencyRateService, cached ~24h). Null when unavailable — never a hardcoded MYR label. */
  balance_myr_equivalent: number | null;
  /** ADR-069 — balance is below this supplier's api_config['low_balance_threshold']. */
  low_balance: boolean;
  drift: SupplierFundingDrift | null;
  circuit_state: "closed" | "open";
}

/**
 * 2026-09-15 addendum: the one place a supplier's own-currency balance
 * gets formatted for display — both /middleware's dashboard and
 * /admin's System Health render `DashboardHealthSupplier` (same
 * endpoint), and both used to either hardcode "RM" or show a bare
 * unlabeled number regardless of the real currency. Same IDR-has-no-
 * decimals convention `SupplierTransferModal`'s own `formatForeign()`
 * already uses, kept consistent rather than duplicated with drift.
 */
export function formatSupplierBalance(amount: number, currency: string): string {
  const decimals = currency === "IDR" ? 0 : 2;

  return `${currency} ${amount.toLocaleString("en-MY", { minimumFractionDigits: decimals, maximumFractionDigits: decimals })}`;
}

export function formatMyrEquivalent(amount: number | null): string | null {
  if (amount === null) return null;

  return `≈ RM ${amount.toFixed(2)}`;
}

export interface ScheduledTaskHealth {
  name: string;
  tier: "critical" | "routine";
  stale: boolean;
  last_ok_at: string | null;
  last_failed_at: string | null;
  last_error: string | null;
  last_runtime_seconds: number | null;
}

export interface DashboardHealth {
  /** ADR-104 2026-10-15 — the status strip: red/amber items count toward needs_action_count, neutral ones don't. */
  attention: { items: AttentionItem[]; needs_action_count: number; last_paid_at: string | null };
  suppliers: DashboardHealthSupplier[];
  suppliers_definition: string;
  /** PR-F build addendum decision 5 — last-known OpenWA session.status event, push- not poll-driven. Null = no event has ever arrived (not provisioned/linked), never assumed healthy. */
  openwa_session: { status: string; at: string } | null;
  openwa_cs_session: { status: string; at: string } | null;
  openwa_session_definition: string;
  stuck_orders: { value: number; definition: string };
  pending_payments: { value: number; definition: string };
  queue: { pending: number; failed: number; definition: string };
  /** ADR-122 — per-task scheduler heartbeat; a stale critical task also fails /api/health. */
  scheduled_tasks: { tasks: ScheduledTaskHealth[]; definition: string };
  /**
   * 2026-09-28 addendum — treasury/liquidity check: a reseller's wallet
   * top-up credits them immediately, but the CHIP cash behind it only
   * settles T+1/T+2. total_supplier_balance_myr_equivalent should stay
   * comfortably above reseller_wallet_liability_sen. No alert threshold
   * yet — side-by-side numbers only, revisit once real reseller volume
   * gives a real buffer to tune against.
   */
  reseller_wallet_liability_sen: number;
  total_supplier_balance_myr_equivalent: number;
  reseller_wallet_liability_definition: string;
}


export function getDashboardHealth() {
  return apiFetch<DashboardHealth>("/api/dashboard/health");
}
