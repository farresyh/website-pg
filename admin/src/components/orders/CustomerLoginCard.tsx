"use client";

/**
 * ADR-124: the customer's game login on Order Detail. Every admin sees
 * the non-secret fields; a super admin can reveal a secret field (MFA
 * step-up, logged, rate-limited) or replace it while the order is open.
 * A revealed value hides again after 30 seconds — a courtesy only, the
 * real controls are server-side. Renders nothing for an order without a
 * login. Key it by order id so a revealed value never carries over.
 */

import { useEffect, useRef, useState } from "react";
import { Lock } from "@primeicons/react/lock";
import { Eye } from "@primeicons/react/eye";
import { Copy } from "@primeicons/react/copy";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { ApiError } from "@/lib/api-client";
import { replaceOrderCredential, revealOrderCredential, type CustomerLogin } from "@/lib/order-credentials";

const REVEAL_SECONDS = 30;

function formatWhen(iso: string): string {
  return new Date(iso).toLocaleString("en-MY", { timeZone: "Asia/Kuala_Lumpur", dateStyle: "medium", timeStyle: "short" });
}

export default function CustomerLoginCard({
  orderId,
  login,
  canManage,
}: {
  orderId: number;
  login: CustomerLogin | null | undefined;
  canManage: boolean;
}) {
  const [current, setCurrent] = useState(login ?? null);
  const [revealed, setRevealed] = useState<{ field: string; value: string } | null>(null);
  const [replacing, setReplacing] = useState<string | null>(null);
  const [newValue, setNewValue] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);
  const hideTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => () => {
    if (hideTimer.current) clearTimeout(hideTimer.current);
  }, []);

  if (!current) return null;

  const wiped = current.purged_at !== null;

  async function reveal(field: string) {
    setBusy(true);
    setError(null);
    try {
      const result = await revealOrderCredential(orderId, field);
      setRevealed(result);
      setCopied(false);
      if (hideTimer.current) clearTimeout(hideTimer.current);
      hideTimer.current = setTimeout(() => setRevealed(null), REVEAL_SECONDS * 1000);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not reveal this field.");
    } finally {
      setBusy(false);
    }
  }

  async function copy(value: string) {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(true);
    } catch {
      setError("Could not copy. Select the value and copy it by hand.");
    }
  }

  async function replace(field: string) {
    if (!newValue) return;
    setBusy(true);
    setError(null);
    try {
      setCurrent(await replaceOrderCredential(orderId, field, newValue));
      setReplacing(null);
      setNewValue("");
      setRevealed(null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not replace this field.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="rounded-2xl border border-gray-200 bg-surface p-6 dark:border-gray-800">
      <h2 className="mb-1 flex items-center gap-2 text-section-title font-semibold text-ink">
        <Lock className="h-4 w-4 text-ink-muted" />
        Customer Login
      </h2>
      <p className="mb-4 text-theme-xs text-ink-muted">
        {wiped
          ? `Wiped ${formatWhen(current.purged_at as string)}. Ask the customer to send it again if the order still needs it.`
          : `Saved ${formatWhen(current.secrets_set_at)}. Wiped automatically when the order no longer needs it.`}
      </p>

      {error && (
        <p className="mb-3 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <dl className="space-y-3 text-sm">
        {current.fields.map((f) => (
          <div key={f.key} className="flex justify-between gap-4">
            <dt className="text-ink-muted">{f.label}</dt>
            <dd className="break-all text-right font-mono text-ink">{f.value}</dd>
          </div>
        ))}

        {current.secret_fields.map((f) => {
          const shown = revealed?.field === f.key ? revealed.value : null;

          return (
            <div key={f.key} className="space-y-2">
              <div className="flex items-center justify-between gap-4">
                <dt className="text-ink-muted">{f.label}</dt>
                <dd className="flex items-center gap-2">
                  <span className="break-all font-mono text-ink">{shown ?? (wiped ? "Wiped" : "••••••••")}</span>
                  {canManage && shown && (
                    <Button type="button" variant="text" size="small" onClick={() => copy(shown)} aria-label={`Copy ${f.label}`}>
                      <Copy />
                      {copied ? "Copied" : "Copy"}
                    </Button>
                  )}
                  {canManage && !wiped && !shown && (
                    <Button type="button" variant="outlined" size="small" onClick={() => reveal(f.key)} disabled={busy}>
                      <Eye />
                      Reveal
                    </Button>
                  )}
                  {canManage && replacing !== f.key && (
                    <Button type="button" variant="text" size="small" onClick={() => { setReplacing(f.key); setNewValue(""); }} disabled={busy}>
                      Replace
                    </Button>
                  )}
                </dd>
              </div>

              {canManage && replacing === f.key && (
                <div className="flex items-center gap-2">
                  <Input
                    type="password"
                    autoComplete="new-password"
                    aria-label={`New ${f.label}`}
                    value={newValue}
                    onChange={(e) => setNewValue(e.target.value)}
                    className="flex-1"
                  />
                  <Button type="button" size="small" onClick={() => replace(f.key)} disabled={busy || !newValue}>
                    Save
                  </Button>
                  <Button type="button" variant="text" size="small" onClick={() => setReplacing(null)} disabled={busy}>
                    Cancel
                  </Button>
                </div>
              )}
            </div>
          );
        })}
      </dl>

      <p className="mt-4 border-t border-gray-200 pt-3 text-theme-xs text-ink-muted dark:border-gray-800">
        Customer agreed {formatWhen(current.consented_at)}: &ldquo;{current.consent_text}&rdquo;
      </p>
    </div>
  );
}
