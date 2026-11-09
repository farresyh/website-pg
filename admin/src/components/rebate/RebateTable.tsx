"use client";

/**
 * The rebate screens' tables, on the components/ui DataTable kit with the
 * /admin/orders cell spacing (ADR-038, the style benchmark). One wrapper so
 * every rebate table has the same card, header row and empty state.
 */

import type { ReactNode } from "react";
import {
  DataTable,
  DataTableCell,
  DataTableRow,
  DataTableTable,
  DataTableTableContainer,
  DataTableTBody,
  DataTableTHead,
  DataTableTHeadCell,
  DataTableTHeadRow,
} from "@/components/ui/datatable";

export const TH = "px-5 py-3 text-start text-theme-xs font-medium text-ink-muted";
export const TD = "px-5 py-4 text-theme-sm text-ink-muted";
export const TD_STRONG = "px-5 py-4 text-theme-sm font-medium text-ink";

interface Props<T extends { id: number | string }> {
  title?: ReactNode;
  headers: ReactNode[];
  rows: T[];
  empty: string;
  /** The cells of one row, in header order: each a [className, content] pair. */
  cells: (row: T) => [string, ReactNode][];
}

export function RebateTable<T extends { id: number | string }>({ title, headers, rows, empty, cells }: Props<T>) {
  return (
    <section className="mb-6 overflow-hidden rounded-2xl border border-gray-200 bg-surface dark:border-gray-800">
      {title && <div className="border-b border-gray-100 px-5 py-3.5 dark:border-gray-800">{title}</div>}
      <div className="max-w-full overflow-x-auto">
        <DataTable data={rows as unknown as Record<string, unknown>[]} dataKey="id">
          <DataTableTableContainer>
            <DataTableTable>
              <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                <DataTableTHeadRow>
                  {headers.map((h, i) => (
                    <DataTableTHeadCell key={i} className={TH}>{h}</DataTableTHeadCell>
                  ))}
                </DataTableTHeadRow>
              </DataTableTHead>
              <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                {({ item }) => {
                  const row = item as unknown as T;
                  return (
                    <DataTableRow key={row.id}>
                      {cells(row).map(([className, content], i) => (
                        <DataTableCell key={i} className={className}>{content}</DataTableCell>
                      ))}
                    </DataTableRow>
                  );
                }}
              </DataTableTBody>
            </DataTableTable>
          </DataTableTableContainer>
        </DataTable>
        {rows.length === 0 && <p className="p-6 text-center text-sm text-ink-muted">{empty}</p>}
      </div>
    </section>
  );
}
