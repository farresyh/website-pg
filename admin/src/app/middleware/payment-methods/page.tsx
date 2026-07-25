"use client";

/**
 * SET-7/SET-11 — see backend/app/Http/Controllers/Middleware/PaymentMethodController.php
 * and the create_payment_methods_table migration's doc comment for why
 * this is admin-curated (Xendit has no API to report which channels are
 * enabled for this merchant account) and why it lives under /middleware.
 * All channels seed `is_active = false` — admin confirms each one via
 * "Test" (fires a real, harmless payment request) before flipping it on.
 */

import React, { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Table, TableHeader, TableBody, TableRow, TableCell } from "@/components/ui/table";
import Button from "@/components/ui/button/Button";
import { getClientSession } from "@/lib/session";
import type { SessionPayload } from "@/lib/auth";
import { ApiError } from "@/lib/api-client";
import {
  type PaymentMethod,
  listPaymentMethods,
  updatePaymentMethodStatus,
  updatePaymentMethodFee,
  testPaymentMethod,
} from "@/lib/payment-methods";

const CATEGORY_LABELS: Record<string, string> = {
  fpx: "FPX",
  ewallet: "E-Wallet",
  card: "Card",
  virtual_account: "Virtual Account",
};

/**
 * Remounted via `key` (same fresh-mount-per-row reasoning as
 * /admin/games's MarkupCell) whenever the server-side rate changes, so
 * the two inputs always start from the latest saved value.
 */
function FeeCell({
  method,
  onUpdate,
}: {
  method: PaymentMethod;
  onUpdate: (percentageRate: number, flatFeeSen: number) => Promise<void>;
}) {
  const [percentage, setPercentage] = useState(method.percentage_rate);
  const [flatSen, setFlatSen] = useState(String(method.flat_fee_sen));
  const [saving, setSaving] = useState(false);

  async function handleUpdate() {
    const percentageRate = parseFloat(percentage);
    const flatFeeSen = parseInt(flatSen, 10);
    if (!Number.isFinite(percentageRate) || percentageRate < 0) return;
    if (!Number.isFinite(flatFeeSen) || flatFeeSen < 0) return;

    setSaving(true);
    try {
      await onUpdate(percentageRate, flatFeeSen);
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="flex items-center gap-1.5">
      <div className="relative">
        <input
          type="text"
          value={percentage}
          onChange={(e) => setPercentage(e.target.value)}
          className="h-9 w-16 rounded-lg border border-gray-300 px-2 pr-4 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
        />
        <span className="pointer-events-none absolute right-1.5 top-1/2 -translate-y-1/2 text-xs text-gray-400">%</span>
      </div>
      <span className="text-xs text-gray-400">+</span>
      <div className="relative">
        <span className="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-xs text-gray-400">¢</span>
        <input
          type="text"
          value={flatSen}
          onChange={(e) => setFlatSen(e.target.value)}
          className="h-9 w-16 rounded-lg border border-gray-300 pl-4 pr-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
        />
      </div>
      <Button size="sm" disabled={saving} onClick={handleUpdate}>
        {saving ? "…" : "Save"}
      </Button>
    </div>
  );
}

export default function PaymentMethodsPage() {
  const router = useRouter();
  const [session, setSession] = useState<SessionPayload | null>(null);

  const [methods, setMethods] = useState<PaymentMethod[] | null>(null);
  const [category, setCategory] = useState<string>("all");
  const [error, setError] = useState<string | null>(null);
  const [testingId, setTestingId] = useState<number | null>(null);

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    setSession(s);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!session) return;

    listPaymentMethods(session.token, { category: category === "all" ? undefined : category })
      .then(setMethods)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load payment methods.");
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, category]);

  async function handleToggleStatus(method: PaymentMethod) {
    if (!session) return;
    setError(null);
    try {
      const updated = await updatePaymentMethodStatus(session.token, method.id, !method.is_active);
      setMethods((prev) => prev?.map((m) => (m.id === method.id ? { ...m, ...updated } : m)) ?? null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not update status.");
    }
  }

  async function handleUpdateFee(method: PaymentMethod, percentageRate: number, flatFeeSen: number) {
    if (!session) return;
    setError(null);
    try {
      const updated = await updatePaymentMethodFee(session.token, method.id, percentageRate, flatFeeSen);
      setMethods((prev) => prev?.map((m) => (m.id === method.id ? { ...m, ...updated } : m)) ?? null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not update fee.");
    }
  }

  async function handleTest(method: PaymentMethod) {
    if (!session) return;
    setError(null);
    setTestingId(method.id);
    try {
      const updated = await testPaymentMethod(session.token, method.id);
      setMethods((prev) => prev?.map((m) => (m.id === method.id ? { ...m, ...updated } : m)) ?? null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not test channel.");
    } finally {
      setTestingId(null);
    }
  }

  const categories = ["all", "fpx", "ewallet", "card", "virtual_account"];

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Payment Methods</h1>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
          Per-channel activation and fee rates (SET-7/SET-11). Every channel starts inactive — confirm it actually
          works with &quot;Test&quot; before turning it on for checkout.
        </p>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <div className="mb-4 flex gap-2">
        {categories.map((c) => (
          <button
            key={c}
            onClick={() => setCategory(c)}
            className={`rounded-lg px-3 py-1.5 text-sm capitalize ${category === c ? "bg-brand-500 text-white" : "bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400"}`}
          >
            {c === "all" ? "All" : (CATEGORY_LABELS[c] ?? c)}
          </button>
        ))}
      </div>

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <Table>
            <TableHeader className="border-b border-gray-100 dark:border-gray-800">
              <TableRow>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Active</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Channel</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Fee</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Last Test</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</TableCell>
              </TableRow>
            </TableHeader>
            <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
              {methods?.map((method) => (
                <TableRow key={method.id}>
                  <TableCell className="px-5 py-4 text-theme-sm">
                    <button
                      role="switch"
                      aria-checked={method.is_active}
                      onClick={() => handleToggleStatus(method)}
                      className={`h-6 w-11 rounded-full transition ${method.is_active ? "bg-brand-500" : "bg-gray-300 dark:bg-gray-700"}`}
                    >
                      <span
                        className={`block h-5 w-5 translate-x-0.5 rounded-full bg-white transition ${method.is_active ? "translate-x-[22px]" : ""}`}
                      />
                    </button>
                  </TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">
                    <span className="font-medium text-gray-800 dark:text-white/90">{method.label}</span>
                    <br />
                    <span className="text-theme-xs text-gray-400">{method.channel_code} · {method.gateway}</span>
                  </TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">
                    <FeeCell
                      key={`${method.id}-${method.percentage_rate}-${method.flat_fee_sen}`}
                      method={method}
                      onUpdate={(percentageRate, flatFeeSen) => handleUpdateFee(method, percentageRate, flatFeeSen)}
                    />
                  </TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                    {method.last_test_result ? (
                      <span className={method.last_test_result === "success" ? "text-success-600 dark:text-success-500" : "text-error-600 dark:text-error-500"}>
                        {method.last_test_result}
                      </span>
                    ) : (
                      "Not tested yet"
                    )}
                  </TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">
                    <Button size="sm" variant="outline" disabled={testingId === method.id} onClick={() => handleTest(method)}>
                      {testingId === method.id ? "Testing…" : "Test"}
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
          {methods?.length === 0 && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No channels in this category.</p>
          )}
          {methods === null && !error && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>
          )}
        </div>
      </div>
    </div>
  );
}
