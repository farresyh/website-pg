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

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
      <div className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
        <h2 className="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">Customer</h2>
        <dl className="space-y-2 text-sm">
          <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Email</dt><dd>{order.customer_email}</dd></div>
          <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Phone</dt><dd>{order.customer_phone ?? "—"}</dd></div>
          <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Player ID</dt><dd>{order.player_id}</dd></div>
          <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Server/Zone ID</dt><dd>{order.server_id ?? "—"}</dd></div>
        </dl>
      </div>

      <div className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
        <h2 className="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">Game / Package</h2>
        <dl className="space-y-2 text-sm">
          <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Game</dt><dd>{order.game?.name ?? "—"}</dd></div>
          <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Package</dt><dd>{order.package?.name ?? "—"}</dd></div>
          <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Supplier</dt><dd>{order.supplier?.name ?? "—"}</dd></div>
          <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Affiliate</dt><dd>{order.affiliate?.business_name ?? "—"}</dd></div>
          {/* ADR-074/075: only set for an order placed via the Reseller API/Bot channel — the platform's own primary affiliate above stays the storefront brand either way. */}
          {order.wallet_reseller && (
            <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Reseller (wallet)</dt><dd>{order.wallet_reseller.business_name}</dd></div>
          )}
        </dl>
      </div>

      <div className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="mb-4 flex items-center gap-2">
          <h2 className="text-sm font-semibold text-gray-800 dark:text-white/90">Pricing (ORD-9, server-computed)</h2>
          {isMemberOrder && <Tag severity="info">Member</Tag>}
        </div>
        <dl className="space-y-2 text-sm">
          <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Cost Price</dt><dd>{formatRm(order.cost_price)}</dd></div>
          <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Standard Selling Price</dt><dd>{formatRm(order.standard_selling_price)}</dd></div>
          {isMemberOrder && order.normal_selling_price !== null && (
            <>
              {order.membership && (
                <>
                  <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Membership Tier</dt><dd>{order.membership.membership_plan.name}</dd></div>
                  <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Member Email</dt><dd>{order.membership.email}</dd></div>
                </>
              )}
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Normal Price (non-member)</dt><dd>{formatRm(order.normal_selling_price)}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Member Discount</dt><dd>{order.member_discount_percent}%</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Margin Given Up to Member</dt><dd>{formatRm(order.normal_selling_price - order.selling_price)}</dd></div>
            </>
          )}
          <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Selling Price</dt><dd>{formatRm(order.selling_price)}</dd></div>
          <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Voucher Discount</dt><dd>{order.voucher_discount ? formatRm(order.voucher_discount) : "—"}</dd></div>
          <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Transaction Fee</dt><dd>{formatRm(order.transaction_fee)}</dd></div>
          <div className="flex justify-between font-medium text-gray-800 dark:text-white/90"><dt>Final Amount</dt><dd>{formatRm(order.final_amount)}</dd></div>
          <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Platform Profit</dt><dd>{formatRm(order.platform_profit)}</dd></div>
          <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Affiliate Profit</dt><dd>{formatRm(order.affiliate_profit)}</dd></div>
        </dl>
      </div>

      <div className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
        <h2 className="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">Payment / Supplier</h2>
        <dl className="space-y-2 text-sm">
          <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Payment Method</dt><dd>{order.payment_method ?? "—"}</dd></div>
          <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Payment Ref (CHIP)</dt><dd>{order.payment_ref ?? "—"}</dd></div>
          <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Reference # (ORD-8)</dt><dd>{order.reference_number ?? "—"}</dd></div>
          <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Supplier Ref</dt><dd>{order.supplier_ref ?? "—"}</dd></div>
        </dl>
        {order.supplier_response && (
          <>
            <p className="mt-4 mb-1 text-theme-xs text-gray-400">Supplier response</p>
            <pre className="overflow-x-auto rounded-lg bg-gray-100 p-3 text-theme-xs text-gray-600 dark:bg-white/5 dark:text-gray-400">
              {JSON.stringify(order.supplier_response, null, 2)}
            </pre>
          </>
        )}
      </div>
    </div>
  );
}
