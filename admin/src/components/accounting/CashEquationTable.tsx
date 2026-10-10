"use client";

/**
 * ADR-083 2026-10-10 addendum, decision 13 — "where is the money" as of the
 * month's last KL day: what the company holds against what it already owes
 * or has set aside. A gap near zero means the records agree.
 */

import { formatRm } from "@/lib/budget-envelopes";
import type { CashPosition } from "@/lib/accounting-summary";

interface Props {
  position: CashPosition;
  cashAccountsSen: number;
}

export function cashGapSen(position: CashPosition, cashAccountsSen: number): number {
  const assets = Object.values(position.assets).reduce((a, b) => a + b, 0);
  const claims = Object.values(position.claims).reduce((a, b) => a + b, 0);
  return cashAccountsSen + assets - claims;
}

function Row({ label, sen }: { label: string; sen: number }) {
  return (
    <div className="flex justify-between gap-4 py-1 text-theme-sm text-gray-600 dark:text-gray-300">
      <span>{label}</span>
      <span className="font-mono text-gray-800 dark:text-white/90">{formatRm(sen)}</span>
    </div>
  );
}

export function CashEquationTable({ position, cashAccountsSen }: Props) {
  const { assets, claims } = position;
  const gap = cashGapSen(position, cashAccountsSen);
  const identityOff = position.envelope_identity_sen !== claims.envelopes_sen;

  return (
    <div className="space-y-3">
      <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
        <div>
          <p className="mb-1 text-theme-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">What the company holds</p>
          <Row label="Cash accounts (as entered at the close)" sen={cashAccountsSen} />
          <Row label="Supplier prepaid balance (month-end rate)" sen={assets.supplier_prepaid_sen} />
          <Row label={`CHIP paid, not settled yet (${position.chip_unsettled_count}, less RM1 fee each)`} sen={assets.chip_unsettled_net_sen} />
        </div>
        <div>
          <p className="mb-1 text-theme-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">What it owes or has set aside</p>
          <Row label="Envelopes (after this allocation)" sen={claims.envelopes_sen} />
          <Row label="Reseller wallet balances" sen={claims.reseller_wallets_sen} />
          <Row label="Affiliate earnings not withdrawn" sen={claims.affiliate_earnings_sen} />
          <Row label="Affiliate withdrawals approved, not paid" sen={claims.affiliate_withdrawals_unpaid_sen} />
          <Row label="Vouchers customers can still spend" sen={claims.vouchers_outstanding_sen} />
          <Row label="Orders paid, not delivered or compensated" sen={claims.orders_undelivered_sen} />
        </div>
      </div>
      <div
        className={`flex justify-between rounded-lg px-3 py-2 text-theme-sm font-medium ${
          Math.abs(gap) > 100
            ? "bg-warning-surface text-warning-ink"
            : "bg-success-surface text-success-ink"
        }`}
      >
        <span>Gap (holds − owes)</span>
        <span className="font-mono">{formatRm(gap)}</span>
      </div>
      {identityOff && (
        <p className="rounded-lg bg-danger-surface px-3 py-2 text-theme-xs text-danger-ink">
          Envelope check failed: by posting type the envelopes should hold {formatRm(position.envelope_identity_sen)}. A posting may be recorded under the wrong type.
        </p>
      )}
    </div>
  );
}
