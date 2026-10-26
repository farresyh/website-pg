"use client";

import Link from "next/link";
import type { LedgerEntry, Paginated } from "@/lib/portal";
import { dateAndTime, ledgerRow, signedRm } from "@/lib/format";
import { Badge, Pager, Skeleton } from "@/components/ui";

type Entry = LedgerEntry & { receipt_name?: string | null };

/**
 * Every movement in or out of a balance, newest first (ADR-128 Money): a
 * type badge, where it came from or went, the signed amount — a credit in
 * success-ink, a debit as ordinary spend in ink, never red — and the date.
 */
export default function LedgerTable({
  entries,
  onPage,
  empty,
}: {
  entries: Paginated<Entry> | null;
  onPage: (page: number) => void;
  empty: string;
}) {
  if (!entries) {
    return (
      <div className="space-y-2 p-5">
        {Array.from({ length: 4 }, (_, i) => (
          <Skeleton key={i} className="h-12" />
        ))}
      </div>
    );
  }
  if (entries.data.length === 0) return <p className="p-8 text-center text-sm text-ink-subtle">{empty}</p>;

  return (
    <>
      <ul className="divide-y divide-border md:hidden">
        {entries.data.map((entry) => {
          const row = ledgerRow(entry);
          const when = dateAndTime(entry.created_at);
          return (
            <li key={entry.id} className="flex items-start justify-between gap-3 px-4 py-3">
              <span className="flex min-w-0 flex-col items-start gap-1">
                <Badge tone={row.badge.tone}>{row.badge.label}</Badge>
                <Reference entry={entry} />
                <span className="max-w-full truncate text-xs text-ink-subtle">{row.detail}</span>
              </span>
              <span className="flex shrink-0 flex-col items-end gap-1">
                <Amount sen={entry.amount} />
                <span className="text-xs text-ink-subtle">
                  {when.date}, {when.time}
                </span>
              </span>
            </li>
          );
        })}
      </ul>
      <div className="relative hidden overflow-x-auto md:block">
        <table className="w-full border-collapse text-left">
          <thead>
            <tr className="bg-subtle text-xs font-semibold text-ink-subtle [&>th]:h-10 [&>th]:whitespace-nowrap [&>th]:border-b [&>th]:border-border [&>th]:px-3 [&>th:first-child]:pl-5 [&>th:last-child]:pr-5">
              <th>Type</th>
              <th>Reference / details</th>
              <th className="text-right">Amount</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            {entries.data.map((entry) => {
              const row = ledgerRow(entry);
              const when = dateAndTime(entry.created_at);
              return (
                <tr key={entry.id} className="border-b border-border text-[13px] last:border-b-0 hover:bg-subtle [&>td]:h-[60px] [&>td]:px-3 [&>td:first-child]:pl-5 [&>td:last-child]:pr-5">
                  <td>
                    <Badge tone={row.badge.tone}>{row.badge.label}</Badge>
                  </td>
                  <td className="max-w-[420px]">
                    <span className="flex min-w-0 flex-col">
                      <Reference entry={entry} />
                      <span className="truncate text-xs text-ink-subtle">{row.detail}</span>
                    </span>
                  </td>
                  <td className="text-right">
                    <Amount sen={entry.amount} />
                  </td>
                  <td className="whitespace-nowrap">
                    <span className="block text-[13px] font-medium text-ink-muted">{when.date}</span>
                    <span className="block text-xs text-ink-subtle">{when.time}</span>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      <Pager page={entries.current_page} lastPage={entries.last_page} total={entries.total} perPage={entries.per_page} onPage={onPage} />
    </>
  );
}

function Amount({ sen }: { sen: number }) {
  return <span className={`whitespace-nowrap text-sm font-semibold ${sen > 0 ? "text-success-ink" : "text-ink"}`}>{signedRm(sen)}</span>;
}

function Reference({ entry }: { entry: Entry }) {
  if (entry.order_number) {
    return (
      <Link href={`/orders/${entry.order_number}`} className="w-fit font-mono text-[13px] font-medium text-cyan-ink hover:underline">
        {entry.order_number}
      </Link>
    );
  }
  if (entry.receipt_name) return <span className="max-w-full truncate text-[13px] font-medium text-ink">Receipt: {entry.receipt_name}</span>;
  return null;
}
