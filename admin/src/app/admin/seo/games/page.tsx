"use client";

/** ADR-029 decision 11 — Game SEO's own list+edit screen, mirroring /admin/vouchers' list-page-plus-edit pattern. */

import { Suspense, useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import Link from "next/link";
import { Table, TableHeader, TableBody, TableRow, TableCell } from "@/components/ui/table";
import Badge from "@/components/ui/badge/Badge";
import Input from "@/components/form/input/InputField";
import Select from "@/components/form/Select";
import { getClientSession } from "@/lib/session";
import type { SessionPayload } from "@/lib/auth";
import { ApiError } from "@/lib/api-client";
import { listGameSeo, type GameSeoListItem, type GameSeoStatus } from "@/lib/seo";

const FILTER_OPTIONS: { value: string; label: string }[] = [
  { value: "", label: "All" },
  { value: "complete", label: "Complete" },
  { value: "incomplete", label: "Incomplete" },
  { value: "missing", label: "Missing" },
];

const STATUS_BADGE: Record<GameSeoStatus, { color: "success" | "warning" | "error"; label: string }> = {
  complete: { color: "success", label: "Complete" },
  incomplete: { color: "warning", label: "Incomplete" },
  missing: { color: "error", label: "Missing" },
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
  const [session, setSession] = useState<SessionPayload | null>(null);
  const [games, setGames] = useState<GameSeoListItem[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [filter, setFilter] = useState(searchParams.get("filter") ?? "");

  async function refresh(token: string) {
    try {
      setGames(await listGameSeo(token, { search: search || undefined, filter: (filter || undefined) as GameSeoStatus | undefined }));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load games.");
    }
  }

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    setSession(s);
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
          <Select value={filter} onChange={setFilter} options={FILTER_OPTIONS} />
        </div>
      </div>

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <Table>
            <TableHeader className="border-b border-gray-100 dark:border-gray-800">
              <TableRow>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Game</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">SEO Title</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Noindex</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</TableCell>
              </TableRow>
            </TableHeader>
            <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
              {games?.map((game) => (
                <TableRow key={game.id}>
                  <TableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">{game.name}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{game.seo_title ?? "—"}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">
                    <Badge size="sm" color={STATUS_BADGE[game.seo_status].color}>{STATUS_BADGE[game.seo_status].label}</Badge>
                  </TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">
                    {game.no_index ? <Badge size="sm" color="light">Noindex</Badge> : <span className="text-gray-400">—</span>}
                  </TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">
                    <Link href={`/admin/seo/games/${game.id}`} className="text-brand-500 hover:underline">
                      Edit
                    </Link>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>

          {games?.length === 0 && <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No games match.</p>}
          {games === null && !error && <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>}
        </div>
      </div>
    </div>
  );
}
