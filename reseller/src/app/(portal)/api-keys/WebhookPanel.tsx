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
import { notify } from "@/components/ui/toast";
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
import { Panel, ErrorNote, EmptyRow, StatusTag } from "@/components/composites";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

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

  function run(fn: () => Promise<unknown>, fallback: string, success?: string) {
    const session = getClientSession();
    if (!session) return;
    setBusy(true);
    return Promise.resolve(fn())
      .then(() => {
        if (success) notify.success(success);
        return refresh();
      })
      .catch((err: unknown) => notify.error(err instanceof ApiError ? err.message : fallback))
      .finally(() => setBusy(false));
  }

  const save = () =>
    run(async () => {
      const session = getClientSession()!;
      const { secret } = await setWebhook(session.token, url.trim());
      if (secret) setFreshSecret(secret);
    }, "Could not save the endpoint.", "Webhook saved");

  const rotate = () =>
    run(async () => {
      const session = getClientSession()!;
      const { secret } = await rotateWebhookSecret(session.token);
      setFreshSecret(secret);
    }, "Could not rotate the secret.", "Secret rotated");

  const toggle = () =>
    run(async () => {
      const session = getClientSession()!;
      await setWebhookActive(session.token, !webhook!.is_active);
    }, "Could not update the endpoint.", "Webhook updated");

  const remove = () =>
    run(async () => {
      const session = getClientSession()!;
      await deleteWebhook(session.token);
      setFreshSecret(null);
    }, "Could not remove the endpoint.", "Webhook removed");

  if (!loaded) return null;

  return (
    <div className="mt-10">
      <h2 className="mb-1 text-lg font-semibold text-ink">Delivery webhook</h2>
      <p className="mb-4 text-sm text-ink-subtle">
        We POST <code>order.delivered</code> / <code>order.failed</code> / <code>order.refunded</code> to your endpoint,
        signed with <code>X-Hub-Signature-256</code>. Polling the order stays available as a fallback.
      </p>

      {error && <ErrorNote message={error} />}

      {freshSecret && (
        <div className="mb-6 space-y-2 rounded-lg border border-warning-ink/30 bg-warning-surface p-4">
          <p className="text-theme-xs font-medium text-warning-ink">
            Copy this signing secret now — it will never be shown again.
          </p>
          <code className="block break-all rounded-md bg-surface px-3 py-2 text-theme-sm text-ink">
            {freshSecret}
          </code>
          <button
            type="button"
            onClick={() => navigator.clipboard.writeText(freshSecret)}
            className="rounded-md border border-border px-3 py-1.5 text-theme-xs text-ink-muted hover:bg-subtle"
          >
            Copy
          </button>
        </div>
      )}

      <Panel title={webhook ? "Endpoint" : "Add an endpoint"}>
        <div className="space-y-4 p-5">
          <label className="block space-y-1.5">
            <span className="text-theme-sm font-medium text-ink-muted">Endpoint URL (HTTPS)</span>
            <Input
              value={url}
              onChange={(e) => setUrl(e.target.value)}
              placeholder="https://your-system.example/webhooks/pekangame"
            />
          </label>

          <div className="flex flex-wrap items-center gap-3">
            <Button
              type="button"
              onClick={save}
              disabled={busy || url.trim() === "" || url.trim() === (webhook?.url ?? "")}
            >
              {webhook ? "Update URL" : "Save endpoint"}
            </Button>

            {webhook && (
              <>
                <button
                  type="button"
                  onClick={rotate}
                  disabled={busy}
                  className="rounded-md border border-border px-3 py-2 text-theme-sm text-ink-muted hover:bg-subtle disabled:opacity-50"
                >
                  Rotate secret
                </button>
                <button
                  type="button"
                  onClick={toggle}
                  disabled={busy}
                  className="rounded-md border border-border px-3 py-2 text-theme-sm text-ink-muted hover:bg-subtle disabled:opacity-50"
                >
                  {webhook.is_active ? "Pause" : "Resume"}
                </button>
                <button
                  type="button"
                  onClick={remove}
                  disabled={busy}
                  className="rounded-md border border-danger-ink/30 px-3 py-2 text-theme-sm text-danger-ink hover:bg-danger-surface disabled:opacity-50"
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
              <thead className="border-b border-border">
                <tr className="text-left text-theme-xs font-medium text-ink-subtle">
                  <th className="px-5 py-3">Event</th>
                  <th className="px-5 py-3">Order</th>
                  <th className="px-5 py-3">Status</th>
                  <th className="px-5 py-3">Attempts</th>
                  <th className="px-5 py-3">Last code</th>
                  <th className="px-5 py-3">When</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {deliveries.map((d) => (
                  <tr key={d.id} className="text-ink-muted">
                    <td className="px-5 py-4 font-mono text-theme-xs">{d.event}</td>
                    <td className="px-5 py-4">{d.order_number ?? "—"}</td>
                    <td className="px-5 py-4">
                      <StatusTag severity={DELIVERY_SEVERITY[d.status]}>{d.status}</StatusTag>
                    </td>
                    <td className="px-5 py-4">{d.attempts}</td>
                    <td className="px-5 py-4">{d.last_response_code ?? "—"}</td>
                    <td className="px-5 py-4 text-ink-subtle">{formatDateTime(d.created_at)}</td>
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
