"use client";

/**
 * ADR-074 decision 1: admin issues/revokes a Reseller's Reseller API
 * credentials — the machine-facing counterpart to ResellerWalletModal's
 * money view. The plaintext key only ever exists in this component's
 * own state, right after issueResellerApiKey() returns — never stored,
 * never refetchable, gone the moment the modal closes or another key
 * is issued.
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
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api-client";
import {
  listResellerApiKeys,
  issueResellerApiKey,
  revokeResellerApiKey,
  type ResellerRow,
  type ResellerApiKey,
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
  const [keys, setKeys] = useState<ResellerApiKey[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [name, setName] = useState("");
  const [issuing, setIssuing] = useState(false);
  const [revokingId, setRevokingId] = useState<number | null>(null);
  const [freshPlainTextKey, setFreshPlainTextKey] = useState<string | null>(null);

  function refresh() {
    return listResellerApiKeys(token, reseller.id)
      .then(setKeys)
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load API keys."));
  }

  useEffect(() => {
    refresh();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [reseller.id]);

  async function handleIssue(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setFreshPlainTextKey(null);

    setIssuing(true);
    try {
      const issued = await issueResellerApiKey(token, reseller.id, name);
      setFreshPlainTextKey(issued.plain_text_key);
      setName("");
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Something went wrong.");
    } finally {
      setIssuing(false);
    }
  }

  async function handleRevoke(key: ResellerApiKey) {
    setError(null);
    setRevokingId(key.id);
    try {
      await revokeResellerApiKey(token, reseller.id, key.id);
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not revoke key.");
    } finally {
      setRevokingId(null);
    }
  }

  return (
    <div className="space-y-6">
      {error && (
        <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      {freshPlainTextKey && (
        <div className="space-y-2 rounded-lg border border-warning-300 bg-warning-50 p-4 dark:border-warning-500/30 dark:bg-warning-500/10">
          <p className="text-theme-xs font-medium text-warning-700 dark:text-warning-400">
            Copy this key now — it will never be shown again.
          </p>
          <code className="block break-all rounded-md bg-white px-3 py-2 text-theme-sm text-gray-800 dark:bg-gray-900 dark:text-white/90">
            {freshPlainTextKey}
          </code>
          <Button type="button" size="small" variant="outlined" onClick={() => navigator.clipboard.writeText(freshPlainTextKey)}>
            Copy
          </Button>
        </div>
      )}

      <form onSubmit={handleIssue} className="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-800">
        <p className="text-theme-xs font-medium text-gray-600 dark:text-gray-400">
          Issue a new key — hand it to the reseller&apos;s own system as a Bearer token for the Reseller API.
        </p>
        <div className="flex items-end gap-3">
          <div className="flex-1">
            <Label htmlFor="api_key_name">Label</Label>
            <Input id="api_key_name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Production key" required />
          </div>
          <Button type="submit" size="small" disabled={issuing}>
            {issuing ? "Issuing…" : "Issue Key"}
          </Button>
        </div>
      </form>

      <div className="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
        <table className="w-full text-left text-theme-sm">
          <thead className="bg-gray-50 dark:bg-gray-900">
            <tr>
              <th className="px-4 py-2 text-theme-xs font-medium text-gray-500 dark:text-gray-400">Label</th>
              <th className="px-4 py-2 text-theme-xs font-medium text-gray-500 dark:text-gray-400">Last used</th>
              <th className="px-4 py-2 text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</th>
              <th className="px-4 py-2 text-theme-xs font-medium text-gray-500 dark:text-gray-400" />
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
            {(keys ?? []).map((key) => (
              <tr key={key.id}>
                <td className="px-4 py-2 text-gray-700 dark:text-gray-300">{key.name}</td>
                <td className="px-4 py-2 text-gray-500 dark:text-gray-400">{key.last_used_at ? formatDate(key.last_used_at) : "Never"}</td>
                <td className="px-4 py-2">
                  {key.revoked_at ? (
                    <span className="text-error-600 dark:text-error-400">Revoked {formatDate(key.revoked_at)}</span>
                  ) : (
                    <span className="text-success-600 dark:text-success-400">Active</span>
                  )}
                </td>
                <td className="px-4 py-2 text-right">
                  {!key.revoked_at && (
                    <Button type="button" size="small" severity="danger" disabled={revokingId === key.id} onClick={() => handleRevoke(key)}>
                      {revokingId === key.id ? "…" : "Revoke"}
                    </Button>
                  )}
                </td>
              </tr>
            ))}
            {keys !== null && keys.length === 0 && (
              <tr>
                <td colSpan={4} className="px-4 py-6 text-center text-gray-400">No API keys yet.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

export default function ResellerApiKeysModal({ isOpen, onClose, token, reseller }: Props) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-2xl">
            <DialogHeader>
              <DialogTitle>{reseller.business_name} — API Keys</DialogTitle>
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
