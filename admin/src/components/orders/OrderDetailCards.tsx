/**
 * ADR-018 decision #8: extracted from the original admin/orders/page.tsx
 * so /admin/orders and /middleware/sandbox render an identical order
 * detail view instead of two independently-maintained copies drifting
 * apart over time.
 */
import { Tag } from "@/components/ui/tag";
import { User } from "@primeicons/react/user";
import { Box } from "@primeicons/react/box";
import { Tag as TagIcon } from "@primeicons/react/tag";
import { CreditCard } from "@primeicons/react/credit-card";
import type { OrderDetail } from "@/lib/orders";

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

export default function OrderDetailCards({ order }: { order: OrderDetail }) {
  const isMemberOrder = order.pricing_basis === "member";
  // ADR-074/075: a wallet order (Reseller API/Bot) never has a real
  // transaction fee (no CHIP round-trip) or voucher (refund-to-wallet
  // replaces it entirely, ADR-073 decision 7), and affiliate_profit is
  // always 0 by construction — showing those rows as blank/RM0.00 reads
  // as "something's missing," not "not applicable here." The reseller's
  // own tier markup isn't snapshotted as its own column, but it's
  // exactly derivable from the two prices that already are.
  const isWalletOrder = order.pricing_basis === "reseller-wallet";
  const isAffiliateWholesale = order.pricing_basis === "affiliate";
  // ADR-111 addendum — reads the same reconciled figure the Cost Price
  // card itself shows below, not the raw catalog `cost_price`, so this
  // derived % stays consistent with whichever basis actually produced
  // the stored platform_profit.
  const resellerMarkupPct = isWalletOrder && order.effective_cost_price > 0
    ? (((order.selling_price - order.effective_cost_price) / order.effective_cost_price) * 100).toFixed(2)
    : null;
  const costBasisTags = {
    real: { label: "Real", severity: "success" as const },
    mixed: { label: "Mixed", severity: "warn" as const },
    estimated: { label: "Estimated", severity: "secondary" as const },
  };
  const costBasisTag = costBasisTags[order.cost_basis];

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
      <div className="rounded-2xl border border-gray-200 bg-surface p-6 dark:border-gray-800">
        <h2 className="mb-4 flex items-center gap-2 text-section-title font-semibold text-ink"><User className="h-4 w-4 text-ink-muted" />Customer Details</h2>
        {/* ADR-104 D1 fix: every <dd> here now carries an explicit
            `text-ink` color — unstyled, it silently inherited the
            browser's plain-black default (no dark-mode override exists
            for "no class at all"), which is invisible-contrast on a
            dark card. Confirmed via getComputedStyle before this fix:
            color was literally rgb(0,0,0) on a rgb(26,25,23) card. Pure
            color-token addition — no layout/label/data/function change. */}
        <dl className="space-y-2 text-sm">
          <div className="flex justify-between"><dt className="text-ink-muted">Email</dt><dd className="text-ink">{order.customer_email}</dd></div>
          <div className="flex justify-between"><dt className="text-ink-muted">Phone</dt><dd className="text-ink">{order.customer_phone ?? "—"}</dd></div>
          <div className="flex justify-between"><dt className="text-ink-muted">Player ID</dt><dd className="text-ink">{order.player_id}</dd></div>
          <div className="flex justify-between"><dt className="text-ink-muted">Server/Zone ID</dt><dd className="text-ink">{order.server_id ?? "—"}</dd></div>
        </dl>
      </div>

      {/* ADR-104: "Game & fulfillment" — merges the old "Game / Package"
          card's fields under the artifact's own card boundary/heading.
          Every field is the same one that card already showed; `channel`
          is newly surfaced here but derived from `wallet_reseller`
          (already fetched, already used elsewhere on this page to tell
          a Reseller-channel order apart from a Direct one) — no new data. */}
      <div className="rounded-2xl border border-gray-200 bg-surface p-6 dark:border-gray-800">
        <h2 className="mb-4 flex items-center gap-2 text-section-title font-semibold text-ink"><Box className="h-4 w-4 text-ink-muted" />Game & fulfillment</h2>
        {/* ADR-104 D1 fix: same explicit `text-ink` addition as Customer
            Details above — same confirmed rgb(0,0,0)-on-dark-card bug. */}
        <dl className="space-y-2 text-sm">
          <div className="flex justify-between"><dt className="text-ink-muted">Game</dt><dd className="text-ink">{order.game?.name ?? "—"}</dd></div>
          <div className="flex justify-between"><dt className="text-ink-muted">Package</dt><dd className="text-ink">{order.package?.name ?? "—"}</dd></div>
          <div className="flex justify-between">
            <dt className="text-ink-muted">Product Code (SKU)</dt>
            <dd className="font-mono text-xs font-medium text-ink">
              {order.supplier_product_ref ?? order.package?.supplier_package_ref ?? "—"}
            </dd>
          </div>
          <div className="flex justify-between"><dt className="text-ink-muted">Supplier</dt><dd className="text-ink">{order.supplier?.name ?? "—"}</dd></div>
          <div className="flex justify-between"><dt className="text-ink-muted">Channel</dt><dd className="text-ink">{order.wallet_reseller ? "Reseller" : "Direct"}</dd></div>
          <div className="flex justify-between"><dt className="text-ink-muted">Affiliate</dt><dd className="text-ink">{order.affiliate?.business_name ?? "—"}</dd></div>
          {/* ADR-074/075: only set for an order placed via the Reseller API/Bot channel — the platform's own primary affiliate above stays the storefront brand either way. */}
          {order.wallet_reseller && (
            <div className="flex justify-between"><dt className="text-ink-muted">Reseller (wallet)</dt><dd className="text-ink">{order.wallet_reseller.business_name}</dd></div>
          )}
        </dl>
      </div>

      <div className="rounded-2xl border border-gray-200 bg-surface p-6 dark:border-gray-800">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="flex items-center gap-2 text-section-title font-semibold text-ink"><TagIcon className="h-4 w-4 text-ink-muted" />Pricing Details</h2>
          <div className="flex items-center gap-1.5">
            {isMemberOrder && (
              <Tag severity="info">
                Member{order.membership ? `: ${order.membership.membership_plan.name}` : ""}
              </Tag>
            )}
            {isWalletOrder && <Tag severity="warn">Reseller Wallet</Tag>}
            {isAffiliateWholesale && <Tag severity="warn">Wholesale Tier</Tag>}
            {!isMemberOrder && !isWalletOrder && !isAffiliateWholesale && <Tag severity="secondary">Standard</Tag>}
          </div>
        </div>
        <dl className="space-y-2.5 text-sm">
          <div className="flex justify-between items-center">
            <dt className="text-ink-muted">Cost Price</dt>
            <dd className="flex items-center gap-1.5 font-mono text-ink">
              {formatRm(order.effective_cost_price)}
              {/* ADR-111 addendum — 'estimated' is today's ordinary state
                  (not yet delivered, or real-cost reconciliation is off),
                  so it stays unlabeled to avoid a permanent gray badge on
                  every row; 'real'/'mixed' are the notable states worth
                  a tag. */}
              {order.cost_basis !== "estimated" && (
                <Tag severity={costBasisTag.severity}>{costBasisTag.label}</Tag>
              )}
            </dd>
          </div>
          {/* Standard Selling Price is the storefront's own guest/list price — meaningless for a wallet order, which is never priced off it (the reseller's own tier markup is, shown below instead). */}
          {!isWalletOrder && (
            <div className="flex justify-between items-center">
              <dt className="text-ink-muted">Standard Selling Price</dt>
              <dd className="font-medium text-ink">{formatRm(order.standard_selling_price)}</dd>
            </div>
          )}
          {/* ADR-104 correction (re-verified live against the artifact for
              PR-2's table/detail work): purple on Orders pages is reserved
              for urgency ("need action"), not profit — that's a Reports-
              chart-specific convention (Revenue=cyan/Owner profit=purple).
              The artifact's own OrderDetailFailed mockup shows "Owner
              profit" in plain neutral text, not purple. Reverted from an
              earlier, incorrect purple-everywhere reading of decision 3. */}
          {isWalletOrder && resellerMarkupPct !== null && (
            <div className="flex justify-between items-center">
              <dt className="text-ink-muted">Reseller Markup</dt>
              <dd className="font-semibold text-ink">+{resellerMarkupPct}%</dd>
            </div>
          )}
          {isAffiliateWholesale && order.wholesale_markup_pct && (
            <div className="flex justify-between items-center">
              <dt className="text-ink-muted">Wholesale Tier Markup</dt>
              <dd className="font-semibold text-ink">+{order.wholesale_markup_pct}%</dd>
            </div>
          )}
          {isMemberOrder && order.normal_selling_price !== null && (
            <div className="rounded-lg bg-info-surface p-3 space-y-1.5 text-xs">
              <div className="flex justify-between text-ink-muted">
                <span>Normal Price (Non-Member)</span>
                <span className="line-through text-ink-muted">{formatRm(order.normal_selling_price)}</span>
              </div>
              <div className="flex justify-between text-info-ink font-medium">
                <span>Member Discount</span>
                <span>-{order.member_discount_percent}%</span>
              </div>
              <div className="flex justify-between text-info-ink font-medium">
                <span>Margin Given Up</span>
                <span>-{formatRm(order.normal_selling_price - order.selling_price)}</span>
              </div>
              {order.membership && (
                <div className="pt-1 text-theme-xs text-info-ink/80 border-t border-cyan-600/20 flex justify-between">
                  <span>Account</span>
                  <span className="truncate max-w-[200px]">{order.membership.email}</span>
                </div>
              )}
            </div>
          )}
          <div className="flex justify-between items-center">
            <dt className="text-ink-muted">Selling Price</dt>
            <dd className="font-medium text-ink">{formatRm(order.selling_price)}</dd>
          </div>
          {/* Neither ever applies to a wallet order — no voucher path (Refund to Wallet replaces it, ADR-073 decision 7) and no CHIP round-trip (debit is instant). Always 0/null there, not just usually. */}
          {!isWalletOrder && (
            <>
              <div className="flex justify-between items-center">
                <dt className="text-ink-muted">Voucher Discount</dt>
                <dd className={order.voucher_discount ? "font-medium text-success-ink" : "text-ink-muted"}>
                  {order.voucher_discount ? `-${formatRm(order.voucher_discount)}` : "—"}
                </dd>
              </div>
              {/* A cost, not a status — neutral, not warning (that token is
                  reserved for something needing attention). */}
              <div className="flex justify-between items-center">
                <dt className="text-ink-muted">Transaction Fee</dt>
                <dd className={order.transaction_fee > 0 ? "text-neutral-ink text-xs font-mono" : "text-ink-muted"}>
                  {order.transaction_fee > 0 ? `+${formatRm(order.transaction_fee)}` : "—"}
                </dd>
              </div>
            </>
          )}

          <div className="border-t border-gray-100 pt-1.5 dark:border-gray-800" />

          <div className="flex justify-between items-center py-0.5">
            <dt className="font-semibold text-ink text-base">Final Amount</dt>
            <dd className="font-bold text-ink text-base font-mono">{formatRm(order.final_amount)}</dd>
          </div>

          <div className="border-t border-gray-100 pt-1.5 dark:border-gray-800" />

          {/* ADR-104 correction — same reasoning as the markup rows above:
              purple is Reports' profit-chart convention, not an Orders one.
              Plain neutral text here, matching the artifact's own
              OrderDetailFailed mockup ("Owner profit" in neutral ink). */}
          {/* ADR-105 decision 4/8: a manual admin resend can now record a
              genuine loss (override_reason-gated), so platform_profit is
              no longer always non-negative — the old hardcoded "+"
              prefix would have rendered "+RM -1.00" (a double sign) the
              first time that happened. A loss reads in the same error
              tone the resend guard itself uses, not the neutral tone a
              normal profit gets. */}
          <div className="flex justify-between items-center">
            <dt className="text-ink-muted text-xs">Platform Profit</dt>
            <dd className={`font-semibold font-mono ${order.platform_profit < 0 ? "text-error-600 dark:text-error-400" : "text-ink"}`}>
              {order.platform_profit < 0 ? formatRm(order.platform_profit) : `+${formatRm(order.platform_profit)}`}
            </dd>
          </div>
          {/* Always 0 by construction for a wallet order (no affiliate markup layered on top, ADR-073 decision 1) — Platform Profit above already carries the whole margin, so this row would only ever read as a dead zero here. */}
          {!isWalletOrder && (
            <div className="flex justify-between items-center">
              <dt className="text-ink-muted text-xs">Affiliate Profit</dt>
              <dd className={order.affiliate_profit > 0 ? "font-semibold font-mono text-ink" : "font-mono text-ink-muted"}>
                {order.affiliate_profit > 0 ? `+${formatRm(order.affiliate_profit)}` : formatRm(0)}
              </dd>
            </div>
          )}
        </dl>
      </div>

      {/* ADR-104: "Payment & supplier" gains a "Fulfilment" sub-section —
          Supplier/Supplier Ref moved here (still the same two fields the
          old "Payment / Supplier" card already showed), plus a structured
          "Latest supplier result" reading the same `order.supplier_response`
          object other components already parse the same keys from
          (`error_code`/`error_message`, see NeedsReviewBanner.tsx) — no
          new data, just this card's own presentation of it instead of an
          always-open raw dump. The raw JSON itself is kept, just behind a
          native <details> disclosure instead of permanently visible. */}
      <div className="rounded-2xl border border-gray-200 bg-surface p-6 dark:border-gray-800">
        <h2 className="mb-4 flex items-center gap-2 text-section-title font-semibold text-ink"><CreditCard className="h-4 w-4 text-ink-muted" />Payment & supplier</h2>
        {/* ADR-104 D1 fix: same explicit `text-ink` addition as the cards
            above — same confirmed rgb(0,0,0)-on-dark-card bug. */}
        <dl className="space-y-2 text-sm">
          <div className="flex justify-between"><dt className="text-ink-muted">Payment Method</dt><dd className="text-ink">{order.payment_method ?? "—"}</dd></div>
          <div className="flex justify-between"><dt className="text-ink-muted">Payment Ref (CHIP)</dt><dd className="text-ink">{order.payment_ref ?? "—"}</dd></div>
          <div className="flex justify-between"><dt className="text-ink-muted">Reference # (ORD-8)</dt><dd className="font-mono text-code-id text-ink">{order.reference_number ?? "—"}</dd></div>
        </dl>

        <p className="mb-2 mt-5 text-theme-xs font-medium uppercase tracking-wide text-ink-muted">Fulfilment</p>
        <dl className="space-y-2 text-sm">
          <div className="flex justify-between"><dt className="text-ink-muted">Supplier</dt><dd className="text-ink">{order.supplier?.name ?? "—"}</dd></div>
          <div className="flex justify-between"><dt className="text-ink-muted">Supplier Ref</dt><dd className="text-ink">{order.supplier_ref ?? "—"}</dd></div>
        </dl>

        {order.supplier_response && (() => {
          const response = order.supplier_response as Record<string, unknown>;
          const errorCode = response.error_code as string | undefined;
          const errorMessage = response.error_message as string | undefined;
          const note = response.note as string | undefined;
          const confirmedAt = response.confirmed_at as string | undefined;
          const confirmedBy = response.confirmed_failed_by as string | undefined;

          return (
            <div className="mt-4 rounded-lg border border-gray-200 bg-subtle p-3 dark:border-gray-800">
              <div className="flex items-center justify-between">
                <p className="text-sm font-medium text-ink">Latest supplier result</p>
                {order.delivery_status === "failed" && (
                  <span className="inline-flex items-center gap-1.5 text-sm font-medium text-danger-ink">
                    <span aria-hidden="true" className="h-1.5 w-1.5 shrink-0 rounded-full bg-current" />
                    Failed
                  </span>
                )}
              </div>
              {(errorCode || errorMessage || note || confirmedAt) && (
                <dl className="mt-2 space-y-1.5 text-sm">
                  {errorCode && <div className="flex justify-between"><dt className="text-ink-muted">Error code</dt><dd className="font-mono text-ink">{errorCode}</dd></div>}
                  {errorMessage && <div className="flex justify-between"><dt className="text-ink-muted">Message</dt><dd className="text-ink">{errorMessage}</dd></div>}
                  {note && <div className="flex justify-between"><dt className="text-ink-muted">Note</dt><dd className="text-ink">{note}</dd></div>}
                  {confirmedAt && (
                    <div className="flex justify-between">
                      <dt className="text-ink-muted">Confirmed</dt>
                      <dd className="text-ink">{new Date(confirmedAt).toLocaleString()}{confirmedBy ? ` by ${confirmedBy}` : ""}</dd>
                    </div>
                  )}
                </dl>
              )}
              <details className="mt-2">
                <summary className="cursor-pointer text-theme-xs text-ink-muted">Raw supplier response</summary>
                <pre className="mt-1 overflow-x-auto rounded-lg bg-subtle p-3 text-theme-xs text-ink-muted">
                  {JSON.stringify(order.supplier_response, null, 2)}
                </pre>
              </details>
            </div>
          );
        })()}
      </div>
    </div>
  );
}
