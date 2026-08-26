"use client";

/** ADR-029 addendum 2 decision 14: per-bot allow/block, feeding native app/robots.ts. */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Table, TableHeader, TableBody, TableRow, TableCell } from "@/components/ui/table";
import Badge from "@/components/ui/badge/Badge";
import Button from "@/components/ui/button/Button";
import { PlusIcon } from "@/icons";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
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
  const [defaultsSaved, setDefaultsSaved] = useState(false);

  function refresh(token: string) {
    return listCrawlerRules(token)
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
    refresh(s.token);
    getSeoSettings(s.token)
      .then((settings) => setDefaultDisallow((settings.crawler_default_disallow_paths ?? []).join("\n")))
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load default disallow paths."));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function handleSaveDefaults() {
    if (!session) return;
    setSavingDefaults(true);
    setDefaultsSaved(false);
    try {
      const paths = defaultDisallow.split("\n").map((p) => p.trim()).filter(Boolean);
      await updateSeoSettings(session.token, { crawler_default_disallow_paths: paths });
      setDefaultsSaved(true);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save default disallow paths.");
    } finally {
      setSavingDefaults(false);
    }
  }

  async function handleSubmit(values: Parameters<typeof createCrawlerRule>[1]) {
    if (!session) return;
    if (editing) {
      await updateCrawlerRule(session.token, editing.id, values);
    } else {
      await createCrawlerRule(session.token, values);
    }
    setModalOpen(false);
    setEditing(null);
    await refresh(session.token);
  }

  async function handleDelete(rule: CrawlerRule) {
    if (!session) return;
    setDeletingId(rule.id);
    try {
      await deleteCrawlerRule(session.token, rule.id);
      await refresh(session.token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not delete rule.");
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
        <Button size="sm" startIcon={<PlusIcon />} onClick={() => { setEditing(null); setModalOpen(true); }}>
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
          <Button size="sm" onClick={handleSaveDefaults} disabled={savingDefaults}>
            {savingDefaults ? "Saving…" : "Save Defaults"}
          </Button>
          {defaultsSaved && <span className="text-sm text-success-600 dark:text-success-400">Saved.</span>}
        </div>
      </div>

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <Table>
            <TableHeader className="border-b border-gray-100 dark:border-gray-800">
              <TableRow>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Bot</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">User-agent</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Crawl delay</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</TableCell>
              </TableRow>
            </TableHeader>
            <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
              {rules?.map((r) => (
                <TableRow key={r.id}>
                  <TableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">{r.bot_name}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{r.user_agent}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">
                    <Badge size="sm" color={r.is_allowed ? "success" : "error"}>{r.is_allowed ? "Allowed" : "Blocked"}</Badge>
                  </TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{r.crawl_delay ?? "—"}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">
                    <div className="flex gap-3">
                      <button type="button" className="text-brand-500 hover:underline" onClick={() => { setEditing(r); setModalOpen(true); }}>Edit</button>
                      <button type="button" className="text-error-500 hover:underline" disabled={deletingId === r.id} onClick={() => handleDelete(r)}>Delete</button>
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>

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
