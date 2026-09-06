"use client";

/**
 * ADR-060 PR-5 — the affiliate Domains screen. Self-serve: add a custom
 * domain, follow the CNAME instructions, "Check now" until it goes
 * active, pick which one is primary.
 *
 * Provider-opaque (ADR-060 addendum section C): the backend has already
 * sanitised every string. This screen shows `dns.cname_target` (a
 * PekanGame-owned alias) and never names a hosting provider.
 */

import { useEffect, useState } from "react";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import {
  getDomains,
  addDomain,
  recheckDomain,
  setPrimaryDomain,
  removeDomain,
  type AffiliateDomainRow,
  type DomainsResponse,
} from "@/lib/portal";
import { formatDateTime } from "@/lib/format";
import { PageHeader, Panel, ErrorNote, EmptyRow, StatusTag } from "@/components/ui";

const STATUS_SEVERITY: Record<AffiliateDomainRow["status"], string> = {
  pending: "warn",
  active: "success",
  failed: "danger",
  suspended: "muted",
};

const STATUS_HINT: Record<AffiliateDomainRow["status"], string> = {
  pending: "Waiting for your DNS records. This can take a few minutes after you set them.",
  active: "Live — your storefront is being served on this domain.",
  failed: "We could not verify this domain. Check the DNS records and try again.",
  suspended: "Your account is inactive, so this domain is not serving. Contact support.",
};

export default function DomainsPage() {
  const [data, setData] = useState<DomainsResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [hostname, setHostname] = useState("");
  const [adding, setAdding] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [copied, setCopied] = useState(false);

  function refresh() {
    const session = getClientSession();
    if (!session) return;

    return getDomains(session.token)
      .then(setData)
      .catch((err: unknown) =>
        setError(err instanceof ApiError ? err.message : "Could not load your domains."),
      );
  }

  useEffect(() => {
    refresh();
  }, []);

  async function handleAdd(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    const session = getClientSession();
    if (!session) return;

    setAdding(true);
    try {
      await addDomain(session.token, hostname.trim());
      setHostname("");
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not add that domain.");
    } finally {
      setAdding(false);
    }
  }

  async function run(id: number, action: (token: string, id: number) => Promise<unknown>) {
    setError(null);
    const session = getClientSession();
    if (!session) return;

    setBusyId(id);
    try {
      await action(session.token, id);
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "That action could not be completed.");
    } finally {
      setBusyId(null);
    }
  }

  const domains = data?.domains ?? [];
  const atCap = data !== null && domains.length >= data.max_domains;
  const canWrite = data?.writable ?? false;

  return (
    <div>
      <PageHeader
        title="Domains"
        subtitle="Serve your storefront on your own domain. Add a domain, set the DNS record shown below, then check back here."
      />

      {error && <ErrorNote message={error} />}

      {data && (
        <Panel title="How to point your domain">
          <div className="space-y-3 p-5 text-theme-sm text-gray-600 dark:text-gray-300">
            <p>
              For <code className="rounded bg-gray-100 px-1 dark:bg-gray-800">shop.yourbrand.com</code> or{" "}
              <code className="rounded bg-gray-100 px-1 dark:bg-gray-800">www.yourbrand.com</code>, add a{" "}
              <strong>CNAME</strong> record at your DNS provider pointing to:
            </p>
            <div className="flex items-center gap-3">
              <code className="rounded-md bg-white px-3 py-2 text-gray-800 dark:bg-gray-900 dark:text-white/90">
                {data.dns.cname_target}
              </code>
              <button
                type="button"
                onClick={() => {
                  navigator.clipboard.writeText(data.dns.cname_target);
                  setCopied(true);
                  setTimeout(() => setCopied(false), 1500);
                }}
                className="rounded-lg border border-gray-200 px-3 py-1.5 text-theme-xs text-gray-600 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
              >
                {copied ? "Copied" : "Copy"}
              </button>
            </div>
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">
              Using a root domain (<code>yourbrand.com</code> with no prefix)? If your DNS provider supports
              CNAME flattening or ALIAS records, point that at the same target. Otherwise use an A record to{" "}
              <code>{data.dns.apex_a_record}</code>. A <code>www</code> or <code>shop</code> subdomain is the
              simplest path.
            </p>
          </div>
        </Panel>
      )}

      <div className="mt-6">
        <Panel title={data ? `Add a domain (${domains.length}/${data.max_domains})` : "Add a domain"}>
          <form onSubmit={handleAdd} className="flex flex-wrap items-end gap-3 p-5">
            <label className="flex-1 space-y-1.5">
              <span className="text-theme-sm font-medium text-gray-700 dark:text-gray-300">Domain</span>
              <input
                value={hostname}
                onChange={(e) => setHostname(e.target.value)}
                placeholder="shop.yourbrand.com"
                required
                disabled={!canWrite || atCap}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-theme-sm text-gray-800 outline-none focus:border-brand-400 disabled:opacity-50 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
              />
            </label>
            <button
              type="submit"
              disabled={adding || !canWrite || atCap}
              className="rounded-lg bg-brand-500 px-4 py-2.5 text-theme-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
            >
              {adding ? "Adding…" : "Add domain"}
            </button>
            {!canWrite && (
              <p className="w-full text-theme-xs text-warning-600 dark:text-warning-500">
                Your account is inactive — domains are read-only. Contact support.
              </p>
            )}
          </form>
        </Panel>
      </div>

      <div className="mt-6">
        <Panel>
          <div className="max-w-full overflow-x-auto">
            <table className="min-w-full text-theme-sm">
              <thead className="border-b border-gray-100 dark:border-gray-800">
                <tr className="text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                  <th className="px-5 py-3">Domain</th>
                  <th className="px-5 py-3">Status</th>
                  <th className="px-5 py-3">Last checked</th>
                  <th className="px-5 py-3" />
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                {domains.map((d) => (
                  <tr key={d.id} className="align-top text-gray-600 dark:text-gray-300">
                    <td className="px-5 py-4">
                      <div className="font-medium text-gray-800 dark:text-white/90">{d.hostname}</div>
                      {d.is_primary && (
                        <span className="mt-1 inline-flex rounded-full bg-brand-50 px-2 py-0.5 text-theme-xs font-medium text-brand-600 dark:bg-brand-500/15 dark:text-brand-400">
                          Primary
                        </span>
                      )}
                      {d.verification.length > 0 && (
                        <div className="mt-2 space-y-1 rounded-md bg-gray-50 p-2 text-theme-xs dark:bg-white/5">
                          <p className="font-medium text-gray-600 dark:text-gray-300">
                            Also add this record to confirm ownership:
                          </p>
                          {d.verification.map((v, i) => (
                            <code key={i} className="block break-all text-gray-500 dark:text-gray-400">
                              {v.type} {v.domain} → {v.value}
                            </code>
                          ))}
                        </div>
                      )}
                    </td>
                    <td className="px-5 py-4">
                      <StatusTag severity={STATUS_SEVERITY[d.status]}>{d.status}</StatusTag>
                      <p className="mt-1 max-w-xs text-theme-xs text-gray-500 dark:text-gray-400">
                        {d.last_error ?? STATUS_HINT[d.status]}
                      </p>
                    </td>
                    <td className="px-5 py-4 text-gray-500 dark:text-gray-400">
                      {d.last_checked_at ? formatDateTime(d.last_checked_at) : "—"}
                    </td>
                    <td className="px-5 py-4">
                      <div className="flex flex-wrap justify-end gap-2">
                        <button
                          type="button"
                          disabled={busyId === d.id}
                          onClick={() => run(d.id, recheckDomain)}
                          className="rounded-lg border border-gray-200 px-3 py-1.5 text-theme-xs text-gray-600 hover:bg-gray-100 disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
                        >
                          {busyId === d.id ? "…" : "Check now"}
                        </button>
                        {canWrite && d.status === "active" && !d.is_primary && (
                          <button
                            type="button"
                            disabled={busyId === d.id}
                            onClick={() => run(d.id, setPrimaryDomain)}
                            className="rounded-lg border border-gray-200 px-3 py-1.5 text-theme-xs text-gray-600 hover:bg-gray-100 disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
                          >
                            Set primary
                          </button>
                        )}
                        {canWrite && (
                          <button
                            type="button"
                            disabled={busyId === d.id}
                            onClick={() => run(d.id, removeDomain)}
                            className="rounded-lg border border-error-200 px-3 py-1.5 text-theme-xs text-error-600 hover:bg-error-50 disabled:opacity-50 dark:border-error-500/30 dark:text-error-400 dark:hover:bg-error-500/10"
                          >
                            Remove
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {data !== null && domains.length === 0 && (
              <EmptyRow>No domains yet. Add one above to serve your storefront on your own address.</EmptyRow>
            )}
          </div>
        </Panel>
      </div>
    </div>
  );
}
