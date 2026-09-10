"use client";

/**
 * ADR-084 PR-3 decision 4/10: self-service delivery-webhook management,
 * folded into the API Keys screen (decision 10 — "extend the existing
 * API Keys area", not a new nav item). Endpoint URL, signing secret
 * (shown once, rotatable), pause toggle, and the dead-letter delivery
 * log. Mirrors the plaintext-once pattern of the API-keys block above it.
 */

import { useEffect, useState } from "react";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import {
  getWebhook,
  setWebhook,
  rotateWebhookSecret,
  setWebhookActive,
  deleteWebhook,
  listWebhookDeliveries,
  type ResellerWebhook,
  type ResellerWebhookDelivery,
} from "@/lib/reseller-portal";
import { formatDateTime } from "@/lib/format";
import { Panel, ErrorNote, EmptyRow, StatusTag } from "@/components/ui";

const DELIVERY_SEVERITY: Record<ResellerWebhookDelivery["status"], string> = {
  pending: "info",
  delivered: "success",
  failed: "warn",
  exhausted: "danger",
};

export default function WebhookPanel() {
  const [webhook, setWebhookState] = useState<ResellerWebhook | null>(null);
  const [loaded, setLoaded] = useState(false);
  const [deliveries, setDeliveries] = useState<ResellerWebhookDelivery[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [url, setUrl] = useState("");
  const [busy, setBusy] = useState(false);
  const [freshSecret, setFreshSecret] = useState<string | null>(null);

  function refresh() {
    const session = getClientSession();
    if (!session) return;

    return Promise.all([getWebhook(session.token), listWebhookDeliveries(session.token)])
      .then(([{ webhook: hook }, page]) => {
        setWebhookState(hook);
        setUrl(hook?.url ?? "");
        setDeliveries(page.data);
      })
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load the webhook."))
      .finally(() => setLoaded(true));
  }

  useEffect(() => {
    refresh();
  }, []);

  function run(fn: () => Promise<unknown>, fallback: string) {
    const session = getClientSession();
    if (!session) return;
    setError(null);
    setBusy(true);
    return Promise.resolve(fn())
      .then(() => refresh())
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : fallback))
      .finally(() => setBusy(false));
  }

  const save = () =>
    run(async () => {
      const session = getClientSession()!;
      const { secret } = await setWebhook(session.token, url.trim());
      if (secret) setFreshSecret(secret);
    }, "Could not save the endpoint.");

  const rotate = () =>
    run(async () => {
      const session = getClientSession()!;
      const { secret } = await rotateWebhookSecret(session.token);
      setFreshSecret(secret);
    }, "Could not rotate the secret.");

  const toggle = () =>
    run(async () => {
      const session = getClientSession()!;
      await setWebhookActive(session.token, !webhook!.is_active);
    }, "Could not update the endpoint.");

  const remove = () =>
    run(async () => {
      const session = getClientSession()!;
      await deleteWebhook(session.token);
      setFreshSecret(null);
    }, "Could not remove the endpoint.");

  if (!loaded) return null;

  return (
    <div className="mt-10">
      <h2 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">Delivery webhook</h2>
      <p className="mb-4 text-sm text-gray-500 dark:text-gray-400">
        We POST <code>order.delivered</code> / <code>order.failed</code> / <code>order.refunded</code> to your endpoint,
        signed with <code>X-Hub-Signature-256</code>. Polling the order stays available as a fallback.
      </p>

      {error && <ErrorNote message={error} />}

      {freshSecret && (
        <div className="mb-6 space-y-2 rounded-lg border border-warning-300 bg-warning-50 p-4 dark:border-warning-500/30 dark:bg-warning-500/10">
          <p className="text-theme-xs font-medium text-warning-700 dark:text-warning-400">
            Copy this signing secret now — it will never be shown again.
          </p>
          <code className="block break-all rounded-md bg-white px-3 py-2 text-theme-sm text-gray-800 dark:bg-gray-900 dark:text-white/90">
            {freshSecret}
          </code>
          <button
            type="button"
            onClick={() => navigator.clipboard.writeText(freshSecret)}
            className="rounded-lg border border-gray-200 px-3 py-1.5 text-theme-xs text-gray-600 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
          >
            Copy
          </button>
        </div>
      )}

      <Panel title={webhook ? "Endpoint" : "Add an endpoint"}>
        <div className="space-y-4 p-5">
          <label className="block space-y-1.5">
            <span className="text-theme-sm font-medium text-gray-700 dark:text-gray-300">Endpoint URL (HTTPS)</span>
            <input
              value={url}
              onChange={(e) => setUrl(e.target.value)}
              placeholder="https://your-system.example/webhooks/pekangame"
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-theme-sm text-gray-800 outline-none focus:border-brand-400 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
            />
          </label>

          <div className="flex flex-wrap items-center gap-3">
            <button
              type="button"
              onClick={save}
              disabled={busy || url.trim() === "" || url.trim() === (webhook?.url ?? "")}
              className="rounded-lg bg-brand-500 px-4 py-2.5 text-theme-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
            >
              {webhook ? "Update URL" : "Save endpoint"}
            </button>

            {webhook && (
              <>
                <button
                  type="button"
                  onClick={rotate}
                  disabled={busy}
                  className="rounded-lg border border-gray-200 px-3 py-2 text-theme-sm text-gray-600 hover:bg-gray-100 disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
                >
                  Rotate secret
                </button>
                <button
                  type="button"
                  onClick={toggle}
                  disabled={busy}
                  className="rounded-lg border border-gray-200 px-3 py-2 text-theme-sm text-gray-600 hover:bg-gray-100 disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
                >
                  {webhook.is_active ? "Pause" : "Resume"}
                </button>
                <button
                  type="button"
                  onClick={remove}
                  disabled={busy}
                  className="rounded-lg border border-error-200 px-3 py-2 text-theme-sm text-error-600 hover:bg-error-50 disabled:opacity-50 dark:border-error-500/30 dark:text-error-400 dark:hover:bg-error-500/10"
                >
                  Remove
                </button>
                <StatusTag severity={webhook.is_active ? "success" : "muted"}>
                  {webhook.is_active ? "Active" : "Paused"}
                </StatusTag>
              </>
            )}
          </div>
        </div>
      </Panel>

      <div className="mt-6">
        <Panel title="Recent deliveries">
          <div className="max-w-full overflow-x-auto">
            <table className="min-w-full text-theme-sm">
              <thead className="border-b border-gray-100 dark:border-gray-800">
                <tr className="text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                  <th className="px-5 py-3">Event</th>
                  <th className="px-5 py-3">Order</th>
                  <th className="px-5 py-3">Status</th>
                  <th className="px-5 py-3">Attempts</th>
                  <th className="px-5 py-3">Last code</th>
                  <th className="px-5 py-3">When</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                {deliveries.map((d) => (
                  <tr key={d.id} className="text-gray-600 dark:text-gray-300">
                    <td className="px-5 py-4 font-mono text-theme-xs">{d.event}</td>
                    <td className="px-5 py-4">{d.order_number ?? "—"}</td>
                    <td className="px-5 py-4">
                      <StatusTag severity={DELIVERY_SEVERITY[d.status]}>{d.status}</StatusTag>
                    </td>
                    <td className="px-5 py-4">{d.attempts}</td>
                    <td className="px-5 py-4">{d.last_response_code ?? "—"}</td>
                    <td className="px-5 py-4 text-gray-500 dark:text-gray-400">{formatDateTime(d.created_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            {deliveries.length === 0 && <EmptyRow>No deliveries yet.</EmptyRow>}
          </div>
        </Panel>
      </div>
    </div>
  );
}
