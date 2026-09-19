"use client";

/**
 * ADR-110 PR-C — CHIP credential `.env`→DB migration. One card per
 * known gateway key (`PAYMENT_GATEWAY_FIELD_DEFINITIONS`, `chip` the
 * only one today — ADR-022's still-open seam for a future 2nd
 * gateway), regardless of whether a `payment_gateways` row exists yet
 * — an unconfigured gateway shows "Not configured" rather than being
 * absent from the screen.
 */

import React, { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Tag } from "@/components/ui/tag";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import {
  type PaymentGateway,
  PAYMENT_GATEWAY_FIELD_DEFINITIONS,
  listPaymentGateways,
  updatePaymentGateway,
} from "@/lib/payment-gateways";
import EditPaymentGatewayModal from "@/components/middleware/payment-gateways/EditPaymentGatewayModal";

function formatDateTime(value: string | null): string {
  return value ? new Date(value).toLocaleString() : "—";
}

export default function PaymentGatewaysPage() {
  const router = useRouter();
  const session = useClientSession();

  const [gateways, setGateways] = useState<PaymentGateway[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ tone: "ok" | "warn"; text: string } | null>(null);
  const [editTarget, setEditTarget] = useState<string | null>(null);

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const refresh = useCallback((token: string) => {
    listPaymentGateways(token)
      .then(setGateways)
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load payment gateways."));
  }, []);

  useEffect(() => {
    if (!session) return;
    refresh(session.token);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session]);

  async function handleSave(apiConfig: Record<string, string>) {
    if (!session || !editTarget) return;
    setNotice(null);
    const updated = await updatePaymentGateway(session.token, editTarget, apiConfig);
    setEditTarget(null);
    refresh(session.token);

    const probe = updated.connection_probe;
    setNotice({
      tone: probe.connection_ok ? "ok" : "warn",
      text: probe.connection_ok
        ? "Credentials saved — connection OK."
        : `Saved, but the connection check failed: ${probe.error ?? "unknown error"}.`,
    });
  }

  const knownKeys = Object.keys(PAYMENT_GATEWAY_FIELD_DEFINITIONS);

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Payment Gateways</h1>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
          Credentials for each payment gateway (ADR-110 PR-C) — moved off <code>.env</code> so a rotation takes
          effect immediately, no redeploy needed.
        </p>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      {notice && (
        <p
          className={
            notice.tone === "ok"
              ? "mb-4 rounded-lg bg-success-50 px-4 py-3 text-sm text-success-700 dark:bg-success-500/15 dark:text-success-400"
              : "mb-4 rounded-lg bg-warning-50 px-4 py-3 text-sm text-warning-700 dark:bg-warning-500/15 dark:text-warning-400"
          }
        >
          {notice.text}
        </p>
      )}

      {gateways === null && !error && <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>}

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        {gateways !== null &&
          knownKeys.map((key) => {
            const gateway = gateways.find((g) => g.gateway_key === key) ?? null;

            return (
              <div
                key={key}
                className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]"
              >
                <div className="mb-3 flex items-start justify-between">
                  <p className="font-medium capitalize text-gray-800 dark:text-white/90">{key}</p>
                  <Tag severity={gateway?.has_credentials ? "success" : "secondary"}>
                    {gateway?.has_credentials ? "Configured" : "Not configured"}
                  </Tag>
                </div>

                <dl className="mb-4 space-y-1.5 text-theme-sm">
                  {(PAYMENT_GATEWAY_FIELD_DEFINITIONS[key] ?? [])
                    .filter((field) => field.type !== "secret")
                    .map((field) => (
                      <div key={field.key} className="flex justify-between gap-2">
                        <dt className="shrink-0 text-gray-500 dark:text-gray-400">{field.label}</dt>
                        <dd className="truncate text-right text-gray-800 dark:text-white/90">
                          {gateway?.visible_config[field.key] ?? "—"}
                        </dd>
                      </div>
                    ))}
                  <div className="flex justify-between">
                    <dt className="text-gray-500 dark:text-gray-400">Last updated</dt>
                    <dd className="text-gray-800 dark:text-white/90">{formatDateTime(gateway?.updated_at ?? null)}</dd>
                  </div>
                </dl>

                <Button size="small" variant="outlined" onClick={() => setEditTarget(key)}>
                  Edit
                </Button>
              </div>
            );
          })}
      </div>

      <EditPaymentGatewayModal
        isOpen={editTarget !== null}
        onClose={() => setEditTarget(null)}
        onSubmit={handleSave}
        gatewayKey={editTarget}
        gateway={editTarget ? gateways?.find((g) => g.gateway_key === editTarget) ?? null : null}
      />
    </div>
  );
}
