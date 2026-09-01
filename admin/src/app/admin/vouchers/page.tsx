"use client";

/**
 * VCH-1..6. Path A (standalone, from this page) and Path B (auto-
 * computed refund for a failed order, triggered from /admin/orders'
 * "Issue Voucher…" button) both land here for viewing. ADR-024
 * decision #9: the detail view (usage history, restore/commit trail)
 * follows the same "selected state on this page, no dynamic route"
 * pattern OrderDetailCards already established for /admin/orders —
 * this codebase has no [id]/page.tsx routes anywhere yet.
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
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
import { Button } from "@/components/ui/button";
import { PlusIcon } from "@/icons";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import {
  type Voucher,
  type VoucherIndexResponse,
  type VoucherRedemption,
  type VoucherShowResponse,
  listVouchers,
  getVoucher,
  createVoucher,
  revokeVoucher,
  mergeVouchers,
} from "@/lib/vouchers";
import CreateVoucherModal from "@/components/vouchers/CreateVoucherModal";
import MergeVouchersModal from "@/components/vouchers/MergeVouchersModal";

const STAT_CARDS: { key: keyof VoucherIndexResponse["stats"]; label: string }[] = [
  { key: "active", label: "Active" },
  { key: "total_issued", label: "Total Issued" },
  { key: "total_used", label: "Total Used" },
  { key: "expired", label: "Expired" },
];

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleString("en-MY", { dateStyle: "medium", timeStyle: "short" });
}

const statusSeverity: Record<Voucher["status"], "success" | "secondary" | "warn" | "danger" | "contrast"> = {
  active: "success",
  exhausted: "secondary",
  expired: "warn",
  revoked: "danger",
  // ADR-036 — a merge's voided source, distinct from "exhausted"
  // (spent through redemption) or "revoked" (an admin pulled it).
  merged: "contrast",
};

const redemptionStatusSeverity: Record<VoucherRedemption["status"], "warn" | "success" | "secondary"> = {
  reserved: "warn",
  committed: "success",
  restored: "secondary",
};

const DETAIL_STAT_CARDS: { key: keyof VoucherShowResponse["stats"]; label: string; isRm: boolean }[] = [
  { key: "original", label: "Original", isRm: true },
  { key: "remaining", label: "Remaining", isRm: true },
  { key: "total_used", label: "Total Used", isRm: true },
  { key: "restored", label: "Restored", isRm: true },
  { key: "success_rate", label: "Success Rate", isRm: false },
  { key: "pending", label: "Pending", isRm: false },
];

export default function VouchersPage() {
  const router = useRouter();
  // Read in an effect, not render body — see UserDropdown.tsx for why.
  const session = useClientSession();

  const [data, setData] = useState<VoucherIndexResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [revokingId, setRevokingId] = useState<number | null>(null);

  // ADR-036 — only active vouchers are ever selectable (enforced by
  // the checkbox itself, below); the server is still the real guard
  // on same-customer/active-status.
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [isMergeModalOpen, setIsMergeModalOpen] = useState(false);

  const [selected, setSelected] = useState<VoucherShowResponse | null>(null);
  const [detailError, setDetailError] = useState<string | null>(null);

  async function refresh(token: string) {
    try {
      setData(await listVouchers(token));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load vouchers.");
    }
  }

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }

    listVouchers(s.token)
      .then(setData)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load vouchers.");
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function handleCreate(values: Parameters<typeof createVoucher>[1]) {
    if (!session) return;
    await createVoucher(session.token, values);
    setIsModalOpen(false);
    await refresh(session.token);
  }

  function toggleSelected(voucherId: number) {
    setSelectedIds((prev) =>
      prev.includes(voucherId) ? prev.filter((id) => id !== voucherId) : [...prev, voucherId],
    );
  }

  async function handleMerge(values: Parameters<typeof mergeVouchers>[1]) {
    if (!session) return;
    await mergeVouchers(session.token, values);
    setIsMergeModalOpen(false);
    setSelectedIds([]);
    await refresh(session.token);
  }

  async function handleRevoke(voucher: Voucher) {
    if (!session) return;
    setError(null);
    setRevokingId(voucher.id);

    try {
      await revokeVoucher(session.token, voucher.id);
      await refresh(session.token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not revoke voucher.");
    } finally {
      setRevokingId(null);
    }
  }

  async function openDetail(voucherId: number) {
    if (!session) return;
    setDetailError(null);
    try {
      setSelected(await getVoucher(session.token, voucherId));
    } catch (err) {
      setDetailError(err instanceof ApiError ? err.message : "Could not load voucher details.");
    }
  }

  async function closeDetail() {
    setSelected(null);
    if (session) await refresh(session.token);
  }

  if (selected) {
    const { voucher, stats } = selected;

    return (
      <div>
        <button
          type="button"
          onClick={closeDetail}
          className="mb-4 text-sm text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white"
        >
          ← Back to vouchers
        </button>

        <div className="mb-6 flex items-start justify-between">
          <div>
            <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">{voucher.code}</h1>
            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Voucher Details</p>
          </div>
          {voucher.status === "active" && (
            <Button
              size="small"
              severity="danger"
              disabled={revokingId === voucher.id}
              onClick={async () => {
                await handleRevoke(voucher);
                await openDetail(voucher.id);
              }}
            >
              Revoke
            </Button>
          )}
        </div>

        {detailError && (
          <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
            {detailError}
          </p>
        )}

        <div className="mb-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
          <h2 className="mb-4 text-base font-semibold text-gray-800 dark:text-white/90">Voucher Information</h2>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
              <p className="text-theme-xs text-gray-500 dark:text-gray-400">Status</p>
              <Tag severity={statusSeverity[voucher.status]}>{voucher.status}</Tag>
            </div>
            <div>
              <p className="text-theme-xs text-gray-500 dark:text-gray-400">Customer Email</p>
              <p className="text-theme-sm text-gray-800 dark:text-white/90">{voucher.customer_email}</p>
            </div>
            <div>
              <p className="text-theme-xs text-gray-500 dark:text-gray-400">Customer Phone</p>
              <p className="text-theme-sm text-gray-800 dark:text-white/90">{voucher.customer_phone ?? "—"}</p>
            </div>
            <div>
              <p className="text-theme-xs text-gray-500 dark:text-gray-400">Created</p>
              <p className="text-theme-sm text-gray-800 dark:text-white/90">{formatDate(voucher.created_at)}</p>
            </div>
            <div>
              <p className="text-theme-xs text-gray-500 dark:text-gray-400">Expires</p>
              <p className="text-theme-sm text-gray-800 dark:text-white/90">
                {voucher.expires_at ? formatDate(voucher.expires_at) : "Never"}
              </p>
            </div>
            <div>
              <p className="text-theme-xs text-gray-500 dark:text-gray-400">Source Order</p>
              <p className="text-theme-sm text-gray-800 dark:text-white/90">
                {voucher.source_order?.order_number ?? "—"}
              </p>
            </div>
          </div>
          <div className="mt-4">
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">Reason</p>
            <p className="mt-1 rounded-lg bg-gray-50 px-3 py-2 text-theme-sm text-gray-800 dark:bg-white/[0.03] dark:text-white/90">
              {voucher.reason}
            </p>
          </div>
        </div>

        {(voucher.merges_as_target.length > 0 || voucher.merge_as_source) && (
          <div className="mb-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 className="mb-4 text-base font-semibold text-gray-800 dark:text-white/90">Merge History</h2>
            {voucher.merge_as_source && (
              <p className="mb-3 text-theme-sm text-gray-700 dark:text-gray-300">
                Merged into <span className="font-medium">{voucher.merge_as_source.target_voucher?.code ?? "—"}</span>
              </p>
            )}
            {voucher.merges_as_target.length > 0 && (
              <div>
                <p className="mb-2 text-theme-xs text-gray-500 dark:text-gray-400">Consolidated from:</p>
                <ul className="space-y-2">
                  {voucher.merges_as_target.map((m) => (
                    <li key={m.id} className="text-theme-sm text-gray-700 dark:text-gray-300">
                      <span className="font-medium">{m.source_voucher?.code ?? `voucher #${m.source_voucher_id}`}</span>
                      {" — "}
                      {m.reason} <span className="text-theme-xs text-gray-400">({formatDate(m.created_at)})</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </div>
        )}

        <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-6">
          {DETAIL_STAT_CARDS.map(({ key, label, isRm }) => (
            <div key={key} className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
              <p className="text-theme-xs text-gray-500 dark:text-gray-400">{label}</p>
              <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">
                {isRm ? formatRm(stats[key]) : key === "success_rate" ? `${stats[key]}%` : stats[key]}
              </p>
            </div>
          ))}
        </div>

        <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
          <div className="border-b border-gray-100 px-5 py-3 dark:border-gray-800">
            <h2 className="text-base font-semibold text-gray-800 dark:text-white/90">Usage History</h2>
          </div>
          <div className="max-w-full overflow-x-auto">
            <DataTable data={voucher.redemptions} dataKey="id">
              <DataTableTableContainer>
                <DataTableTable>
                  <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                    <DataTableTHeadRow>
                      <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Order</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Amount</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Date</DataTableTHeadCell>
                    </DataTableTHeadRow>
                  </DataTableTHead>
                  <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                    {({ item }) => {
                      const r = item as unknown as VoucherRedemption;

                      return (
                        <DataTableRow key={r.id}>
                          <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                            {r.order?.order_number ?? `order #${r.order_id}`}
                          </DataTableCell>
                          <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                            -{formatRm(r.amount)}
                          </DataTableCell>
                          <DataTableCell className="px-5 py-4 text-theme-sm">
                            <Tag severity={redemptionStatusSeverity[r.status]}>{r.status}</Tag>
                          </DataTableCell>
                          <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                            {formatDate(r.created_at)}
                          </DataTableCell>
                        </DataTableRow>
                      );
                    }}
                  </DataTableTBody>
                </DataTableTable>
              </DataTableTableContainer>
            </DataTable>

            {voucher.redemptions.length === 0 && (
              <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Not used yet.</p>
            )}
          </div>
        </div>
      </div>
    );
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Vouchers</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Create and manage customer store-credit vouchers.
          </p>
        </div>
        <div className="flex items-center gap-3">
          {selectedIds.length >= 2 && (
            <Button size="small" variant="outlined" onClick={() => setIsMergeModalOpen(true)}>
              Merge Selected ({selectedIds.length})
            </Button>
          )}
          <Button size="small" onClick={() => setIsModalOpen(true)}>
            <PlusIcon />
            Create Voucher
          </Button>
        </div>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        {STAT_CARDS.map(({ key, label }) => (
          <div key={key} className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">{label}</p>
            <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">
              {data?.stats[key]?.count ?? 0}
            </p>
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">
              {formatRm(data?.stats[key]?.total ?? 0)}
            </p>
          </div>
        ))}
      </div>

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={data?.vouchers ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">{null}</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Code</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Customer</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Amount</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Remaining</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item }) => {
                    const v = item as unknown as Voucher;

                    return (
                      <DataTableRow key={v.id}>
                        <DataTableCell className="px-5 py-4">
                          {v.status === "active" && (
                            <input
                              type="checkbox"
                              checked={selectedIds.includes(v.id)}
                              onChange={() => toggleSelected(v.id)}
                              aria-label={`Select ${v.code} for merge`}
                            />
                          )}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                          <button
                            type="button"
                            className="hover:text-brand-500 dark:hover:text-brand-400"
                            onClick={() => openDetail(v.id)}
                          >
                            {v.code}
                          </button>
                          {v.order_id && (
                            <span className="ml-2 text-theme-xs text-gray-400">order #{v.order_id}</span>
                          )}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{v.customer_email}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{formatRm(v.amount)}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{formatRm(v.remaining)}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <Tag severity={statusSeverity[v.status]}>{v.status}</Tag>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <div className="flex items-center gap-3">
                            <button
                              type="button"
                              className="text-brand-500 hover:text-brand-600 dark:text-brand-400"
                              onClick={() => openDetail(v.id)}
                            >
                              View
                            </button>
                            {v.status === "active" && (
                              <Button size="small" severity="danger" disabled={revokingId === v.id} onClick={() => handleRevoke(v)}>
                                Revoke
                              </Button>
                            )}
                          </div>
                        </DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>

          {data?.vouchers.length === 0 && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No vouchers yet.</p>
          )}
          {data === null && !error && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>
          )}
        </div>
      </div>

      <CreateVoucherModal isOpen={isModalOpen} onClose={() => setIsModalOpen(false)} onSubmit={handleCreate} />
      <MergeVouchersModal
        isOpen={isMergeModalOpen}
        vouchers={data?.vouchers.filter((v) => selectedIds.includes(v.id)) ?? []}
        onClose={() => setIsMergeModalOpen(false)}
        onSubmit={handleMerge}
      />
    </div>
  );
}
