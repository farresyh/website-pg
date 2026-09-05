"use client";

/**
 * PR-G (ADR-072 decision 1 of the planning addendum): admin-triggered
 * portal-login invite for a Reseller (wallet) account — no self-serve
 * signup exists. Mirrors AffiliateDetailModal's "Portal users" section
 * (@/components/affiliates), reusing the same `AffiliateInviteService`/
 * `AffiliateUser` table underneath (this row's `owner_type='reseller'`).
 * A distinct modal rather than folded into ResellerWalletModal/
 * ResellerApiKeysModal, matching this screen's existing one-modal-per-
 * concern row-action pattern.
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
import { Tag } from "@/components/ui/tag";
import { ApiError } from "@/lib/api-client";
import {
  getResellerDetail,
  addResellerUser,
  resendResellerUserInvite,
  type ResellerRow,
  type ResellerUserRow,
} from "@/lib/resellers";

interface Props {
  isOpen: boolean;
  onClose: () => void;
  token: string;
  reseller: ResellerRow;
}

function formatDate(iso: string | null): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleString("en-MY", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
}

function Content({ token, reseller }: Omit<Props, "isOpen" | "onClose">) {
  const [users, setUsers] = useState<ResellerUserRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const [newUserName, setNewUserName] = useState("");
  const [newUserEmail, setNewUserEmail] = useState("");

  function refresh() {
    return getResellerDetail(token, reseller.id)
      .then((d) => setUsers(d.users))
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load portal users."));
  }

  useEffect(() => {
    refresh();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [reseller.id]);

  async function run(key: string, fn: () => Promise<void>) {
    setBusy(key);
    setError(null);
    setNotice(null);
    try {
      await fn();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Something went wrong.");
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="space-y-4">
      {error && (
        <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}
      {notice && (
        <p className="rounded-lg bg-success-50 px-3 py-2 text-sm text-success-600 dark:bg-success-500/15 dark:text-success-400">{notice}</p>
      )}

      <p className="text-theme-xs text-gray-500 dark:text-gray-400">
        A portal user can log into the shared reseller portal to check the wallet balance, top up via CHIP, view
        order history, and manage their own API keys — never to place an order (that stays the API/Bot channels).
      </p>

      <ul className="divide-y divide-gray-100 dark:divide-gray-800">
        {(users ?? []).map((u) => (
          <li key={u.id} className="flex items-center justify-between py-2 text-theme-sm">
            <div>
              <p className="font-medium text-gray-800 dark:text-white/90">{u.name}</p>
              <p className="text-theme-xs text-gray-500 dark:text-gray-400">
                {u.email} · last login {formatDate(u.last_login_at)}
              </p>
            </div>
            <div className="flex items-center gap-2">
              {u.invite_pending ? <Tag severity="warn">invite pending</Tag> : <Tag severity="success">active</Tag>}
              {u.invite_pending && (
                <Button
                  type="button"
                  size="small"
                  variant="outlined"
                  disabled={busy !== null}
                  onClick={() =>
                    run(`resend-${u.id}`, async () => {
                      await resendResellerUserInvite(token, reseller.id, u.id);
                      setNotice(`Invite re-sent to ${u.email}.`);
                    })
                  }
                >
                  {busy === `resend-${u.id}` ? "…" : "Resend invite"}
                </Button>
              )}
            </div>
          </li>
        ))}
        {users !== null && users.length === 0 && (
          <li className="py-4 text-center text-theme-sm text-gray-400">No portal users yet.</li>
        )}
      </ul>

      <div className="flex flex-wrap items-end gap-3 border-t border-gray-100 pt-4 dark:border-gray-800">
        <div className="min-w-40 flex-1">
          <Label htmlFor="new_reseller_user_name">Name</Label>
          <Input id="new_reseller_user_name" value={newUserName} onChange={(e) => setNewUserName(e.target.value)} />
        </div>
        <div className="min-w-48 flex-1">
          <Label htmlFor="new_reseller_user_email">Email</Label>
          <Input
            id="new_reseller_user_email"
            type="email"
            value={newUserEmail}
            onChange={(e) => setNewUserEmail(e.target.value)}
          />
        </div>
        <Button
          type="button"
          size="small"
          disabled={busy !== null || !newUserName || !newUserEmail}
          onClick={() =>
            run("add-user", async () => {
              const d = await addResellerUser(token, reseller.id, { name: newUserName, email: newUserEmail });
              setNewUserName("");
              setNewUserEmail("");
              setUsers(d.users);
              setNotice("User added — set-password invite sent.");
            })
          }
        >
          {busy === "add-user" ? "Adding…" : "Add user"}
        </Button>
      </div>
    </div>
  );
}

export default function ResellerPortalUsersModal({ isOpen, onClose, token, reseller }: Props) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-2xl">
            <DialogHeader>
              <DialogTitle>{reseller.business_name} — Portal Users</DialogTitle>
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
