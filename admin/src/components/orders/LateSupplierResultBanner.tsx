import type { OrderDetail } from "@/lib/orders";

/**
 * ADR-102 2026-11-15 addendum: a supplier result that arrived after this
 * order (or one of its combo legs) was final. Shown above the actions so the
 * admin checks the supplier before settling, resending or refunding.
 */
export default function LateSupplierResultBanner({ order }: { order: OrderDetail }) {
  const results = order.late_supplier_results ?? [];
  if (results.length === 0) return null;

  const supplierName = order.supplier?.name ?? "the supplier";

  return (
    <div className="mt-3 rounded-lg bg-danger-surface p-4 text-sm">
      <p className="font-medium text-danger-ink">The supplier reported a different result after this order was final.</p>
      <ul className="mt-2 space-y-1 text-ink-muted">
        {results.map((r) => (
          <li key={`${r.supplier_reference}-${r.outcome}`}>
            {r.leg_number !== null && <span className="font-medium text-ink">Leg {r.leg_number}: </span>}
            {r.outcome === "success"
              ? "reported success after it failed: the credit may already be with the player."
              : "reported failure after it was delivered: the player may not have the credit."}{" "}
            <span className="text-ink-subtle">
              ({r.supplier_reference}
              {r.created_at && `, ${new Date(r.created_at).toLocaleString()}`})
            </span>
          </li>
        ))}
      </ul>
      <p className="mt-2 text-ink-muted">
        Check {supplierName}&apos;s transaction history and the customer before any refund, resend or settlement, then
        record the matching manual adjustment in the supplier ledger.
      </p>
    </div>
  );
}
