"use client";

/** ADR-029 decision 11 — Game SEO's own list+edit screen, mirroring /admin/vouchers' list-page-plus-edit pattern. */

import { Suspense, useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import Link from "next/link";
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
import { Input } from "@/components/ui/input";
import { SimpleSelect } from "@/components/ui/select";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { listGameSeo, type GameSeoListItem, type GameSeoStatus } from "@/lib/seo";

const FILTER_OPTIONS: { value: string; label: string }[] = [
  { value: "", label: "All" },
  { value: "complete", label: "Complete" },
  { value: "incomplete", label: "Incomplete" },
  { value: "missing", label: "Missing" },
];

const STATUS_TAG: Record<GameSeoStatus, { severity: "success" | "warn" | "danger"; label: string }> = {
  complete: { severity: "success", label: "Complete" },
  incomplete: { severity: "warn", label: "Incomplete" },
  missing: { severity: "danger", label: "Missing" },
};

export default function GameSeoListPage() {
  return (
    <Suspense fallback={<p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>}>
      <GameSeoListPageInner />
    </Suspense>
  );
}

function GameSeoListPageInner() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const session = useClientSession();
  const [games, setGames] = useState<GameSeoListItem[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [filter, setFilter] = useState(searchParams.get("filter") ?? "");

  function refresh(token: string) {
    return listGameSeo(token, { search: search || undefined, filter: (filter || undefined) as GameSeoStatus | undefined })
      .then(setGames)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load games.");
      });
  }

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    refresh(s.token);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (session) refresh(session.token);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search, filter]);

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Game SEO</h1>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
          Per-game meta title/description/keywords/OG image, JSON-LD brand/category, and noindex.
        </p>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <div className="mb-4 flex flex-wrap items-center gap-3">
        <div className="w-64">
          <Input placeholder="Search games…" value={search} onChange={(e) => setSearch(e.target.value)} />
        </div>
        <div className="w-48">
          <SimpleSelect value={filter} onChange={setFilter} options={FILTER_OPTIONS} />
        </div>
      </div>

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={games ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Game</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">SEO Title</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Noindex</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item }) => {
                    const game = item as unknown as GameSeoListItem;

                    return (
                      <DataTableRow key={game.id}>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">{game.name}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{game.seo_title ?? "—"}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <Tag severity={STATUS_TAG[game.seo_status].severity}>{STATUS_TAG[game.seo_status].label}</Tag>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          {game.no_index ? <Tag severity="secondary">Noindex</Tag> : <span className="text-gray-400">—</span>}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <Link href={`/admin/seo/games/${game.id}`} className="text-brand-500 hover:underline">
                            Edit
                          </Link>
                        </DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>

          {games?.length === 0 && <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No games match.</p>}
          {games === null && !error && <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>}
        </div>
      </div>
    </div>
  );
}
