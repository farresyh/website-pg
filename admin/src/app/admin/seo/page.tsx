"use client";

/**
 * ADR-029 decision 4 / addendum 2 decision 17 — a computed checklist +
 * Recommendations panel, never a weighted score (explicitly rejected).
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import Badge from "@/components/ui/badge/Badge";
import { getClientSession } from "@/lib/session";
import type { SessionPayload } from "@/lib/auth";
import { ApiError } from "@/lib/api-client";
import { getSeoOverview, type SeoOverview } from "@/lib/seo";

export default function SeoOverviewPage() {
  const router = useRouter();
  const [session, setSession] = useState<SessionPayload | null>(null);
  const [data, setData] = useState<SeoOverview | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    setSession(s);
    getSeoOverview(s.token)
      .then(setData)
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load SEO overview."));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  if (error) {
    return <p className="rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>;
  }

  if (!session || !data) {
    return <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>;
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">SEO Overview</h1>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
          Coverage checklist across the catalog and storefront — not a scored grade.
        </p>
      </div>

      <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard label="Active Games" value={data.games.total} />
        <StatCard label="SEO Complete" value={data.games.complete} />
        <StatCard label="Redirects" value={data.redirects.total} />
        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Sitemap ({data.sitemap_url_count} URLs)</p>
          <a href={data.sitemap_url} target="_blank" rel="noreferrer" className="mt-1 block text-sm font-medium text-brand-500 hover:underline">
            View /sitemap.xml
          </a>
        </div>
      </div>

      <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <h2 className="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">Recommendations</h2>
        {data.recommendations.length === 0 ? (
          <p className="text-sm text-gray-500 dark:text-gray-400">Nothing outstanding — every active game has SEO title, description, and OG image set.</p>
        ) : (
          <ul className="space-y-3">
            {data.recommendations.map((rec, i) => (
              <li key={i} className="flex items-center justify-between gap-4 rounded-lg border border-gray-100 px-4 py-3 dark:border-gray-800">
                <div className="flex items-center gap-3">
                  <Badge size="sm" color={rec.severity === "warning" ? "warning" : "info"}>
                    {rec.severity === "warning" ? "Warning" : "Info"}
                  </Badge>
                  <span className="text-sm text-gray-700 dark:text-gray-300">{rec.message}</span>
                </div>
                <Link href={rec.link} className="text-sm font-medium text-brand-500 hover:underline">
                  Fix Now
                </Link>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}

function StatCard({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
      <p className="text-theme-xs text-gray-500 dark:text-gray-400">{label}</p>
      <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{value}</p>
    </div>
  );
}
