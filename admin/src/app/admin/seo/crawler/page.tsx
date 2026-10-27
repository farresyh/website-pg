"use client";

/** ADR-029 addendum 2 decision 14: per-bot allow/block, feeding native app/robots.ts. */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import {
  DataTable,
  DataTableTableContainer,
  DataTableTable,
  DataTableTHead,
  DataTableTHeadRow,
  DataTableTHeadCell,
  DataTableTBody,
  DataTableRow,
  DataTableCell,
} from "@/components/ui/datatable";
import { Tag } from "@/components/ui/tag";
import { Button } from "@/components/ui/button";
import { Plus as PlusIcon } from "@primeicons/react/plus";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { notify } from "@/components/ui/toast";
import { listCrawlerRules, createCrawlerRule, updateCrawlerRule, deleteCrawlerRule, getSeoSettings, updateSeoSettings, type CrawlerRule } from "@/lib/seo";
import SaveCrawlerRuleModal from "@/components/seo/SaveCrawlerRuleModal";

export default function CrawlerRulesPage() {
  const router = useRouter();
  const session = useClientSession();
  const [rules, setRules] = useState<CrawlerRule[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<CrawlerRule | null>(null);
  const [deletingId, setDeletingId] = useState<number | null>(null);

  const [defaultDisallow, setDefaultDisallow] = useState("");
  const [savingDefaults, setSavingDefaults] = useState(false);

  function refresh() {
    return listCrawlerRules()
      .then(setRules)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load crawler rules.");
      });
  }

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    refresh();
    getSeoSettings()
      .then((settings) => setDefaultDisallow((settings.crawler_default_disallow_paths ?? []).join("\n")))
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load default disallow paths."));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function handleSaveDefaults() {
    if (!session) return;
    setSavingDefaults(true);
    try {
      const paths = defaultDisallow.split("\n").map((p) => p.trim()).filter(Boolean);
      await updateSeoSettings({ crawler_default_disallow_paths: paths });
      notify.success("Default disallow paths saved");
    } catch (err) {
      notify.error("Could not save default disallow paths", err instanceof ApiError ? err.message : "Action failed.");
    } finally {
      setSavingDefaults(false);
    }
  }

  async function handleSubmit(values: Parameters<typeof createCrawlerRule>[0]) {
    if (!session) return;
    if (editing) {
      await updateCrawlerRule(editing.id, values);
      notify.success("Crawler rule updated");
    } else {
      await createCrawlerRule(values);
      notify.success("Crawler rule created");
    }
    setModalOpen(false);
    setEditing(null);
    await refresh();
  }

  async function handleDelete(rule: CrawlerRule) {
    if (!session) return;
    setDeletingId(rule.id);
    try {
      await deleteCrawlerRule(rule.id);
      notify.success("Crawler rule deleted");
      await refresh();
    } catch (err) {
      notify.error("Could not delete rule", err instanceof ApiError ? err.message : "Action failed.");
    } finally {
      setDeletingId(null);
    }
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Crawler</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Per-bot robots.txt rules — search engines, social-preview bots, AI-training bots.</p>
        </div>
        <Button size="small" onClick={() => { setEditing(null); setModalOpen(true); }}>
          <PlusIcon />
          Add Custom Rule
        </Button>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <div className="mb-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <h2 className="mb-1 text-sm font-semibold text-gray-800 dark:text-white/90">Default Disallow Paths (all bots)</h2>
        <p className="mb-4 text-theme-xs text-gray-500 dark:text-gray-400">
          A robots.txt rule for a named bot (Googlebot, GPTBot, etc.) never inherits from &quot;*&quot; — a path here is merged into
          every <strong>Allowed</strong> bot below automatically, including ones added later, so it never needs re-entering per row.
        </p>
        <textarea
          rows={3}
          value={defaultDisallow}
          onChange={(e) => setDefaultDisallow(e.target.value)}
          placeholder={"/order/status\n/api"}
          className="w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 font-mono text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
        />
        <div className="mt-3 flex items-center gap-3">
          <Button size="small" onClick={handleSaveDefaults} disabled={savingDefaults}>
            {savingDefaults ? "Saving…" : "Save Defaults"}
          </Button>
        </div>
      </div>

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={rules ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Bot</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">User-agent</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Crawl delay</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item }) => {
                    const r = item as unknown as CrawlerRule;

                    return (
                      <DataTableRow key={r.id}>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">{r.bot_name}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{r.user_agent}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <Tag severity={r.is_allowed ? "success" : "danger"}>{r.is_allowed ? "Allowed" : "Blocked"}</Tag>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{r.crawl_delay ?? "—"}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <div className="flex gap-3">
                            <button type="button" className="text-brand-500 hover:underline" onClick={() => { setEditing(r); setModalOpen(true); }}>Edit</button>
                            <button type="button" className="text-danger-ink hover:underline" disabled={deletingId === r.id} onClick={() => handleDelete(r)}>Delete</button>
                          </div>
                        </DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>

          {rules?.length === 0 && <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No crawler rules yet.</p>}
          {rules === null && !error && <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>}
        </div>
      </div>

      <SaveCrawlerRuleModal
        isOpen={modalOpen}
        rule={editing}
        onClose={() => { setModalOpen(false); setEditing(null); }}
        onSubmit={handleSubmit}
      />
    </div>
  );
}
