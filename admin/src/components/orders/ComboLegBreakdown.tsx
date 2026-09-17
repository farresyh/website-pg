/**
 * ADR-094 decision 12: the admin detail screen's precision for a
 * combo order — empty for every ordinary single-supplier order
 * (`delivery_legs` is empty there), one row per real outbound
 * supplier call for a combo one. The two per-supplier reference
 * numbers a combo order produces are exactly what admin needs to
 * manually check against the supplier's own dashboard when something
 * looks wrong — the tracking page customers see stays untouched
 * (decision 12's own "zero new customer-facing states").
 */
import {
  DataTable,
  DataTableTableContainer,
  DataTableTable,
  DataTableTHead,
  DataTableTHeadRow,
  DataTableTHeadCell,
  DataTableTBody,
  DataTableRow,
  DataTableCell,
} from "@/components/ui/datatable";
import { Tag } from "@/components/ui/tag";
import type { OrderDeliveryLeg } from "@/lib/orders";

function severityFor(status: OrderDeliveryLeg["status"]): "success" | "danger" | "warn" | "info" | "review" {
  switch (status) {
    case "delivered":
      return "success";
    case "failed":
      return "danger";
    // ADR-104 decision 3 — the artifact's own distinct review token,
    // same reasoning as the order-level delivery_status map.
    case "needs_review":
      return "review";
    case "pending":
      return "warn";
    default:
      return "info";
  }
}

export default function ComboLegBreakdown({ legs }: { legs: OrderDeliveryLeg[] }) {
  if (legs.length === 0) return null;

  return (
    <div className="mt-6 overflow-hidden rounded-2xl border border-gray-200 bg-surface dark:border-gray-800">
      <div className="border-b border-gray-100 p-6 pb-4 dark:border-gray-800">
        <h2 className="text-section-title font-semibold text-ink">Combo Delivery Legs</h2>
        <p className="mt-1 text-xs text-ink-muted">
          This order is a combo package — one real supplier call per component.
        </p>
      </div>

      <div className="max-w-full overflow-x-auto p-6 pt-2">
        <DataTable data={legs} dataKey="id">
          <DataTableTableContainer>
            <DataTableTable>
              <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                <DataTableTHeadRow>
                  <DataTableTHeadCell className="px-3 py-2 text-start text-theme-xs font-medium text-ink-muted">Leg</DataTableTHeadCell>
                  <DataTableTHeadCell className="px-3 py-2 text-start text-theme-xs font-medium text-ink-muted">Component</DataTableTHeadCell>
                  <DataTableTHeadCell className="px-3 py-2 text-start text-theme-xs font-medium text-ink-muted">Supplier</DataTableTHeadCell>
                  <DataTableTHeadCell className="px-3 py-2 text-start text-theme-xs font-medium text-ink-muted">Status</DataTableTHeadCell>
                  <DataTableTHeadCell className="px-3 py-2 text-start text-theme-xs font-medium text-ink-muted">Supplier Ref</DataTableTHeadCell>
                  <DataTableTHeadCell className="px-3 py-2 text-start text-theme-xs font-medium text-ink-muted">Failure Reason</DataTableTHeadCell>
                </DataTableTHeadRow>
              </DataTableTHead>
              <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                {({ item }) => {
                  const leg = item as unknown as OrderDeliveryLeg;

                  return (
                    <DataTableRow key={leg.id}>
                      <DataTableCell className="px-3 py-3 text-theme-sm text-ink-muted">#{leg.leg_number}</DataTableCell>
                      <DataTableCell className="px-3 py-3 text-theme-sm">
                        <span className="font-medium text-ink">{leg.component_package?.name ?? "—"}</span>
                        {leg.component_package?.denomination !== null && leg.component_package?.denomination !== undefined && (
                          <span className="ml-1 text-theme-xs text-gray-400">({leg.component_package.denomination})</span>
                        )}
                        {/* 2026-09-16 addendum: the product SKU actually submitted for this leg — distinct from the "Supplier Ref" column, which is the supplier's own transaction/response id, not the product code. Needed when two components share a denomination across suppliers. */}
                        {leg.component_package?.supplier_package_ref && (
                          <div className="font-mono text-theme-xs text-gray-400">{leg.component_package.supplier_package_ref}</div>
                        )}
                      </DataTableCell>
                      <DataTableCell className="px-3 py-3 text-theme-sm text-ink-muted">{leg.supplier?.name ?? "—"}</DataTableCell>
                      <DataTableCell className="px-3 py-3 text-theme-sm">
                        <Tag dot severity={severityFor(leg.status)}>{leg.status}</Tag>
                      </DataTableCell>
                      <DataTableCell className="px-3 py-3 font-mono text-theme-xs text-ink-muted">{leg.supplier_reference ?? "—"}</DataTableCell>
                      <DataTableCell className="px-3 py-3 text-theme-xs text-ink-muted max-w-xs truncate" title={leg.failure_reason ?? undefined}>
                        {leg.failure_reason ?? "—"}
                      </DataTableCell>
                    </DataTableRow>
                  );
                }}
              </DataTableTBody>
            </DataTableTable>
          </DataTableTableContainer>
        </DataTable>
      </div>
    </div>
  );
}
