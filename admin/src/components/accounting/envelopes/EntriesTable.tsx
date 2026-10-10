"use client";

/**
 * One envelope's lines, each with its posting's details. A void reverses
 * the whole posting (every envelope it touched), so the button says so;
 * the original stays visible, struck through.
 */

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tag } from "@/components/ui/tag";
import { ApiError } from "@/lib/api-client";
import { downloadPostingReceipt, formatRm, voidPosting, type BudgetEnvelopeEntry } from "@/lib/budget-envelopes";

function formatDateTime(iso: string): string {
  return new Date(iso).toLocaleString("en-MY", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
}

interface Props {
  token: string;
  entries: BudgetEnvelopeEntry[];
  onChanged: () => Promise<void> | void;
  onError: (message: string) => void;
}

export function EntriesTable({ token, entries, onChanged, onError }: Props) {
  const [voidingId, setVoidingId] = useState<number | null>(null);
  const [voidReason, setVoidReason] = useState("");
  const [downloading, setDownloading] = useState<number | null>(null);

  async function handleVoid(postingId: number) {
    if (!voidReason.trim()) {
      onError("Enter a reason to void this entry.");
      return;
    }
    try {
      await voidPosting(token, postingId, voidReason.trim());
      setVoidingId(null);
      setVoidReason("");
      await onChanged();
    } catch (err) {
      onError(err instanceof ApiError ? err.message : "Could not void this entry.");
    }
  }

  async function handleDownload(postingId: number) {
    setDownloading(postingId);
    try {
      await downloadPostingReceipt(token, postingId);
    } catch (err) {
      onError(err instanceof ApiError ? err.message : "Download failed.");
    } finally {
      setDownloading(null);
    }
  }

  const th = "px-4 py-2 text-theme-xs font-medium text-gray-500 dark:text-gray-400";

  return (
    <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
      <div className="max-w-full overflow-x-auto">
        <table className="w-full text-left text-theme-sm">
          <thead className="bg-gray-50 dark:bg-gray-900">
            <tr>
              <th className={th}>Date</th>
              <th className={th}>Type</th>
              <th className={th}>Amount</th>
              <th className={th}>Description</th>
              <th className={th}>By</th>
              <th className={th}>Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
            {entries.map((entry) => {
              const isReversal = entry.reverses_posting_id !== null;
              const details = [entry.counterparty_label, entry.fund_type_label, entry.expense_category_label, entry.reference_no && `Ref: ${entry.reference_no}`].filter(Boolean);

              return (
                <tr key={entry.id} className={entry.is_voided ? "opacity-60" : undefined}>
                  <td className="px-4 py-2 text-gray-500 dark:text-gray-400">
                    {entry.transaction_date}
                    <span className="block text-theme-xs text-gray-400">#{entry.posting_id} · recorded {formatDateTime(entry.created_at)}</span>
                  </td>
                  <td className="px-4 py-2 text-gray-700 dark:text-gray-300">
                    {entry.type_label}
                    {/* Its two lines net to zero in the same envelope: say which is which. */}
                    {entry.type === "director_paid_expense" && (
                      <span className="block text-theme-xs text-gray-400">
                        {entry.amount_sen > 0 === !isReversal ? "loan in from the director" : "expense out"}
                      </span>
                    )}
                    {entry.is_voided && <Tag severity="danger" className="ml-2">Voided</Tag>}
                    {isReversal && <Tag severity="warn" className="ml-2">Void reversal</Tag>}
                  </td>
                  <td className={`px-4 py-2 font-medium ${entry.amount_sen < 0 ? "text-error-600 dark:text-error-400" : "text-success-600 dark:text-success-400"} ${entry.is_voided ? "line-through" : ""}`}>
                    {entry.amount_sen > 0 ? "+" : ""}{formatRm(entry.amount_sen)}
                  </td>
                  <td className="px-4 py-2 text-gray-600 dark:text-gray-300">
                    {entry.description}
                    {details.length > 0 && <span className="block text-theme-xs text-gray-400">{details.join(" · ")}</span>}
                  </td>
                  <td className="px-4 py-2 text-gray-500 dark:text-gray-400">{entry.created_by ?? "—"}</td>
                  <td className="px-4 py-2">
                    <div className="flex items-center gap-2">
                      {entry.has_receipt && (
                        <Button type="button" size="small" variant="outlined" disabled={downloading === entry.posting_id} onClick={() => handleDownload(entry.posting_id)}>
                          {downloading === entry.posting_id ? "…" : "Receipt"}
                        </Button>
                      )}
                      {!entry.is_voided && !isReversal && (
                        <Button type="button" size="small" variant="outlined" onClick={() => { setVoidingId(voidingId === entry.posting_id ? null : entry.posting_id); setVoidReason(""); }}>
                          {voidingId === entry.posting_id ? "Cancel" : "Void…"}
                        </Button>
                      )}
                    </div>
                    {voidingId === entry.posting_id && (
                      <div className="mt-2 space-y-1">
                        <div className="flex items-center gap-2">
                          <Input value={voidReason} onChange={(e) => setVoidReason(e.target.value)} placeholder="Reason (required)" className="w-56" />
                          <Button type="button" size="small" severity="danger" onClick={() => handleVoid(entry.posting_id)}>Void entry #{entry.posting_id}</Button>
                        </div>
                        <span className="block text-theme-xs text-gray-400">Reverses every envelope this entry touched.</span>
                      </div>
                    )}
                  </td>
                </tr>
              );
            })}
            {entries.length === 0 && (
              <tr>
                <td colSpan={6} className="px-4 py-6 text-center text-gray-400">No entries recorded yet.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
