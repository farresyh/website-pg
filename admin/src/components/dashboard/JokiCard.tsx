"use client";

import Link from "next/link";
import type { JokiCounts } from "@/lib/joki";

/**
 * ADR-126 decision 24 + F4: joki work on the Dashboard. The general Need
 * Action and stuck counts leave joki out; these four (JokiBoard's own
 * filters) are where it shows. Hidden while there is no joki work at all.
 */
const ITEMS: { key: keyof JokiCounts; label: string; urgent: boolean }[] = [
  { key: "waiting", label: "Waiting for a penjoki", urgent: true },
  { key: "in_progress", label: "In progress", urgent: false },
  { key: "stale", label: "Stale", urgent: true },
  { key: "needs_voucher", label: "Failed, needs voucher", urgent: true },
];

export function JokiCard({ counts }: { counts: JokiCounts }) {
  if (ITEMS.every(({ key }) => counts[key] === 0)) return null;

  return (
    <section aria-label="Joki" className="rounded-2xl border border-gray-200 bg-surface p-4 dark:border-gray-800">
      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-theme-sm font-semibold text-ink">Joki</h2>
        <Link href="/admin/joki" className="text-theme-xs text-cyan-ink hover:underline">Open Joki</Link>
      </div>
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {ITEMS.map(({ key, label, urgent }) => (
          <Link
            key={key}
            href={`/admin/joki?filter=${key}`}
            className="rounded-xl border border-gray-200 p-3 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]"
          >
            <p className={`text-xl font-semibold ${urgent && counts[key] > 0 ? "text-error-600 dark:text-error-400" : "text-ink"}`}>{counts[key]}</p>
            <p className="text-theme-xs text-ink-muted">{label}</p>
          </Link>
        ))}
      </div>
    </section>
  );
}
