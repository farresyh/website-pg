"use client";

import React, { useState } from "react";
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
import { SimpleSelect } from "@/components/ui/select";
import { Button } from "@/components/ui/button";
import { Tag } from "@/components/ui/tag";
import { ApiError } from "@/lib/api-client";
import {
  addAffiliateUser,
  assignAffiliateTier,
  chargeAffiliateTierFee,
  impersonateAffiliate,
  reactivateAffiliateSubscription,
  resendAffiliateInvite,
  type AffiliateDetail,
  type AffiliateSubscriptionStatus,
  type AffiliateTier,
} from "@/lib/affiliates";

interface Props {
  isOpen: boolean;
  onClose: () => void;
  token: string;
  detail: AffiliateDetail;
  tiers: AffiliateTier[];
  onChanged: (detail: AffiliateDetail) => void;
  onRefresh: () => void;
}

const subStatusSeverity: Record<AffiliateSubscriptionStatus, "success" | "warn" | "danger"> = {
  active: "success",
  grace: "warn",
  lapsed: "danger",
};

function formatRm(sen: number | null | undefined): string {
  if (sen === null || sen === undefined) return "—";
  return `RM ${(sen / 100).toFixed(2)}`;
}

function formatDate(iso: string | null): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleString("en-MY", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
}

function Body({ token, detail, tiers, onChanged, onRefresh, onClose }: Omit<Props, "isOpen">) {
  const r = detail.affiliate;
  const sub = r.subscription;

  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const [tierId, setTierId] = useState<string>(sub ? String(sub.tier_id) : "");
  const [tierNote, setTierNote] = useState("");

  const [newUserName, setNewUserName] = useState("");
  const [newUserEmail, setNewUserEmail] = useState("");

  const [impersonateReason, setImpersonateReason] = useState("");

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

  const tierOptions = tiers
    .filter((t) => t.is_active || String(t.id) === tierId)
    .map((t) => ({ value: String(t.id), label: `${t.name} (RM${(t.monthly_fee_sen / 100).toFixed(2)}/mo, +${t.markup_percent}%)` }));

  return (
    <div className="space-y-6">
      {error && (
        <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}
      {notice && (
        <p className="rounded-lg bg-success-50 px-3 py-2 text-sm text-success-600 dark:bg-success-500/15 dark:text-success-400">{notice}</p>
      )}

      <div className="grid grid-cols-2 gap-3 text-theme-sm sm:grid-cols-4">
        <div>
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Status</p>
          <Tag severity={r.status === "active" ? "success" : "secondary"}>{r.status}</Tag>
        </div>
        <div>
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Earnings balance</p>
          <p className="font-semibold text-gray-800 dark:text-white/90">{formatRm(r.earnings_balance_sen)}</p>
        </div>
        <div>
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Orders</p>
          <p className="font-semibold text-gray-800 dark:text-white/90">{r.orders_count}</p>
        </div>
        <div>
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Markup %</p>
          <p className="font-semibold text-gray-800 dark:text-white/90">
            {r.markup_pct}
            {r.max_markup_pct ? ` / ${r.max_markup_pct} max` : ""}
          </p>
        </div>
      </div>

      {/* Affiliate-tier subscription */}
      <section className="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
        <div className="mb-3 flex items-center justify-between">
          <h3 className="text-sm font-semibold text-gray-800 dark:text-white/90">Affiliate tier</h3>
          {sub && <Tag severity={subStatusSeverity[sub.status]}>{sub.status}</Tag>}
        </div>

        {sub ? (
          <div className="mb-3 grid grid-cols-2 gap-2 text-theme-xs text-gray-600 dark:text-gray-400 sm:grid-cols-4">
            <div><span className="text-gray-400">Tier</span><br />{sub.tier_name ?? "—"}</div>
            <div><span className="text-gray-400">Fee</span><br />{formatRm(sub.monthly_fee_sen)}/mo</div>
            <div><span className="text-gray-400">Next charge</span><br />{formatDate(sub.next_charge_at)}</div>
            <div><span className="text-gray-400">Grace until</span><br />{formatDate(sub.grace_until)}</div>
          </div>
        ) : (
          <p className="mb-3 text-theme-xs text-gray-500 dark:text-gray-400">
            No subscription — this affiliate pays the walk-in rate (standard selling price).
          </p>
        )}

        <div className="flex flex-wrap items-end gap-3">
          <div className="min-w-56 flex-1">
            <Label htmlFor="tier_select">{sub ? "Change tier" : "Assign tier"}</Label>
            <SimpleSelect options={tierOptions} value={tierId} onChange={setTierId} />
          </div>
          <div className="min-w-40 flex-1">
            <Label htmlFor="tier_note">Note (optional)</Label>
            <Input id="tier_note" value={tierNote} onChange={(e) => setTierNote(e.target.value)} />
          </div>
          <Button
            type="button"
            size="small"
            disabled={busy !== null || tierId === "" || (sub !== null && String(sub.tier_id) === tierId)}
            onClick={() =>
              run("assign", async () => {
                const d = await assignAffiliateTier(token, r.id, Number(tierId), tierNote || null);
                setTierNote("");
                onChanged(d);
                setNotice("Tier updated.");
              })
            }
          >
            {busy === "assign" ? "Saving…" : "Apply tier"}
          </Button>
        </div>

        {sub && (
          <div className="mt-3 flex flex-wrap gap-2">
            <Button
              type="button"
              size="small"
              variant="outlined"
              disabled={busy !== null}
              onClick={() =>
                run("charge", async () => {
                  const d = await chargeAffiliateTierFee(token, r.id);
                  onChanged(d);
                  setNotice("Charge attempted — see the tier status above.");
                })
              }
            >
              {busy === "charge" ? "Charging…" : "Charge fee now"}
            </Button>
            {sub.status !== "active" && (
              <Button
                type="button"
                size="small"
                variant="outlined"
                disabled={busy !== null}
                onClick={() =>
                  run("reactivate", async () => {
                    const d = await reactivateAffiliateSubscription(token, r.id);
                    onChanged(d);
                    setNotice("Subscription reactivated with a fresh 30-day cycle.");
                  })
                }
              >
                {busy === "reactivate" ? "…" : "Reactivate"}
              </Button>
            )}
          </div>
        )}
      </section>

      {/* Portal users */}
      <section className="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
        <h3 className="mb-3 text-sm font-semibold text-gray-800 dark:text-white/90">Portal users</h3>
        <ul className="mb-4 divide-y divide-gray-100 dark:divide-gray-800">
          {detail.users.map((u) => (
            <li key={u.id} className="flex items-center justify-between py-2 text-theme-sm">
              <div>
                <p className="font-medium text-gray-800 dark:text-white/90">{u.name}</p>
                <p className="text-theme-xs text-gray-500 dark:text-gray-400">{u.email}</p>
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
                        await resendAffiliateInvite(token, r.id, u.id);
                        setNotice(`Invite re-sent to ${u.email}.`);
                      })
                    }
                  >
                    Resend invite
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ul>

        <div className="flex flex-wrap items-end gap-3">
          <div className="min-w-40 flex-1">
            <Label htmlFor="new_user_name">Name</Label>
            <Input id="new_user_name" value={newUserName} onChange={(e) => setNewUserName(e.target.value)} />
          </div>
          <div className="min-w-48 flex-1">
            <Label htmlFor="new_user_email">Email</Label>
            <Input id="new_user_email" type="email" value={newUserEmail} onChange={(e) => setNewUserEmail(e.target.value)} />
          </div>
          <Button
            type="button"
            size="small"
            disabled={busy !== null || !newUserName || !newUserEmail}
            onClick={() =>
              run("add-user", async () => {
                const d = await addAffiliateUser(token, r.id, { name: newUserName, email: newUserEmail });
                setNewUserName("");
                setNewUserEmail("");
                onChanged(d);
                setNotice("User added — set-password invite sent.");
              })
            }
          >
            {busy === "add-user" ? "Adding…" : "Add user"}
          </Button>
        </div>
      </section>

      {/* Impersonation */}
      <section className="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
        <h3 className="mb-1 text-sm font-semibold text-gray-800 dark:text-white/90">Impersonate (RES-4)</h3>
        <p className="mb-3 text-theme-xs text-gray-500 dark:text-gray-400">
          Opens the affiliate portal in a new tab as one of their users, under a short-lived (60&nbsp;min) token.
          Audited with your identity; the portal shows a persistent &ldquo;impersonating&rdquo; banner. End the
          session from that banner, or from the list below.
        </p>
        <div className="flex flex-wrap items-end gap-3">
          <div className="min-w-56 flex-1">
            <Label htmlFor="imp_reason">Reason (optional)</Label>
            <Input id="imp_reason" value={impersonateReason} onChange={(e) => setImpersonateReason(e.target.value)} />
          </div>
          <Button
            type="button"
            size="small"
            disabled={busy !== null || r.status !== "active"}
            onClick={() =>
              run("impersonate", async () => {
                const res = await impersonateAffiliate(token, r.id, impersonateReason || null);
                setImpersonateReason("");
                // ADR-059 59c: open the portal directly, carrying the
                // minted token in the URL *hash* (never a query string —
                // it stays out of server logs and the Referer header).
                // The portal's /impersonate page consumes it.
                window.open(
                  `${res.portal_url}/impersonate#token=${encodeURIComponent(res.token)}`,
                  "_blank",
                  "noopener",
                );
                setNotice(
                  `Session #${res.session_id} started as ${res.acting_as.email} — opened in a new tab (token expires ${formatDate(res.expires_at)}).`,
                );
                onRefresh();
              })
            }
          >
            {busy === "impersonate" ? "Starting…" : "Start impersonation"}
          </Button>
        </div>
      </section>

      {/* Tier change history */}
      {detail.tier_changes.length > 0 && (
        <section className="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
          <h3 className="mb-3 text-sm font-semibold text-gray-800 dark:text-white/90">Tier change history</h3>
          <ul className="space-y-2 text-theme-xs text-gray-600 dark:text-gray-400">
            {detail.tier_changes.map((c) => (
              <li key={c.id}>
                {formatDate(c.created_at)} — {c.from_tier ?? "none"} → {c.to_tier ?? "none"}
                {c.admin ? ` by ${c.admin}` : ""}
                {c.note ? ` (${c.note})` : ""}
              </li>
            ))}
          </ul>
        </section>
      )}

      <div className="flex justify-end">
        <Button type="button" variant="outlined" onClick={onClose}>
          Close
        </Button>
      </div>
    </div>
  );
}

export default function AffiliateDetailModal({ isOpen, onClose, token, detail, tiers, onChanged, onRefresh }: Props) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-3xl">
            <DialogHeader>
              <DialogTitle>{detail.affiliate.business_name}</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && (
                <Body token={token} detail={detail} tiers={tiers} onChanged={onChanged} onRefresh={onRefresh} onClose={onClose} />
              )}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
