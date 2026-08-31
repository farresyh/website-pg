"use client";

import { useEffect, useState } from "react";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import {
  getWithdrawals,
  createWithdrawal,
  type WithdrawalsResponse,
} from "@/lib/portal";
import { formatRm, formatDateTime } from "@/lib/format";
import {
  PageHeader,
  StatCard,
  Panel,
  StatusTag,
  ErrorNote,
  EmptyRow,
} from "@/components/ui";

const STATUS_SEVERITY: Record<string, string> = {
  pending: "warn",
  approved: "info",
  completed: "success",
  rejected: "danger",
};

export default function WithdrawalPage() {
  const [data, setData] = useState<WithdrawalsResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [reloadKey, setReloadKey] = useState(0);

  const [amountRm, setAmountRm] = useState("");
  const [bankName, setBankName] = useState("");
  const [bankAccountNo, setBankAccountNo] = useState("");
  const [bankHolder, setBankHolder] = useState("");
  const [bankTouched, setBankTouched] = useState(false);

  useEffect(() => {
    const session = getClientSession();
    if (!session) return;

    let cancelled = false;
    getWithdrawals(session.token)
      .then((result) => {
        if (cancelled) return;
        setData(result);
        setError(null);
        if (!bankTouched) {
          setBankName(result.prefill.bank_name ?? "");
          setBankAccountNo(result.prefill.bank_account_no ?? "");
          setBankHolder(result.prefill.bank_account_holder ?? "");
        }
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(
          err instanceof ApiError ? err.message : "Could not load withdrawals.",
        );
      });

    return () => {
      cancelled = true;
    };
    // bankTouched intentionally excluded — prefill only on first load
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [reloadKey]);

  const hasOpenRequest = data?.withdrawals.some(
    (w) => w.status === "pending" || w.status === "approved",
  );

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setFormError(null);

    const rm = Number(amountRm);
    if (!Number.isFinite(rm) || rm <= 0) {
      setFormError("Enter a valid amount.");
      return;
    }
    const session = getClientSession();
    if (!session) return;

    setSubmitting(true);
    try {
      await createWithdrawal(session.token, {
        amount: Math.round(rm * 100),
        bank_name: bankName || undefined,
        bank_account_no: bankAccountNo || undefined,
        bank_account_holder: bankHolder || undefined,
      });
      setAmountRm("");
      setReloadKey((k) => k + 1);
    } catch (err) {
      setFormError(
        err instanceof ApiError ? err.message : "Could not submit the request.",
      );
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div>
      <PageHeader
        title="Withdrawal"
        subtitle="Request a payout against your earnings. The platform reviews and transfers it manually."
      />

      {error && <ErrorNote message={error} />}

      <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard
          label="Withdrawable balance"
          value={data ? formatRm(data.balance) : "—"}
        />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Panel title="New request">
          {hasOpenRequest ? (
            <EmptyRow>
              You have a withdrawal in progress. Wait for it to complete before
              requesting another.
            </EmptyRow>
          ) : (
            <form onSubmit={handleSubmit} className="space-y-4 p-5">
              {formError && (
                <p className="rounded-lg bg-error-50 px-3 py-2 text-theme-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
                  {formError}
                </p>
              )}

              <Field label="Amount (RM)">
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  required
                  value={amountRm}
                  onChange={(e) => setAmountRm(e.target.value)}
                  className={inputClass}
                />
              </Field>
              <Field label="Bank name">
                <input
                  value={bankName}
                  onChange={(e) => {
                    setBankTouched(true);
                    setBankName(e.target.value);
                  }}
                  className={inputClass}
                />
              </Field>
              <Field label="Account number">
                <input
                  value={bankAccountNo}
                  onChange={(e) => {
                    setBankTouched(true);
                    setBankAccountNo(e.target.value);
                  }}
                  className={inputClass}
                />
              </Field>
              <Field label="Account holder">
                <input
                  value={bankHolder}
                  onChange={(e) => {
                    setBankTouched(true);
                    setBankHolder(e.target.value);
                  }}
                  className={inputClass}
                />
              </Field>

              <p className="text-theme-xs text-gray-500 dark:text-gray-400">
                Prefilled from your Profile. Edit here for a one-off, or update
                Profile to change the default.
              </p>

              <button
                type="submit"
                disabled={submitting}
                className="w-full rounded-lg bg-brand-500 py-2.5 text-theme-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
              >
                {submitting ? "Submitting…" : "Request withdrawal"}
              </button>
            </form>
          )}
        </Panel>

        <Panel title="History">
          <div className="max-w-full overflow-x-auto">
            <table className="min-w-full text-theme-sm">
              <thead className="border-b border-gray-100 dark:border-gray-800">
                <tr className="text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                  <th className="px-5 py-3">Date</th>
                  <th className="px-5 py-3">Amount</th>
                  <th className="px-5 py-3">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                {data?.withdrawals.map((w) => (
                  <tr key={w.id} className="text-gray-600 dark:text-gray-300">
                    <td className="px-5 py-4 text-theme-xs text-gray-400">
                      {formatDateTime(w.created_at)}
                    </td>
                    <td className="px-5 py-4 font-medium text-gray-800 dark:text-white/90">
                      {formatRm(w.amount)}
                    </td>
                    <td className="px-5 py-4">
                      <StatusTag severity={STATUS_SEVERITY[w.status] ?? "muted"}>
                        {w.status}
                      </StatusTag>
                      {w.admin_note && (
                        <span className="block text-theme-xs text-gray-400">
                          {w.admin_note}
                        </span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {data && data.withdrawals.length === 0 && (
              <EmptyRow>No withdrawal requests yet.</EmptyRow>
            )}
          </div>
        </Panel>
      </div>
    </div>
  );
}

const inputClass =
  "w-full rounded-lg border border-gray-300 px-3 py-2 text-theme-sm text-gray-800 outline-none focus:border-brand-400 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90";

function Field({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) {
  return (
    <label className="block space-y-1.5">
      <span className="text-theme-sm font-medium text-gray-700 dark:text-gray-300">
        {label}
      </span>
      {children}
    </label>
  );
}
