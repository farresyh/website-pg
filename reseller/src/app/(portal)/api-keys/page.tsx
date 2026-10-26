"use client";

/**
 * ADR-072 decision 5 / PR-G planning addendum decision 7: full
 * self-service Reseller API key management — plaintext-once, mirrors
 * `admin/src/components/resellers/ResellerApiKeysModal.tsx`'s shape
 * adapted to this app's own read-screen design system.
 */

import { Fragment, useEffect, useState } from "react";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import {
  listApiKeys,
  issueApiKey,
  revokeApiKey,
  updateApiKeyAllowedIps,
  type ResellerApiKeyRow,
} from "@/lib/reseller-portal";
import { formatDateTime } from "@/lib/format";
import { PageHeader, Panel, ErrorNote, EmptyRow, inputClass, buttonClass, Badge } from "@/components/ui";
import WebhookPanel from "./WebhookPanel";

export default function ApiKeysPage() {
  const [keys, setKeys] = useState<ResellerApiKeyRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [name, setName] = useState("");
  const [issuing, setIssuing] = useState(false);
  const [revokingId, setRevokingId] = useState<number | null>(null);
  const [freshPlainTextKey, setFreshPlainTextKey] = useState<string | null>(null);
  const [ipEditId, setIpEditId] = useState<number | null>(null);
  const [ipDraft, setIpDraft] = useState("");
  const [savingIps, setSavingIps] = useState(false);

  function refresh() {
    const session = getClientSession();
    if (!session) return;

    return listApiKeys(session.token)
      .then(setKeys)
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load API keys."));
  }

  useEffect(() => {
    refresh();
  }, []);

  async function handleIssue(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    setFreshPlainTextKey(null);

    const session = getClientSession();
    if (!session) return;

    setIssuing(true);
    try {
      const issued = await issueApiKey(session.token, name);
      setFreshPlainTextKey(issued.plain_text_key);
      setName("");
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not issue a new key.");
    } finally {
      setIssuing(false);
    }
  }

  function openIpEditor(key: ResellerApiKeyRow) {
    setIpEditId(key.id);
    setIpDraft(key.allowed_ips.join("\n"));
    setError(null);
  }

  async function handleSaveIps(key: ResellerApiKeyRow) {
    const session = getClientSession();
    if (!session) return;

    const ips = ipDraft
      .split(/[\s,]+/)
      .map((s) => s.trim())
      .filter(Boolean);

    setSavingIps(true);
    setError(null);
    try {
      await updateApiKeyAllowedIps(session.token, key.id, ips);
      setIpEditId(null);
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save the IP allowlist.");
    } finally {
      setSavingIps(false);
    }
  }

  async function handleRevoke(key: ResellerApiKeyRow) {
    const session = getClientSession();
    if (!session) return;

    setError(null);
    setRevokingId(key.id);
    try {
      await revokeApiKey(session.token, key.id);
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not revoke this key.");
    } finally {
      setRevokingId(null);
    }
  }

  const activeCount = (keys ?? []).filter((k) => !k.revoked_at).length;

  return (
    <div>
      <PageHeader
        title="API Keys"
        subtitle="Connect your own system to the Reseller API — up to 5 active keys."
      />

      {error && <ErrorNote message={error} />}

      {freshPlainTextKey && (
        <div className="mb-6 space-y-2 rounded-lg border border-warning-ink/30 bg-warning-surface p-4">
          <p className="text-theme-xs font-medium text-warning-ink">
            Copy this key now — it will never be shown again.
          </p>
          <code className="block break-all rounded-md bg-surface px-3 py-2 text-theme-sm text-ink">
            {freshPlainTextKey}
          </code>
          <button
            type="button"
            onClick={() => navigator.clipboard.writeText(freshPlainTextKey)}
            className="rounded-md border border-border px-3 py-1.5 text-theme-xs text-ink-muted hover:bg-subtle"
          >
            Copy
          </button>
        </div>
      )}

      <Panel title={`Issue a new key (${activeCount}/5 active)`}>
        <form onSubmit={handleIssue} className="flex items-end gap-3 p-5">
          <label className="flex-1 space-y-1.5">
            <span className="text-theme-sm font-medium text-ink-muted">
              Label
            </span>
            <input
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Production key"
              required
              className={inputClass}
            />
          </label>
          <button
            type="submit"
            disabled={issuing || activeCount >= 5}
            className={buttonClass("primary")}
          >
            {issuing ? "Issuing…" : "Issue key"}
          </button>
        </form>
      </Panel>

      <div className="mt-6">
        <Panel>
          <div className="max-w-full overflow-x-auto">
            <table className="min-w-full text-theme-sm">
              <thead className="border-b border-border">
                <tr className="text-left text-theme-xs font-medium text-ink-subtle">
                  <th className="px-5 py-3">Label</th>
                  <th className="px-5 py-3">Last used</th>
                  <th className="px-5 py-3">IP allowlist</th>
                  <th className="px-5 py-3">Status</th>
                  <th className="px-5 py-3" />
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {(keys ?? []).map((key) => (
                  <Fragment key={key.id}>
                    <tr className="text-ink-muted">
                      <td className="px-5 py-4">{key.name}</td>
                      <td className="px-5 py-4 text-ink-subtle">
                        {key.last_used_at ? formatDateTime(key.last_used_at) : "Never"}
                        {key.last_used_ip && (
                          <span className="block text-theme-xs text-ink-subtle">from {key.last_used_ip}</span>
                        )}
                      </td>
                      <td className="px-5 py-4 text-ink-subtle">
                        {key.revoked_at ? (
                          "—"
                        ) : key.allowed_ips.length === 0 ? (
                          <button
                            type="button"
                            onClick={() => openIpEditor(key)}
                            className="text-theme-xs text-ink-subtle underline decoration-dotted hover:text-ink-muted"
                          >
                            Any IP — restrict
                          </button>
                        ) : (
                          <button
                            type="button"
                            onClick={() => openIpEditor(key)}
                            className="text-theme-xs text-cyan-ink hover:underline"
                          >
                            {key.allowed_ips.length} IP{key.allowed_ips.length > 1 ? "s" : ""} — edit
                          </button>
                        )}
                      </td>
                      <td className="px-5 py-4">
                        {key.revoked_at ? (
                          <span className="flex flex-col items-start gap-0.5">
                            <Badge tone="neutral">Revoked</Badge>
                            <span className="text-xs text-ink-subtle">{formatDateTime(key.revoked_at)}</span>
                          </span>
                        ) : (
                          <Badge tone="success">Active</Badge>
                        )}
                      </td>
                      <td className="px-5 py-4 text-right">
                        {!key.revoked_at && (
                          <button
                            type="button"
                            disabled={revokingId === key.id}
                            onClick={() => handleRevoke(key)}
                            className="rounded-md border border-danger-ink/30 px-3 py-1.5 text-theme-xs text-danger-ink hover:bg-danger-surface disabled:opacity-50"
                          >
                            {revokingId === key.id ? "…" : "Revoke"}
                          </button>
                        )}
                      </td>
                    </tr>
                    {ipEditId === key.id && (
                      <tr className="bg-subtle">
                        <td colSpan={5} className="px-5 py-4">
                          <div className="space-y-2">
                            <p className="text-theme-xs text-ink-subtle">
                              One IPv4/IPv6 address per line (or comma-separated). Leave empty to allow any IP.
                              Exact match only — no ranges.
                            </p>
                            <textarea
                              value={ipDraft}
                              onChange={(e) => setIpDraft(e.target.value)}
                              rows={4}
                              placeholder="203.0.113.7&#10;198.51.100.24"
                              className={`${inputClass} font-mono text-xs`}
                            />
                            <div className="flex gap-2">
                              <button
                                type="button"
                                onClick={() => handleSaveIps(key)}
                                disabled={savingIps}
                                className={buttonClass("primary", true)}
                              >
                                {savingIps ? "Saving…" : "Save"}
                              </button>
                              <button
                                type="button"
                                onClick={() => setIpEditId(null)}
                                className="rounded-md border border-border px-3 py-1.5 text-theme-xs text-ink-muted hover:bg-subtle"
                              >
                                Cancel
                              </button>
                            </div>
                          </div>
                        </td>
                      </tr>
                    )}
                  </Fragment>
                ))}
              </tbody>
            </table>
            {keys !== null && keys.length === 0 && <EmptyRow>No API keys yet.</EmptyRow>}
          </div>
        </Panel>
      </div>

      <WebhookPanel />
    </div>
  );
}
