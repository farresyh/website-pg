"use client";

/**
 * ADR-033 addendum decision 1/2/4: every FX rate ever fetched, across
 * any currency pair, paginated newest-first — independent of any one
 * sync run (that's the "Rate used" line in Last Sync Details instead,
 * page.tsx's own concern). Extracted from page.tsx, same reasoning
 * PendingPriceChangeSection/SyncDetailsModal were already split out
 * for.
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
import { Button } from "@/components/ui/button";
import type { CurrencyRate, CurrencyRatePage } from "@/lib/price-sync";

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
      <h2 className="mb-3 text-section-title font-semibold text-ink">FX Rate History</h2>
      <div className="overflow-hidden rounded-2xl border border-border bg-surface">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={page?.data ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-border">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Pair</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Rate</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Source</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Fetched</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-border">
                  {({ item }) => {
                    const r = item as unknown as CurrencyRate;

                    return (
                      <DataTableRow key={r.id}>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-ink">
                          {r.from} → {r.to}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-ink-muted">{r.rate}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-ink-muted">{r.source}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-ink-muted">{formatDateTime(r.fetched_at)}</DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>
          {page?.data.length === 0 && (
            <p className="p-6 text-center text-sm text-ink-muted">
              No FX rate fetched yet — this fills in once a non-MYR supplier&apos;s price sync runs for real.
            </p>
          )}
          {page === null && (
            <p className="p-6 text-center text-sm text-ink-muted">Loading…</p>
          )}
        </div>
      </div>
      {page && page.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-sm text-ink-muted">
          <span>Page {page.current_page} of {page.last_page} ({page.total} total)</span>
          <div className="flex gap-2">
            <Button size="small" variant="outlined" disabled={page.current_page <= 1} onClick={onPrevious}>
              Previous
            </Button>
            <Button size="small" variant="outlined" disabled={page.current_page >= page.last_page} onClick={onNext}>
              Next
            </Button>
          </div>
        </div>
      )}
    </>
  );
}
