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
import { notify } from "@/components/ui/toast";
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
import { PageHeader, Panel, ErrorNote, EmptyRow, StatusTag } from "@/components/composites";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { DomainSetupGuide } from "@/components/domains/DomainSetupGuide";

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
      notify.success("Domain added");
      setHostname("");
      await refresh();
    } catch (err) {
      notify.error("Could not add that domain", err instanceof ApiError ? err.message : "Action failed.");
    } finally {
      setAdding(false);
    }
  }

  async function run(id: number, action: (token: string, id: number) => Promise<unknown>, success?: string) {
    const session = getClientSession();
    if (!session) return;

    setBusyId(id);
    try {
      await action(session.token, id);
      if (success) notify.success(success);
      await refresh();
    } catch (err) {
      notify.error("That action could not be completed", err instanceof ApiError ? err.message : "Action failed.");
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
          <DomainSetupGuide cnameTarget={data.dns.cname_target} apexARecord={data.dns.apex_a_record} />
        </Panel>
      )}

      <div className="mt-6">
        <Panel title={data ? `Add a domain (${domains.length}/${data.max_domains})` : "Add a domain"}>
          <form onSubmit={handleAdd} className="flex flex-wrap items-end gap-3 p-5">
            <label className="flex-1 space-y-1.5">
              <span className="text-theme-sm font-medium text-ink-muted">Domain</span>
              <Input
                value={hostname}
                onChange={(e) => setHostname(e.target.value)}
                placeholder="yourbrand.com"
                required
                disabled={!canWrite || atCap}
              />
            </label>
            <Button
              type="submit"
              disabled={adding || !canWrite || atCap}
            >
              {adding ? "Adding…" : "Add domain"}
            </Button>
            {!canWrite && (
              <p className="w-full text-theme-xs text-warning-ink">
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
              <thead className="border-b border-border">
                <tr className="text-left text-theme-xs font-medium text-ink-subtle">
                  <th className="px-5 py-3">Domain</th>
                  <th className="px-5 py-3">Status</th>
                  <th className="px-5 py-3">Last checked</th>
                  <th className="px-5 py-3" />
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {domains.map((d) => (
                  <tr key={d.id} className="align-top text-ink-muted">
                    <td className="px-5 py-4">
                      <div className="font-medium text-ink">{d.hostname}</div>
                      {d.is_primary && (
                        <span className="mt-1 inline-flex rounded-full bg-cyan-50 px-2 py-0.5 text-theme-xs font-medium text-cyan-ink">
                          Primary
                        </span>
                      )}
                      {d.verification.length > 0 && (
                        <div className="mt-2 space-y-1 rounded-md bg-subtle p-2 text-theme-xs">
                          <p className="font-medium text-ink-muted">
                            Also add this record to confirm ownership:
                          </p>
                          {d.verification.map((v, i) => (
                            <code key={i} className="block break-all text-ink-subtle">
                              {v.type} {v.domain} → {v.value}
                            </code>
                          ))}
                        </div>
                      )}
                    </td>
                    <td className="px-5 py-4">
                      <StatusTag severity={STATUS_SEVERITY[d.status]}>{d.status}</StatusTag>
                      <p className="mt-1 max-w-xs text-theme-xs text-ink-subtle">
                        {d.last_error ?? STATUS_HINT[d.status]}
                      </p>
                    </td>
                    <td className="px-5 py-4 text-ink-subtle">
                      {d.last_checked_at ? formatDateTime(d.last_checked_at) : "—"}
                    </td>
                    <td className="px-5 py-4">
                      <div className="flex flex-wrap justify-end gap-2">
                        <button
                          type="button"
                          disabled={busyId === d.id}
                          onClick={() => run(d.id, recheckDomain, "Domain re-checked")}
                          className="rounded-md border border-border px-3 py-1.5 text-theme-xs text-ink-muted hover:bg-subtle disabled:opacity-50"
                        >
                          {busyId === d.id ? "…" : "Check now"}
                        </button>
                        {canWrite && d.status === "active" && !d.is_primary && (
                          <button
                            type="button"
                            disabled={busyId === d.id}
                            onClick={() => run(d.id, setPrimaryDomain, "Primary domain set")}
                            className="rounded-md border border-border px-3 py-1.5 text-theme-xs text-ink-muted hover:bg-subtle disabled:opacity-50"
                          >
                            Set primary
                          </button>
                        )}
                        {canWrite && (
                          <button
                            type="button"
                            disabled={busyId === d.id}
                            onClick={() => run(d.id, removeDomain, "Domain removed")}
                            className="rounded-md border border-danger-ink/30 px-3 py-1.5 text-theme-xs text-danger-ink hover:bg-danger-surface disabled:opacity-50"
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
