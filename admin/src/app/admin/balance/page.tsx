"use client";

/**
 * Balance — read-only snapshot of what we hold at each Supplier (live,
 * refreshable via the existing ADR-046 decision-8 checkBalance() probe,
 * previously wired on the backend but never exposed in any admin
 * screen) alongside what every Reseller has prepaid into their wallet
 * (ADR-073, ledger-derived via listResellers()). Deliberately two
 * separate tables, never summed together — a Supplier's balance is in
 * its own currency (e.g. Digiflazz = IDR), Reseller wallets are MYR
 * sen. Wallet crediting/management stays on Resellers; this page is
 * visibility only.
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
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
import { Button } from "@/components/ui/button";
import { Tag } from "@/components/ui/tag";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { listSuppliers, refreshSupplierBalance, type Supplier } from "@/lib/suppliers";
import { listResellers, type ResellerRow } from "@/lib/resellers";

const TH = "px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400";
const TD = "px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400";

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

function formatDate(iso: string | null): string {
  if (!iso) return "never";
  return new Date(iso).toLocaleString("en-MY", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" });
}

export default function BalanceOverviewPage() {
  const router = useRouter();
  const session = useClientSession();
  const token = session?.token ?? null;
  const [suppliers, setSuppliers] = useState<Supplier[] | null>(null);
  const [resellers, setResellers] = useState<ResellerRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [refreshingId, setRefreshingId] = useState<number | null>(null);

  function refresh(t: string) {
    return Promise.all([listSuppliers(t), listResellers(t)])
      .then(([s, r]) => {
        setSuppliers(s);
        setResellers(r.resellers);
      })
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load balances."));
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

  async function handleRefreshSupplier(supplierId: number) {
    if (!token) return;
    setRefreshingId(supplierId);
    setError(null);
    try {
      const updated = await refreshSupplierBalance(token, supplierId);
      setSuppliers((prev) => prev?.map((s) => (s.id === supplierId ? updated : s)) ?? prev);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Balance check failed.");
    } finally {
      setRefreshingId(null);
    }
  }

  if (error && !suppliers) {
    return <p className="rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>;
  }

  if (!token || !suppliers || !resellers) {
    return <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>;
  }

  const totalWalletSen = resellers.reduce((sum, r) => sum + r.wallet_balance_sen, 0);
  const sortedResellers = [...resellers].sort((a, b) => b.wallet_balance_sen - a.wallet_balance_sen);

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Balance</h1>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
          What we currently hold at each supplier, and what every Reseller has prepaid into their wallet. Two
          separate pots in two separate currencies — shown side by side, never added together.
        </p>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <div className="mb-8">
        <h2 className="mb-3 text-theme-sm font-medium text-gray-600 dark:text-gray-400">Supplier balances</h2>
        <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
          <div className="max-w-full overflow-x-auto">
            <DataTable data={suppliers} dataKey="id">
              <DataTableTableContainer>
                <DataTableTable>
                  <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                    <DataTableTHeadRow>
                      <DataTableTHeadCell className={TH}>Supplier</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Currency</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Balance</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Last checked</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Status</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Actions</DataTableTHeadCell>
                    </DataTableTHeadRow>
                  </DataTableTHead>
                  <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                    {({ item }) => {
                      const s = item as unknown as Supplier;
                      return (
                        <DataTableRow key={s.id}>
                          <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                            {s.name}
                          </DataTableCell>
                          <DataTableCell className={TD}>{s.currency}</DataTableCell>
                          <DataTableCell className={TD}>{s.balance ?? "—"}</DataTableCell>
                          <DataTableCell className={TD}>
                            {formatDate(s.last_tested_at)}
                            {s.last_test_result?.startsWith("failed") && (
                              <p className="mt-0.5 max-w-xs text-theme-xs text-error-600 dark:text-error-400">
                                {s.last_test_result}
                              </p>
                            )}
                          </DataTableCell>
                          <DataTableCell className="px-5 py-4">
                            <Tag severity={s.is_active ? "success" : "secondary"}>{s.is_active ? "active" : "inactive"}</Tag>
                          </DataTableCell>
                          <DataTableCell className="px-5 py-4">
                            <Button
                              size="small"
                              variant="outlined"
                              disabled={refreshingId === s.id}
                              onClick={() => handleRefreshSupplier(s.id)}
                            >
                              {refreshingId === s.id ? "Checking…" : "Refresh"}
                            </Button>
                          </DataTableCell>
                        </DataTableRow>
                      );
                    }}
                  </DataTableTBody>
                </DataTableTable>
              </DataTableTableContainer>
            </DataTable>
            {suppliers.length === 0 && (
              <p className="px-5 py-6 text-center text-theme-sm text-gray-400">No suppliers configured yet.</p>
            )}
          </div>
        </div>
      </div>

      <div>
        <div className="mb-3 flex items-baseline justify-between">
          <h2 className="text-theme-sm font-medium text-gray-600 dark:text-gray-400">Reseller wallets</h2>
          <p className="text-theme-sm text-gray-500 dark:text-gray-400">
            Total across {resellers.length} reseller{resellers.length === 1 ? "" : "s"}:{" "}
            <span className="font-semibold text-gray-800 dark:text-white/90">{formatRm(totalWalletSen)}</span>
          </p>
        </div>
        <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
          <div className="max-w-full overflow-x-auto">
            <DataTable data={sortedResellers} dataKey="id">
              <DataTableTableContainer>
                <DataTableTable>
                  <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                    <DataTableTHeadRow>
                      <DataTableTHeadCell className={TH}>Reseller</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Tier</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Wallet balance</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Status</DataTableTHeadCell>
                    </DataTableTHeadRow>
                  </DataTableTHead>
                  <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                    {({ item }) => {
                      const r = item as unknown as ResellerRow;
                      return (
                        <DataTableRow key={r.id}>
                          <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                            {r.business_name}
                          </DataTableCell>
                          <DataTableCell className={TD}>{r.tier_name ?? "—"}</DataTableCell>
                          <DataTableCell className={TD}>{formatRm(r.wallet_balance_sen)}</DataTableCell>
                          <DataTableCell className="px-5 py-4">
                            <Tag severity={r.is_active ? "success" : "secondary"}>{r.is_active ? "active" : "inactive"}</Tag>
                          </DataTableCell>
                        </DataTableRow>
                      );
                    }}
                  </DataTableTBody>
                </DataTableTable>
              </DataTableTableContainer>
            </DataTable>
            {resellers.length === 0 && (
              <p className="px-5 py-6 text-center text-theme-sm text-gray-400">No resellers yet.</p>
            )}
          </div>
        </div>
        <p className="mt-2 text-theme-xs text-gray-400">
          Read-only — to credit a wallet or manage a reseller, use{" "}
          <Link href="/admin/resellers" className="underline">
            Resellers
          </Link>
          .
        </p>
      </div>
    </div>
  );
}
