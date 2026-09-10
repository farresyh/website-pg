"use client";

/**
 * ADR-072 decision 5 / PR-G planning addendum decision 7: full
 * self-service Reseller API key management — plaintext-once, mirrors
 * `admin/src/components/resellers/ResellerApiKeysModal.tsx`'s shape
 * adapted to this app's own read-screen design system.
 */

import { useEffect, useState } from "react";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import {
  listApiKeys,
  issueApiKey,
  revokeApiKey,
  type ResellerApiKeyRow,
} from "@/lib/reseller-portal";
import { formatDateTime } from "@/lib/format";
import { PageHeader, Panel, ErrorNote, EmptyRow } from "@/components/ui";
import WebhookPanel from "./WebhookPanel";

export default function ApiKeysPage() {
  const [keys, setKeys] = useState<ResellerApiKeyRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [name, setName] = useState("");
  const [issuing, setIssuing] = useState(false);
  const [revokingId, setRevokingId] = useState<number | null>(null);
  const [freshPlainTextKey, setFreshPlainTextKey] = useState<string | null>(null);

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
        <div className="mb-6 space-y-2 rounded-lg border border-warning-300 bg-warning-50 p-4 dark:border-warning-500/30 dark:bg-warning-500/10">
          <p className="text-theme-xs font-medium text-warning-700 dark:text-warning-400">
            Copy this key now — it will never be shown again.
          </p>
          <code className="block break-all rounded-md bg-white px-3 py-2 text-theme-sm text-gray-800 dark:bg-gray-900 dark:text-white/90">
            {freshPlainTextKey}
          </code>
          <button
            type="button"
            onClick={() => navigator.clipboard.writeText(freshPlainTextKey)}
            className="rounded-lg border border-gray-200 px-3 py-1.5 text-theme-xs text-gray-600 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
          >
            Copy
          </button>
        </div>
      )}

      <Panel title={`Issue a new key (${activeCount}/5 active)`}>
        <form onSubmit={handleIssue} className="flex items-end gap-3 p-5">
          <label className="flex-1 space-y-1.5">
            <span className="text-theme-sm font-medium text-gray-700 dark:text-gray-300">
              Label
            </span>
            <input
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Production key"
              required
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-theme-sm text-gray-800 outline-none focus:border-brand-400 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
            />
          </label>
          <button
            type="submit"
            disabled={issuing || activeCount >= 5}
            className="rounded-lg bg-brand-500 px-4 py-2.5 text-theme-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
          >
            {issuing ? "Issuing…" : "Issue key"}
          </button>
        </form>
      </Panel>

      <div className="mt-6">
        <Panel>
          <div className="max-w-full overflow-x-auto">
            <table className="min-w-full text-theme-sm">
              <thead className="border-b border-gray-100 dark:border-gray-800">
                <tr className="text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                  <th className="px-5 py-3">Label</th>
                  <th className="px-5 py-3">Last used</th>
                  <th className="px-5 py-3">Status</th>
                  <th className="px-5 py-3" />
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                {(keys ?? []).map((key) => (
                  <tr key={key.id} className="text-gray-600 dark:text-gray-300">
                    <td className="px-5 py-4">{key.name}</td>
                    <td className="px-5 py-4 text-gray-500 dark:text-gray-400">
                      {key.last_used_at ? formatDateTime(key.last_used_at) : "Never"}
                    </td>
                    <td className="px-5 py-4">
                      {key.revoked_at ? (
                        <span className="text-error-600 dark:text-error-400">
                          Revoked {formatDateTime(key.revoked_at)}
                        </span>
                      ) : (
                        <span className="text-success-600 dark:text-success-400">Active</span>
                      )}
                    </td>
                    <td className="px-5 py-4 text-right">
                      {!key.revoked_at && (
                        <button
                          type="button"
                          disabled={revokingId === key.id}
                          onClick={() => handleRevoke(key)}
                          className="rounded-lg border border-error-200 px-3 py-1.5 text-theme-xs text-error-600 hover:bg-error-50 disabled:opacity-50 dark:border-error-500/30 dark:text-error-400 dark:hover:bg-error-500/10"
                        >
                          {revokingId === key.id ? "…" : "Revoke"}
                        </button>
                      )}
                    </td>
                  </tr>
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
