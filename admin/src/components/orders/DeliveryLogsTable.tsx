/**
 * ADR-018 decision #8: extracted from the original admin/orders/page.tsx —
 * see OrderDetailCards.tsx for the same reasoning.
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
import type { OrderResendAttempt } from "@/lib/orders";

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

export default function DeliveryLogsTable({ attempts }: { attempts: OrderResendAttempt[] }) {
  if (attempts.length === 0) return null;

  return (
    <div className="mt-6 overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
      <h2 className="p-6 pb-0 text-sm font-semibold text-gray-800 dark:text-white/90">Delivery Logs</h2>
      <div className="max-w-full overflow-x-auto p-6 pt-4">
        <DataTable data={attempts} dataKey="id">
          <DataTableTableContainer>
            <DataTableTable>
              <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                <DataTableTHeadRow>
                  <DataTableTHeadCell className="px-3 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Date</DataTableTHeadCell>
                  <DataTableTHeadCell className="px-3 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Package</DataTableTHeadCell>
                  <DataTableTHeadCell className="px-3 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Price Diff</DataTableTHeadCell>
                  <DataTableTHeadCell className="px-3 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Outcome</DataTableTHeadCell>
                  <DataTableTHeadCell className="px-3 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Triggered By</DataTableTHeadCell>
                  <DataTableTHeadCell className="px-3 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Note</DataTableTHeadCell>
                </DataTableTHeadRow>
              </DataTableTHead>
              <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                {({ item }) => {
                  const attempt = item as unknown as OrderResendAttempt;

                  return (
                    <DataTableRow key={attempt.id}>
                      <DataTableCell className="px-3 py-3 text-theme-xs text-gray-500 dark:text-gray-400">
                        {new Date(attempt.created_at).toLocaleString()}
                      </DataTableCell>
                      <DataTableCell className="px-3 py-3 text-theme-sm text-gray-800 dark:text-white/90">
                        {attempt.package?.name ?? "—"}
                      </DataTableCell>
                      <DataTableCell className="px-3 py-3 text-theme-sm">
                        <span className={attempt.price_diff_sen > 0 ? "text-error-600 dark:text-error-400" : attempt.price_diff_sen < 0 ? "text-success-600 dark:text-success-400" : "text-gray-500 dark:text-gray-400"}>
                          {attempt.price_diff_sen > 0 ? "+" : ""}
                          {formatRm(attempt.price_diff_sen)}
                        </span>
                      </DataTableCell>
                      <DataTableCell className="px-3 py-3 text-theme-sm">
                        <Tag severity={attempt.outcome === "success" ? "success" : "danger"}>{attempt.outcome}</Tag>
                      </DataTableCell>
                      <DataTableCell className="px-3 py-3 text-theme-xs text-gray-500 dark:text-gray-400">{attempt.triggered_by ?? "—"}</DataTableCell>
                      <DataTableCell className="px-3 py-3 text-theme-xs text-gray-500 dark:text-gray-400">{attempt.note ?? "—"}</DataTableCell>
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
