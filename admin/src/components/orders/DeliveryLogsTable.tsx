/**
 * ADR-018 decision #8: extracted from the original admin/orders/page.tsx —
 * see OrderDetailCards.tsx for the same reasoning.
 */
import { Table, TableHeader, TableBody, TableRow, TableCell } from "@/components/ui/table";
import Badge from "@/components/ui/badge/Badge";
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
        <Table>
          <TableHeader className="border-b border-gray-100 dark:border-gray-800">
            <TableRow>
              <TableCell isHeader className="px-3 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Date</TableCell>
              <TableCell isHeader className="px-3 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Package</TableCell>
              <TableCell isHeader className="px-3 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Price Diff</TableCell>
              <TableCell isHeader className="px-3 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Outcome</TableCell>
              <TableCell isHeader className="px-3 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Triggered By</TableCell>
              <TableCell isHeader className="px-3 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Note</TableCell>
            </TableRow>
          </TableHeader>
          <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
            {attempts.map((attempt) => (
              <TableRow key={attempt.id}>
                <TableCell className="px-3 py-3 text-theme-xs text-gray-500 dark:text-gray-400">
                  {new Date(attempt.created_at).toLocaleString()}
                </TableCell>
                <TableCell className="px-3 py-3 text-theme-sm text-gray-800 dark:text-white/90">
                  {attempt.package?.name ?? "—"}
                </TableCell>
                <TableCell className="px-3 py-3 text-theme-sm">
                  <span className={attempt.price_diff_sen > 0 ? "text-error-600 dark:text-error-400" : attempt.price_diff_sen < 0 ? "text-success-600 dark:text-success-400" : "text-gray-500 dark:text-gray-400"}>
                    {attempt.price_diff_sen > 0 ? "+" : ""}
                    {formatRm(attempt.price_diff_sen)}
                  </span>
                </TableCell>
                <TableCell className="px-3 py-3 text-theme-sm">
                  <Badge size="sm" color={attempt.outcome === "success" ? "success" : "error"}>{attempt.outcome}</Badge>
                </TableCell>
                <TableCell className="px-3 py-3 text-theme-xs text-gray-500 dark:text-gray-400">{attempt.triggered_by ?? "—"}</TableCell>
                <TableCell className="px-3 py-3 text-theme-xs text-gray-500 dark:text-gray-400">{attempt.note ?? "—"}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </div>
  );
}
