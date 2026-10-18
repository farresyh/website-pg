"use client";

/**
 * ADR-075 PR-F decision 3, shared by ADR-125 decision 60: link and unlink a
 * bot-session WhatsApp group to its owner (a reseller or a manual supplier).
 * Onboarding: add the bot and the owner into one WhatsApp group, send any
 * message, and it shows up in the pending list within a few seconds, so the
 * admin picks it instead of pasting a raw group id. Every ownership rule
 * (one owner per group, one active group per supplier) is the backend's;
 * its refusal is shown as the error.
 */

import React, { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { Tag } from "@/components/ui/tag";
import { ApiError } from "@/lib/api-client";
import { listPendingWhatsAppGroups, type WhatsAppGroup, type WhatsAppPendingLink } from "@/lib/whatsapp-groups";

interface Props {
  loadGroups: () => Promise<WhatsAppGroup[]>;
  link: (whatsappGroupId: string) => Promise<unknown>;
  setActive: (groupId: number, isActive: boolean) => Promise<unknown>;
  /** "reseller" or "supplier", used in the copy. */
  ownerNoun: string;
  onChanged?: () => void;
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleString("en-MY", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
}

export default function WhatsAppGroupLinker({ loadGroups, link, setActive, ownerNoun, onChanged }: Props) {
  const [groups, setGroups] = useState<WhatsAppGroup[] | null>(null);
  const [pending, setPending] = useState<WhatsAppPendingLink[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [linking, setLinking] = useState<string | null>(null);
  const [togglingId, setTogglingId] = useState<number | null>(null);

  function refresh() {
    return Promise.all([loadGroups(), listPendingWhatsAppGroups()])
      .then(([g, p]) => {
        setGroups(g);
        setPending(p);
      })
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load WhatsApp groups."));
  }

  useEffect(() => {
    refresh();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function handleLink(whatsappGroupId: string) {
    setError(null);
    setLinking(whatsappGroupId);
    try {
      await link(whatsappGroupId);
      await refresh();
      onChanged?.();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not link this group.");
    } finally {
      setLinking(null);
    }
  }

  async function handleToggle(group: WhatsAppGroup) {
    setError(null);
    setTogglingId(group.id);
    try {
      await setActive(group.id, !group.is_active);
      await refresh();
      onChanged?.();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not update this group.");
    } finally {
      setTogglingId(null);
    }
  }

  const linkedGroupIds = new Set((groups ?? []).map((g) => g.whatsapp_group_id));
  const linkablePending = (pending ?? []).filter((p) => !linkedGroupIds.has(p.whatsapp_group_id));

  return (
    <div className="space-y-6">
      {error && (
        <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <div>
        <p className="mb-2 text-theme-xs font-medium text-gray-600 dark:text-gray-400">
          This {ownerNoun}&apos;s linked groups
        </p>
        <div className="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
          <table className="w-full text-left text-theme-sm">
            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
              {(groups ?? []).map((g) => (
                <tr key={g.id}>
                  <td className="px-4 py-2 text-gray-700 dark:text-gray-300">{g.whatsapp_group_id}</td>
                  <td className="px-4 py-2">
                    <Tag severity={g.is_active ? "success" : "secondary"}>{g.is_active ? "active" : "unlinked"}</Tag>
                  </td>
                  <td className="px-4 py-2 text-right">
                    <Button
                      type="button"
                      size="small"
                      variant="outlined"
                      severity={g.is_active ? "danger" : undefined}
                      disabled={togglingId === g.id}
                      onClick={() => handleToggle(g)}
                    >
                      {togglingId === g.id ? "…" : g.is_active ? "Unlink" : "Relink"}
                    </Button>
                  </td>
                </tr>
              ))}
              {groups !== null && groups.length === 0 && (
                <tr>
                  <td colSpan={3} className="px-4 py-6 text-center text-ink-subtle">No groups linked yet.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      <div>
        <p className="mb-2 text-theme-xs font-medium text-gray-600 dark:text-gray-400">
          Pending — add the bot + this {ownerNoun} into a shared WhatsApp group, then send any message. It shows up
          here within a few seconds.
        </p>
        <div className="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
          <table className="w-full text-left text-theme-sm">
            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
              {linkablePending.map((p) => (
                <tr key={p.whatsapp_group_id}>
                  <td className="px-4 py-2 text-gray-700 dark:text-gray-300">{p.whatsapp_group_id}</td>
                  <td className="px-4 py-2 text-gray-500 dark:text-gray-400">
                    {p.last_message_at ? formatDate(p.last_message_at) : "—"}
                    {p.last_message_preview && (
                      <span className="ml-2 italic text-ink-subtle">&ldquo;{p.last_message_preview}&rdquo;</span>
                    )}
                  </td>
                  <td className="px-4 py-2 text-right">
                    <Button
                      type="button"
                      size="small"
                      disabled={linking === p.whatsapp_group_id}
                      onClick={() => handleLink(p.whatsapp_group_id)}
                    >
                      {linking === p.whatsapp_group_id ? "…" : `Link to this ${ownerNoun}`}
                    </Button>
                  </td>
                </tr>
              ))}
              {pending !== null && linkablePending.length === 0 && (
                <tr>
                  <td colSpan={3} className="px-4 py-6 text-center text-ink-subtle">No pending groups right now.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
