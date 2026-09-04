"use client";

/**
 * ADR-075 / PR-F build addendum decision 3: link/unlink a WhatsApp
 * group to this Reseller (Bot channel account identity). Onboarding
 * flow: admin adds the bot + this reseller into a shared WA group, the
 * reseller (or admin) sends any message, and it shows up here as a
 * "pending" group within a few seconds — admin picks it from the list
 * rather than copy-pasting a raw group id.
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
import { CloseIcon } from "@/icons";
import { Button } from "@/components/ui/button";
import { Tag } from "@/components/ui/tag";
import { ApiError } from "@/lib/api-client";
import {
  listResellerWhatsAppGroups,
  listPendingWhatsAppGroups,
  linkResellerWhatsAppGroup,
  updateResellerWhatsAppGroupStatus,
  type ResellerRow,
  type ResellerWhatsAppGroup,
  type ResellerWhatsAppPendingLink,
} from "@/lib/resellers";

interface Props {
  isOpen: boolean;
  onClose: () => void;
  token: string;
  reseller: ResellerRow;
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleString("en-MY", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
}

function Content({ token, reseller }: Omit<Props, "isOpen" | "onClose">) {
  const [groups, setGroups] = useState<ResellerWhatsAppGroup[] | null>(null);
  const [pending, setPending] = useState<ResellerWhatsAppPendingLink[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [linking, setLinking] = useState<string | null>(null);
  const [togglingId, setTogglingId] = useState<number | null>(null);

  function refresh() {
    return Promise.all([listResellerWhatsAppGroups(token, reseller.id), listPendingWhatsAppGroups(token)])
      .then(([g, p]) => {
        setGroups(g);
        setPending(p);
      })
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load WhatsApp groups."));
  }

  useEffect(() => {
    refresh();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [reseller.id]);

  async function handleLink(whatsappGroupId: string) {
    setError(null);
    setLinking(whatsappGroupId);
    try {
      await linkResellerWhatsAppGroup(token, reseller.id, whatsappGroupId);
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not link this group.");
    } finally {
      setLinking(null);
    }
  }

  async function handleToggle(group: ResellerWhatsAppGroup) {
    setError(null);
    setTogglingId(group.id);
    try {
      await updateResellerWhatsAppGroupStatus(token, reseller.id, group.id, !group.is_active);
      await refresh();
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
          This reseller&apos;s linked groups
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
                  <td colSpan={3} className="px-4 py-6 text-center text-gray-400">No groups linked yet.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      <div>
        <p className="mb-2 text-theme-xs font-medium text-gray-600 dark:text-gray-400">
          Pending — add the bot + this reseller into a shared WhatsApp group, then send any message. It shows up
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
                      <span className="ml-2 italic text-gray-400">&ldquo;{p.last_message_preview}&rdquo;</span>
                    )}
                  </td>
                  <td className="px-4 py-2 text-right">
                    <Button
                      type="button"
                      size="small"
                      disabled={linking === p.whatsapp_group_id}
                      onClick={() => handleLink(p.whatsapp_group_id)}
                    >
                      {linking === p.whatsapp_group_id ? "…" : "Link to this reseller"}
                    </Button>
                  </td>
                </tr>
              ))}
              {pending !== null && linkablePending.length === 0 && (
                <tr>
                  <td colSpan={3} className="px-4 py-6 text-center text-gray-400">No pending groups right now.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}

export default function ResellerWhatsAppGroupsModal({ isOpen, onClose, token, reseller }: Props) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-2xl">
            <DialogHeader>
              <DialogTitle>{reseller.business_name} — WhatsApp Groups</DialogTitle>
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
