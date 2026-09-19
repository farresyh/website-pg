"use client";

/**
 * ADR-110 PR-B, fills ADR-083 decision 7 — "CHIP Settlements". Upload
 * CHIP's own dashboard `.xlsx` export; the backend matches every
 * transaction against orders/membership subscriptions/reseller wallet
 * top-ups and never re-counts an already-reconciled one (this ADR's
 * own addendum — a re-uploaded or date-overlapping file is safe).
 */

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
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
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import {
  type PaymentSettlement,
  type SettlementIngestResult,
  listPaymentSettlements,
  uploadPaymentSettlement,
  updatePaymentSettlement,
} from "@/lib/payment-settlements";

const TH = "px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400";
const TD = "px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400";

function rm(sen: number | null): string {
  return sen === null ? "—" : `RM ${(sen / 100).toFixed(2)}`;
}

const statusSeverity: Record<PaymentSettlement["status"], "secondary" | "success" | "danger"> = {
  pending: "secondary",
  matched: "success",
  variance: "danger",
};

export default function PaymentSettlementsPage() {
  const router = useRouter();
  const session = useClientSession();
  const token = session?.token ?? null;

  const [settlements, setSettlements] = useState<PaymentSettlement[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [uploading, setUploading] = useState(false);
  const [lastResult, setLastResult] = useState<SettlementIngestResult | null>(null);
  const [bankFigures, setBankFigures] = useState<Record<number, string>>({});
  const [savingId, setSavingId] = useState<number | null>(null);

  const refresh = useCallback((t: string) => {
    listPaymentSettlements(t)
      .then(setSettlements)
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load settlements."));
  }, []);

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    refresh(s.token);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function handleUpload(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    e.target.value = "";
    if (!file || !token) return;

    setUploading(true);
    setError(null);
    setLastResult(null);
    try {
      const result = await uploadPaymentSettlement(token, file);
      setLastResult(result);
      refresh(token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Upload failed — is this a real CHIP settlement .xlsx?");
    } finally {
      setUploading(false);
    }
  }

  async function handleSaveBankFigure(settlement: PaymentSettlement) {
    if (!token) return;
    const raw = bankFigures[settlement.id];
    if (!raw) return;
    const sen = Math.round(parseFloat(raw) * 100);
    if (Number.isNaN(sen)) return;

    setSavingId(settlement.id);
    setError(null);
    try {
      const status = sen === settlement.expected_net_sen ? "matched" : "variance";
      const variance_note = status === "variance" ? `Bank figure RM${raw} vs expected ${rm(settlement.expected_net_sen)}` : undefined;
      await updatePaymentSettlement(token, settlement.id, { actual_bank_amount_sen: sen, status, variance_note });
      refresh(token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save the bank figure.");
    } finally {
      setSavingId(null);
    }
  }

  if (error && !settlements) {
    return <p className="rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>;
  }

  if (!token || !settlements) {
    return <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>;
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">CHIP Settlements</h1>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
          Upload CHIP&apos;s own dashboard settlement export (.xlsx). Re-uploading the same file, or one whose date
          range overlaps an earlier upload, is safe — a transaction already reconciled is never counted twice.
        </p>
      </div>

      <div className="mb-6 rounded-lg border border-gray-200 p-4 dark:border-gray-800">
        <Label htmlFor="settlement_file">Upload settlement .xlsx</Label>
        <input
          id="settlement_file"
          type="file"
          accept=".xlsx"
          disabled={uploading}
          onChange={handleUpload}
          className="block w-full text-theme-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-theme-xs dark:text-gray-400 dark:file:bg-gray-800"
        />
        {uploading && <p className="mt-2 text-theme-xs text-gray-400">Uploading and reconciling…</p>}
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      {lastResult && (
        <div className="mb-6 space-y-2 rounded-lg border border-gray-200 p-4 text-theme-sm dark:border-gray-800">
          <p>
            <strong>{lastResult.newly_matched_count}</strong> newly matched,{" "}
            <strong>{lastResult.newly_unmatched_count}</strong> settled but unmatched,{" "}
            <strong>{lastResult.already_reconciled_skipped_count}</strong> already reconciled (skipped).
          </p>
          {lastResult.unmatched_transaction_ids.length > 0 && (
            <p className="text-warning-600 dark:text-warning-400">
              Unmatched Transaction IDs: {lastResult.unmatched_transaction_ids.join(", ")}
            </p>
          )}
          {lastResult.paid_but_not_settled.length > 0 && (
            <p className="text-warning-600 dark:text-warning-400">
              Paid but not yet settled in any file: {lastResult.paid_but_not_settled.map((r) => r.reference).join(", ")}
            </p>
          )}
        </div>
      )}

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={settlements} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className={TH}>Window</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Expected Net</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>File Net</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Bank Figure</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Status</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}></DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item }) => {
                    const s = item as unknown as PaymentSettlement;
                    return (
                      <DataTableRow key={s.id}>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                          {s.date_from} → {s.date_to}
                        </DataTableCell>
                        <DataTableCell className={TD}>{rm(s.expected_net_sen)}</DataTableCell>
                        <DataTableCell className={TD}>{rm(s.file_net_sen)}</DataTableCell>
                        <DataTableCell className="px-5 py-4">
                          {s.actual_bank_amount_sen !== null ? (
                            rm(s.actual_bank_amount_sen)
                          ) : (
                            <div className="flex items-center gap-2">
                              <Input
                                type="number"
                                step="0.01"
                                placeholder="0.00"
                                className="w-24"
                                value={bankFigures[s.id] ?? ""}
                                onChange={(e) => setBankFigures((v) => ({ ...v, [s.id]: e.target.value }))}
                              />
                              <Button size="small" disabled={savingId === s.id} onClick={() => handleSaveBankFigure(s)}>
                                Save
                              </Button>
                            </div>
                          )}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4">
                          <Tag severity={statusSeverity[s.status]}>{s.status}</Tag>
                        </DataTableCell>
                        <DataTableCell className={TD}>
                          <Link href={`/admin/accounting/settlements/${s.id}`} className="text-brand-500 hover:underline">
                            View transactions
                          </Link>
                        </DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>
          {settlements.length === 0 && (
            <p className="px-5 py-6 text-center text-theme-sm text-gray-400">No settlements uploaded yet.</p>
          )}
        </div>
      </div>
    </div>
  );
}
