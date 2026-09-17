/**
 * ADR-018 decision #8: extracted from the original admin/orders/page.tsx
 * so /admin/orders and /middleware/sandbox render an identical order
 * detail view instead of two independently-maintained copies drifting
 * apart over time.
 */
import { Tag } from "@/components/ui/tag";
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
  const resellerMarkupPct = isWalletOrder && order.cost_price > 0
    ? (((order.selling_price - order.cost_price) / order.cost_price) * 100).toFixed(2)
    : null;

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
      <div className="rounded-2xl border border-gray-200 bg-surface p-6 dark:border-gray-800">
        <h2 className="mb-4 text-section-title font-semibold text-ink">Customer Details</h2>
        <dl className="space-y-2 text-sm">
          <div className="flex justify-between"><dt className="text-ink-muted">Email</dt><dd>{order.customer_email}</dd></div>
          <div className="flex justify-between"><dt className="text-ink-muted">Phone</dt><dd>{order.customer_phone ?? "—"}</dd></div>
          <div className="flex justify-between"><dt className="text-ink-muted">Player ID</dt><dd>{order.player_id}</dd></div>
          <div className="flex justify-between"><dt className="text-ink-muted">Server/Zone ID</dt><dd>{order.server_id ?? "—"}</dd></div>
        </dl>
      </div>

      <div className="rounded-2xl border border-gray-200 bg-surface p-6 dark:border-gray-800">
        <h2 className="mb-4 text-section-title font-semibold text-ink">Game / Package</h2>
        <dl className="space-y-2 text-sm">
          <div className="flex justify-between"><dt className="text-ink-muted">Game</dt><dd>{order.game?.name ?? "—"}</dd></div>
          <div className="flex justify-between"><dt className="text-ink-muted">Package</dt><dd>{order.package?.name ?? "—"}</dd></div>
          <div className="flex justify-between">
            <dt className="text-ink-muted">Product Code (SKU)</dt>
            <dd className="font-mono text-xs font-medium text-ink">
              {order.supplier_product_ref ?? order.package?.supplier_package_ref ?? "—"}
            </dd>
          </div>
          <div className="flex justify-between"><dt className="text-ink-muted">Supplier</dt><dd>{order.supplier?.name ?? "—"}</dd></div>
          <div className="flex justify-between"><dt className="text-ink-muted">Affiliate</dt><dd>{order.affiliate?.business_name ?? "—"}</dd></div>
          {/* ADR-074/075: only set for an order placed via the Reseller API/Bot channel — the platform's own primary affiliate above stays the storefront brand either way. */}
          {order.wallet_reseller && (
            <div className="flex justify-between"><dt className="text-ink-muted">Reseller (wallet)</dt><dd>{order.wallet_reseller.business_name}</dd></div>
          )}
        </dl>
      </div>

      <div className="rounded-2xl border border-gray-200 bg-surface p-6 dark:border-gray-800">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-section-title font-semibold text-ink">Pricing Details</h2>
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
            <dd className="font-mono text-ink">{formatRm(order.cost_price)}</dd>
          </div>
          {/* Standard Selling Price is the storefront's own guest/list price — meaningless for a wallet order, which is never priced off it (the reseller's own tier markup is, shown below instead). */}
          {!isWalletOrder && (
            <div className="flex justify-between items-center">
              <dt className="text-ink-muted">Standard Selling Price</dt>
              <dd className="font-medium text-ink">{formatRm(order.standard_selling_price)}</dd>
            </div>
          )}
          {/* ADR-104 decision 3: purple = profit, everywhere — a markup% is
              the platform's own take, same semantic as Platform/Affiliate
              Profit below, so it gets the same token, not amber. */}
          {isWalletOrder && resellerMarkupPct !== null && (
            <div className="flex justify-between items-center">
              <dt className="text-ink-muted">Reseller Markup</dt>
              <dd className="font-semibold text-purple-ink">+{resellerMarkupPct}%</dd>
            </div>
          )}
          {isAffiliateWholesale && order.wholesale_markup_pct && (
            <div className="flex justify-between items-center">
              <dt className="text-ink-muted">Wholesale Tier Markup</dt>
              <dd className="font-semibold text-purple-ink">+{order.wholesale_markup_pct}%</dd>
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

          {/* ADR-104 decision 3: purple = "this needs you" / profit,
              specifically — Platform/Affiliate Profit are the platform's
              own margin, the same semantic the Reports revenue-vs-profit
              charts use purple for. */}
          <div className="flex justify-between items-center">
            <dt className="text-ink-muted text-xs">Platform Profit</dt>
            <dd className="font-semibold font-mono text-purple-ink">
              +{formatRm(order.platform_profit)}
            </dd>
          </div>
          {/* Always 0 by construction for a wallet order (no affiliate markup layered on top, ADR-073 decision 1) — Platform Profit above already carries the whole margin, so this row would only ever read as a dead zero here. */}
          {!isWalletOrder && (
            <div className="flex justify-between items-center">
              <dt className="text-ink-muted text-xs">Affiliate Profit</dt>
              <dd className={order.affiliate_profit > 0 ? "font-semibold font-mono text-purple-ink" : "font-mono text-ink-muted"}>
                {order.affiliate_profit > 0 ? `+${formatRm(order.affiliate_profit)}` : formatRm(0)}
              </dd>
            </div>
          )}
        </dl>
      </div>

      <div className="rounded-2xl border border-gray-200 bg-surface p-6 dark:border-gray-800">
        <h2 className="mb-4 text-section-title font-semibold text-ink">Payment / Supplier</h2>
        <dl className="space-y-2 text-sm">
          <div className="flex justify-between"><dt className="text-ink-muted">Payment Method</dt><dd>{order.payment_method ?? "—"}</dd></div>
          <div className="flex justify-between"><dt className="text-ink-muted">Payment Ref (CHIP)</dt><dd>{order.payment_ref ?? "—"}</dd></div>
          <div className="flex justify-between"><dt className="text-ink-muted">Reference # (ORD-8)</dt><dd className="font-mono text-code-id">{order.reference_number ?? "—"}</dd></div>
          <div className="flex justify-between"><dt className="text-ink-muted">Supplier Ref</dt><dd>{order.supplier_ref ?? "—"}</dd></div>
        </dl>
        {order.supplier_response && (
          <>
            <p className="mt-4 mb-1 text-theme-xs text-gray-400">Supplier response</p>
            <pre className="overflow-x-auto rounded-lg bg-subtle p-3 text-theme-xs text-ink-muted">
              {JSON.stringify(order.supplier_response, null, 2)}
            </pre>
          </>
        )}
      </div>
    </div>
  );
}
