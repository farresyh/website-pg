"use client";

/**
 * VCH-1..6. Only Path A (standalone, from this page) has a UI so far —
 * Path B (auto-computed refund for a failed order) has a backend
 * endpoint (POST /orders/{order}/voucher) but no trigger point yet,
 * since there's no Orders admin UI to add a "Create Voucher" button to.
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Table, TableHeader, TableBody, TableRow, TableCell } from "@/components/ui/table";
import Badge from "@/components/ui/badge/Badge";
import Button from "@/components/ui/button/Button";
import { PlusIcon } from "@/icons";
import { getClientSession } from "@/lib/session";
import type { SessionPayload } from "@/lib/auth";
import { ApiError } from "@/lib/api-client";
import {
  type Voucher,
  type VoucherIndexResponse,
  listVouchers,
  createVoucher,
  revokeVoucher,
} from "@/lib/vouchers";
import CreateVoucherModal from "@/components/vouchers/CreateVoucherModal";

const STAT_CARDS: { key: keyof VoucherIndexResponse["stats"]; label: string }[] = [
  { key: "active", label: "Active" },
  { key: "total_issued", label: "Total Issued" },
  { key: "total_used", label: "Total Used" },
  { key: "expired", label: "Expired" },
];

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

const statusColor: Record<Voucher["status"], "success" | "light" | "warning" | "error"> = {
  active: "success",
  exhausted: "light",
  expired: "warning",
  revoked: "error",
};

export default function VouchersPage() {
  const router = useRouter();
  // Read in an effect, not render body — see UserDropdown.tsx for why.
  const [session, setSession] = useState<SessionPayload | null>(null);

  const [data, setData] = useState<VoucherIndexResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [revokingId, setRevokingId] = useState<number | null>(null);

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
    setSession(s);

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

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Vouchers</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Create and manage customer store-credit vouchers.
          </p>
        </div>
        <Button size="sm" startIcon={<PlusIcon />} onClick={() => setIsModalOpen(true)}>
          Create Voucher
        </Button>
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
          <Table>
            <TableHeader className="border-b border-gray-100 dark:border-gray-800">
              <TableRow>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Code</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Customer</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Amount</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Remaining</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</TableCell>
              </TableRow>
            </TableHeader>
            <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
              {data?.vouchers.map((v) => (
                <TableRow key={v.id}>
                  <TableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                    {v.code}
                    {v.order_id && (
                      <span className="ml-2 text-theme-xs text-gray-400">order #{v.order_id}</span>
                    )}
                  </TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{v.customer_email}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{formatRm(v.amount)}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{formatRm(v.remaining)}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">
                    <Badge size="sm" color={statusColor[v.status]}>{v.status}</Badge>
                  </TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">
                    {v.status === "active" ? (
                      <Button size="sm" variant="danger" disabled={revokingId === v.id} onClick={() => handleRevoke(v)}>
                        Revoke
                      </Button>
                    ) : (
                      <span className="text-gray-400">—</span>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>

          {data?.vouchers.length === 0 && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No vouchers yet.</p>
          )}
          {data === null && !error && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>
          )}
        </div>
      </div>

      <CreateVoucherModal isOpen={isModalOpen} onClose={() => setIsModalOpen(false)} onSubmit={handleCreate} />
    </div>
  );
}
