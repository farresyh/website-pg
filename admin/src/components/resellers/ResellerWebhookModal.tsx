"use client";

/**
 * ADR-084 PR-3 decision 10: admin support-side view/set/rotate/disable of
 * a Reseller's delivery webhook, plus its dead-letter delivery log. The
 * reseller self-manages the same from the portal's API Keys screen; this
 * is the parallel support path. Mirrors ResellerApiKeysModal's shape.
 */

import React, { useEffect, useState } from "react";
import {
  Dialog,
  DialogPortal,
  DialogBackdrop,
  DialogPositioner,
  DialogPopup,
  DialogHeader,
  DialogHeaderActions,
  DialogClose,
  DialogTitle,
  DialogContent,
} from "@/components/ui/dialog";
import { Times as CloseIcon } from "@primeicons/react/times";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Tag } from "@/components/ui/tag";
import { ApiError } from "@/lib/api-client";
import {
  getResellerWebhook,
  setResellerWebhook,
  rotateResellerWebhookSecret,
  setResellerWebhookActive,
  deleteResellerWebhook,
  listResellerWebhookDeliveries,
  type ResellerRow,
  type ResellerWebhook,
  type ResellerWebhookDelivery,
} from "@/lib/resellers";

interface Props {
  isOpen: boolean;
  onClose: () => void;
  token: string;
  reseller: ResellerRow;
}

function formatDate(iso: string | null): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleString("en-MY", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
}

const DELIVERY_SEVERITY: Record<ResellerWebhookDelivery["status"], "info" | "success" | "warn" | "danger"> = {
  pending: "info",
  delivered: "success",
  failed: "warn",
  exhausted: "danger",
};

function Content({ token, reseller }: Omit<Props, "isOpen" | "onClose">) {
  const [webhook, setWebhook] = useState<ResellerWebhook | null>(null);
  const [deliveries, setDeliveries] = useState<ResellerWebhookDelivery[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [url, setUrl] = useState("");
  const [busy, setBusy] = useState(false);
  const [freshSecret, setFreshSecret] = useState<string | null>(null);

  function refresh() {
    return Promise.all([getResellerWebhook(token, reseller.id), listResellerWebhookDeliveries(token, reseller.id)])
      .then(([{ webhook: hook }, page]) => {
        setWebhook(hook);
        setUrl(hook?.url ?? "");
        setDeliveries(page.data);
      })
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load the webhook."))
      .finally(() => setLoaded(true));
  }

  useEffect(() => {
    refresh();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [reseller.id]);

  function run(fn: () => Promise<unknown>, fallback: string) {
    setError(null);
    setBusy(true);
    return Promise.resolve(fn())
      .then(() => refresh())
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : fallback))
      .finally(() => setBusy(false));
  }

  const save = () =>
    run(async () => {
      const { secret } = await setResellerWebhook(token, reseller.id, url.trim());
      if (secret) setFreshSecret(secret);
    }, "Could not save the endpoint.");

  const rotate = () =>
    run(async () => {
      const { secret } = await rotateResellerWebhookSecret(token, reseller.id);
      setFreshSecret(secret);
    }, "Could not rotate the secret.");

  const toggle = () => run(() => setResellerWebhookActive(token, reseller.id, !webhook!.is_active), "Could not update the endpoint.");

  const remove = () =>
    run(async () => {
      await deleteResellerWebhook(token, reseller.id);
      setFreshSecret(null);
    }, "Could not remove the endpoint.");

  if (!loaded) return <p className="text-sm text-gray-400">Loading…</p>;

  return (
    <div className="space-y-6">
      {error && (
        <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      {freshSecret && (
        <div className="space-y-2 rounded-lg border border-warning-300 bg-warning-50 p-4 dark:border-warning-500/30 dark:bg-warning-500/10">
          <p className="text-theme-xs font-medium text-warning-700 dark:text-warning-400">
            Copy this signing secret now — it will never be shown again. Hand it to the reseller over a secure channel.
          </p>
          <code className="block break-all rounded-md bg-white px-3 py-2 text-theme-sm text-gray-800 dark:bg-gray-900 dark:text-white/90">
            {freshSecret}
          </code>
          <Button type="button" size="small" variant="outlined" onClick={() => navigator.clipboard.writeText(freshSecret)}>
            Copy
          </Button>
        </div>
      )}

      <div className="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-800">
        <div className="flex items-center justify-between">
          <p className="text-theme-xs font-medium text-gray-600 dark:text-gray-400">
            Delivery webhook — <code>order.delivered</code> / <code>order.failed</code> / <code>order.refunded</code>, signed{" "}
            <code>X-Hub-Signature-256</code>.
          </p>
          {webhook && <Tag severity={webhook.is_active ? "success" : "secondary"}>{webhook.is_active ? "Active" : "Paused"}</Tag>}
        </div>
        <div>
          <Label htmlFor="webhook_url">Endpoint URL (HTTPS)</Label>
          <Input
            id="webhook_url"
            value={url}
            onChange={(e) => setUrl(e.target.value)}
            placeholder="https://reseller-system.example/webhooks/pekangame"
          />
        </div>
        <div className="flex flex-wrap gap-2">
          <Button
            type="button"
            size="small"
            disabled={busy || url.trim() === "" || url.trim() === (webhook?.url ?? "")}
            onClick={save}
          >
            {webhook ? "Update URL" : "Save endpoint"}
          </Button>
          {webhook && (
            <>
              <Button type="button" size="small" variant="outlined" disabled={busy} onClick={rotate}>
                Rotate secret
              </Button>
              <Button type="button" size="small" variant="outlined" disabled={busy} onClick={toggle}>
                {webhook.is_active ? "Pause" : "Resume"}
              </Button>
              <Button type="button" size="small" severity="danger" disabled={busy} onClick={remove}>
                Remove
              </Button>
            </>
          )}
        </div>
      </div>

      <div className="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
        <table className="w-full text-left text-theme-sm">
          <thead className="bg-gray-50 dark:bg-gray-900">
            <tr>
              {["Event", "Order", "Status", "Attempts", "Last code", "When"].map((h) => (
                <th key={h} className="px-4 py-2 text-theme-xs font-medium text-gray-500 dark:text-gray-400">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
            {deliveries.map((d) => (
              <tr key={d.id}>
                <td className="px-4 py-2 font-mono text-theme-xs text-gray-700 dark:text-gray-300">{d.event}</td>
                <td className="px-4 py-2 text-gray-500 dark:text-gray-400">{d.order_number ?? "—"}</td>
                <td className="px-4 py-2"><Tag severity={DELIVERY_SEVERITY[d.status]}>{d.status}</Tag></td>
                <td className="px-4 py-2 text-gray-500 dark:text-gray-400">{d.attempts}</td>
                <td className="px-4 py-2 text-gray-500 dark:text-gray-400">{d.last_response_code ?? "—"}</td>
                <td className="px-4 py-2 text-gray-500 dark:text-gray-400">{formatDate(d.created_at)}</td>
              </tr>
            ))}
            {deliveries.length === 0 && (
              <tr>
                <td colSpan={6} className="px-4 py-6 text-center text-gray-400">No deliveries yet.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

export default function ResellerWebhookModal({ isOpen, onClose, token, reseller }: Props) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-2xl">
            <DialogHeader>
              <DialogTitle>{reseller.business_name} — Delivery Webhook</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && <Content key={reseller.id} token={token} reseller={reseller} />}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
