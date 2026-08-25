"use client";

/**
 * ADR-033 addendum decision 1/2/4: every FX rate ever fetched, across
 * any currency pair, paginated newest-first — independent of any one
 * sync run (that's the "Rate used" line in Last Sync Details instead,
 * page.tsx's own concern). Extracted from page.tsx, same reasoning
 * PendingPriceChangeSection/SyncDetailsModal were already split out
 * for.
 */

import { Table, TableHeader, TableBody, TableRow, TableCell } from "@/components/ui/table";
import Button from "@/components/ui/button/Button";
import type { CurrencyRatePage } from "@/lib/price-sync";

function formatDateTime(value: string): string {
  return new Date(value).toLocaleString();
}

interface Props {
  page: CurrencyRatePage | null;
  onPrevious: () => void;
  onNext: () => void;
}

export default function FxRateHistorySection({ page, onPrevious, onNext }: Props) {
  return (
    <>
      <h2 className="mb-3 text-base font-semibold text-gray-800 dark:text-white/90">FX Rate History</h2>
      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <Table>
            <TableHeader className="border-b border-gray-100 dark:border-gray-800">
              <TableRow>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Pair</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Rate</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Source</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Fetched</TableCell>
              </TableRow>
            </TableHeader>
            <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
              {page?.data.map((r) => (
                <TableRow key={r.id}>
                  <TableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                    {r.from} → {r.to}
                  </TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{r.rate}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{r.source}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{formatDateTime(r.fetched_at)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
          {page?.data.length === 0 && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">
              No FX rate fetched yet — this fills in once a non-MYR supplier&apos;s price sync runs for real.
            </p>
          )}
          {page === null && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>
          )}
        </div>
      </div>
      {page && page.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
          <span>Page {page.current_page} of {page.last_page} ({page.total} total)</span>
          <div className="flex gap-2">
            <Button size="sm" variant="outline" disabled={page.current_page <= 1} onClick={onPrevious}>
              Previous
            </Button>
            <Button size="sm" variant="outline" disabled={page.current_page >= page.last_page} onClick={onNext}>
              Next
            </Button>
          </div>
        </div>
      )}
    </>
  );
}
