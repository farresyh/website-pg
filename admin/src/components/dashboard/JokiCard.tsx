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
    <section aria-label="Joki" className="rounded-2xl border border-gray-200 bg-surface p-5 dark:border-gray-800">
      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-section-title font-semibold text-ink">Joki</h2>
        <Link href="/admin/joki" className="text-theme-xs font-medium text-cyan-ink hover:underline">Open Joki →</Link>
      </div>
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {ITEMS.map(({ key, label, urgent }) => {
          const hot = urgent && counts[key] > 0;
          return (
            <Link
              key={key}
              href={`/admin/joki?filter=${key}`}
              className={`relative rounded-2xl border p-4 transition-colors ${
                hot ? "border-purple-200 bg-purple-50" : "border-gray-200 bg-surface hover:border-gray-300 dark:border-gray-800 dark:hover:border-gray-700"
              }`}
            >
              {hot && <span aria-hidden="true" className="absolute right-4 top-4 h-2 w-2 rounded-full bg-purple-600" />}
              <p className={`text-overline font-medium uppercase tracking-wide ${hot ? "text-purple-ink" : "text-ink-muted"}`}>{label}</p>
              <p className={`mt-1 text-metric-lg font-semibold ${hot ? "text-purple-ink" : "text-ink"}`}>{counts[key]}</p>
            </Link>
          );
        })}
      </div>
    </section>
  );
}
