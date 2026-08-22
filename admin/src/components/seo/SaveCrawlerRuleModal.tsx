"use client";

import React, { useState } from "react";
import { Modal } from "@/components/ui/modal";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import Button from "@/components/ui/button/Button";
import type { CrawlerRule, SaveCrawlerRuleValues } from "@/lib/seo";

interface Props {
  isOpen: boolean;
  rule: CrawlerRule | null;
  onClose: () => void;
  onSubmit: (values: SaveCrawlerRuleValues) => Promise<void>;
}

/** Renders as a child of <Modal>, which unmounts while closed — same fresh-mount-per-open reasoning as CreateBlacklistEntryModal. */
function Fields({ rule, onClose, onSubmit }: Omit<Props, "isOpen">) {
  const [botName, setBotName] = useState(rule?.bot_name ?? "");
  const [userAgent, setUserAgent] = useState(rule?.user_agent ?? "");
  const [isAllowed, setIsAllowed] = useState(rule?.is_allowed ?? true);
  const [crawlDelay, setCrawlDelay] = useState(rule?.crawl_delay?.toString() ?? "");
  const [disallowPaths, setDisallowPaths] = useState((rule?.disallow_paths ?? []).join("\n"));
  const [sortOrder, setSortOrder] = useState(String(rule?.sort_order ?? 0));
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await onSubmit({
        bot_name: botName,
        user_agent: userAgent,
        is_allowed: isAllowed,
        crawl_delay: crawlDelay ? Number(crawlDelay) : null,
        disallow_paths: disallowPaths.split("\n").map((p) => p.trim()).filter(Boolean),
        sort_order: Number(sortOrder),
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="max-w-md p-6">
      <h3 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">{rule ? "Edit Bot Rule" : "Add Custom Rule"}</h3>
      <p className="mb-5 text-sm text-gray-500 dark:text-gray-400">Feeds the storefront&apos;s /robots.txt via app/robots.ts.</p>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label htmlFor="bot_name">Bot name</Label>
          <Input id="bot_name" placeholder="GPTBot" value={botName} onChange={(e) => setBotName(e.target.value)} required />
        </div>
        <div>
          <Label htmlFor="user_agent">User-agent token</Label>
          <Input id="user_agent" placeholder="GPTBot" value={userAgent} onChange={(e) => setUserAgent(e.target.value)} required />
        </div>
        <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
          <input type="checkbox" checked={isAllowed} onChange={(e) => setIsAllowed(e.target.checked)} />
          Allowed to crawl
        </label>
        <div>
          <Label htmlFor="crawl_delay">Crawl delay (seconds, optional)</Label>
          <input
            id="crawl_delay"
            type="number"
            value={crawlDelay}
            onChange={(e) => setCrawlDelay(e.target.value)}
            className="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800"
          />
        </div>
        <div>
          <Label htmlFor="disallow_paths">Disallow paths (one per line)</Label>
          <textarea
            id="disallow_paths"
            rows={3}
            value={disallowPaths}
            onChange={(e) => setDisallowPaths(e.target.value)}
            placeholder="/admin&#10;/checkout"
            className="w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 font-mono text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
          />
        </div>
        <div>
          <Label htmlFor="sort_order">Sort order</Label>
          <input
            id="sort_order"
            type="number"
            value={sortOrder}
            onChange={(e) => setSortOrder(e.target.value)}
            className="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800"
          />
        </div>

        <div className="flex items-center justify-end gap-3 pt-2">
          <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>Cancel</Button>
          <Button type="submit" disabled={submitting}>{submitting ? "Saving…" : rule ? "Save" : "Add Rule"}</Button>
        </div>
      </form>
    </div>
  );
}

export default function SaveCrawlerRuleModal({ isOpen, rule, onClose, onSubmit }: Props) {
  return (
    <Modal isOpen={isOpen} onClose={onClose} className="max-w-md">
      {isOpen && <Fields rule={rule} onClose={onClose} onSubmit={onSubmit} />}
    </Modal>
  );
}
