"use client";

/**
 * ADR-058 58b — admin Affiliate Management (RES-1..6) + the
 * affiliate_membership_tiers CRUD (ADR-056 decision 1). super_admin tier,
 * same as Settings / Membership. PrimeReact-Tailwind primitives only
 * (ADR-038) — this screen is new, so nothing to migrate.
 */

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
import {
  Dialog,
  DialogPortal,
  DialogBackdrop,
  DialogPositioner,
  DialogPopup,
  DialogHeader,
  DialogTitle,
  DialogContent,
  DialogFooter,
} from "@/components/ui/dialog";
import { Tag } from "@/components/ui/tag";
import { Button } from "@/components/ui/button";
import { PlusIcon } from "@/icons";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import {
  listAffiliates,
  getAffiliate,
  createAffiliate,
  updateAffiliate,
  updateAffiliateStatus,
  deleteAffiliate,
  listAffiliateTiers,
  createAffiliateTier,
  updateAffiliateTier,
  deleteAffiliateTier,
  listImpersonationSessions,
  endImpersonationSession,
  type AffiliateRow,
  type AffiliateDetail,
  type AffiliateTier,
  type AffiliateSubscriptionStatus,
  type ImpersonationSessionRow,
} from "@/lib/affiliates";
import AffiliateFormModal, { type AffiliateFormSubmitValues } from "@/components/affiliates/AffiliateFormModal";
import AffiliateDetailModal from "@/components/affiliates/AffiliateDetailModal";
import AffiliateTierFormModal from "@/components/affiliates/AffiliateTierFormModal";

const subSeverity: Record<AffiliateSubscriptionStatus, "success" | "warn" | "danger"> = {
  active: "success",
  grace: "warn",
  lapsed: "danger",
};

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

const TH = "px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400";
const TD = "px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400";

export default function AffiliatesPage() {
  const router = useRouter();
  const session = useClientSession();
  const token = session?.token ?? null;
  const [affiliates, setAffiliates] = useState<AffiliateRow[] | null>(null);
  const [tiers, setTiers] = useState<AffiliateTier[]>([]);
  const [sessions, setSessions] = useState<ImpersonationSessionRow[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<number | null>(null);

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<AffiliateRow | null>(null);
  const [detail, setDetail] = useState<AffiliateDetail | null>(null);
  const [tierFormOpen, setTierFormOpen] = useState(false);
  const [editingTier, setEditingTier] = useState<AffiliateTier | null>(null);
  const [statusTarget, setStatusTarget] = useState<AffiliateRow | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<AffiliateRow | null>(null);
  const [deleteTierTarget, setDeleteTierTarget] = useState<AffiliateTier | null>(null);

  function refresh(t: string) {
    return Promise.all([listAffiliates(t), listAffiliateTiers(t), listImpersonationSessions(t)])
      .then(([r, ti, s]) => {
        setAffiliates(r.affiliates);
        setTiers(ti.tiers);
        setSessions(s.sessions);
      })
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load affiliates."));
  }

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    refresh(s.token);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function guard(key: number, fn: () => Promise<void>) {
    if (!token) return;
    setBusy(key);
    setError(null);
    try {
      await fn();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Something went wrong.");
    } finally {
      setBusy(null);
    }
  }

  async function handleCreate(values: AffiliateFormSubmitValues) {
    if (!token) return;
    await createAffiliate(token, values);
    setFormOpen(false);
    await refresh(token);
  }

  async function handleUpdate(values: AffiliateFormSubmitValues) {
    if (!token || !editing) return;
    await updateAffiliate(token, editing.id, {
      business_name: values.business_name,
      contact_name: values.contact_name,
      email: values.email,
      phone: values.phone,
      markup_pct: values.markup_pct,
      max_markup_pct: values.max_markup_pct,
      domains: values.domains,
      notes: values.notes,
      is_owned: values.is_owned,
      membership_enabled: values.membership_enabled,
    });
    setFormOpen(false);
    setEditing(null);
    await refresh(token);
  }

  async function openDetail(id: number) {
    if (!token) return;
    await guard(id, async () => {
      setDetail(await getAffiliate(token, id));
    });
  }

  if (error && !affiliates) {
    return <p className="rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>;
  }

  if (!token || !affiliates) {
    return <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>;
  }

  const activeSessions = sessions.filter((s) => s.active);

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Affiliates</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Branded-storefront owners. The primary storefront is the first row and can&apos;t be deleted.
          </p>
        </div>
        <Button onClick={() => { setEditing(null); setFormOpen(true); }}>
          <PlusIcon />
          Add Affiliate
        </Button>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={affiliates} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className={TH}>Business</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Tier</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Earnings</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Orders</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Domains</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Status</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item }) => {
                    const r = item as unknown as AffiliateRow;
                    return (
                      <DataTableRow key={r.id}>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                          {r.business_name}
                          {r.is_primary && <span className="ml-2 text-theme-xs text-gray-400">(primary)</span>}
                          {r.is_owned && !r.is_primary && <span className="ml-2 text-theme-xs text-gray-400">(our brand)</span>}
                          {r.membership_enabled && <span className="ml-2 text-theme-xs text-brand-500">membership</span>}
                          {r.contact_name && <p className="text-theme-xs font-normal text-gray-500 dark:text-gray-400">{r.contact_name}</p>}
                        </DataTableCell>
                        <DataTableCell className={TD}>
                          {r.subscription ? (
                            <span className="flex items-center gap-2">
                              {r.subscription.tier_name ?? "—"}
                              <Tag severity={subSeverity[r.subscription.status]}>{r.subscription.status}</Tag>
                            </span>
                          ) : (
                            <span className="text-gray-400">walk-in rate</span>
                          )}
                        </DataTableCell>
                        <DataTableCell className={TD}>{formatRm(r.earnings_balance_sen)}</DataTableCell>
                        <DataTableCell className={TD}>{r.orders_count}</DataTableCell>
                        <DataTableCell className={TD}>{r.domains.length > 0 ? r.domains.join(", ") : "—"}</DataTableCell>
                        <DataTableCell className="px-5 py-4">
                          <Tag severity={r.status === "active" ? "success" : "secondary"}>{r.status}</Tag>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4">
                          <div className="flex flex-wrap gap-1.5">
                            <Button size="small" variant="outlined" disabled={busy === r.id} onClick={() => openDetail(r.id)}>
                              Manage
                            </Button>
                            <Button size="small" variant="outlined" onClick={() => { setEditing(r); setFormOpen(true); }}>
                              Edit
                            </Button>
                            {!r.is_primary && (
                              <>
                                <Button
                                  size="small"
                                  variant="outlined"
                                  disabled={busy === r.id}
                                  onClick={() =>
                                    r.status === "active"
                                      ? setStatusTarget(r)
                                      : guard(r.id, async () => {
                                          await updateAffiliateStatus(token, r.id, "active");
                                          await refresh(token);
                                        })
                                  }
                                >
                                  {r.status === "active" ? "Deactivate" : "Activate"}
                                </Button>
                                <Button size="small" variant="outlined" severity="danger" onClick={() => setDeleteTarget(r)}>
                                  Delete
                                </Button>
                              </>
                            )}
                          </div>
                        </DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>
        </div>
      </div>

      {/* Wholesale tiers */}
      <div className="mt-10">
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 className="text-base font-semibold text-gray-800 dark:text-white/90">Wholesale tiers</h2>
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">
              Paid monthly subscription tiers (ADR-056). Markup % is applied over supplier cost price. Fee is
              collected from the affiliate&apos;s earnings balance.
            </p>
          </div>
          <Button size="small" onClick={() => { setEditingTier(null); setTierFormOpen(true); }}>
            <PlusIcon />
            Add Tier
          </Button>
        </div>

        <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
          <div className="max-w-full overflow-x-auto">
            <DataTable data={tiers} dataKey="id">
              <DataTableTableContainer>
                <DataTableTable>
                  <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                    <DataTableTHeadRow>
                      <DataTableTHeadCell className={TH}>Name</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Monthly fee</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Markup %</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Subscribers</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Active</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Actions</DataTableTHeadCell>
                    </DataTableTHeadRow>
                  </DataTableTHead>
                  <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                    {({ item }) => {
                      const t = item as unknown as AffiliateTier;
                      return (
                        <DataTableRow key={t.id}>
                          <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">{t.name}</DataTableCell>
                          <DataTableCell className={TD}>{formatRm(t.monthly_fee_sen)}</DataTableCell>
                          <DataTableCell className={TD}>{t.markup_percent}%</DataTableCell>
                          <DataTableCell className={TD}>{t.subscriptions_count}</DataTableCell>
                          <DataTableCell className="px-5 py-4">
                            <Tag severity={t.is_active ? "success" : "secondary"}>{t.is_active ? "yes" : "no"}</Tag>
                          </DataTableCell>
                          <DataTableCell className="px-5 py-4">
                            <div className="flex gap-1.5">
                              <Button size="small" variant="outlined" onClick={() => { setEditingTier(t); setTierFormOpen(true); }}>
                                Edit
                              </Button>
                              <Button size="small" variant="outlined" severity="danger" onClick={() => setDeleteTierTarget(t)}>
                                Delete
                              </Button>
                            </div>
                          </DataTableCell>
                        </DataTableRow>
                      );
                    }}
                  </DataTableTBody>
                </DataTableTable>
              </DataTableTableContainer>
            </DataTable>
          </div>
          {tiers.length === 0 && <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No tiers yet.</p>}
        </div>
      </div>

      {/* Impersonation log */}
      <div className="mt-10">
        <h2 className="mb-1 text-base font-semibold text-gray-800 dark:text-white/90">Impersonation sessions</h2>
        <p className="mb-4 text-theme-xs text-gray-500 dark:text-gray-400">
          {activeSessions.length} active. Every session is audited with the acting admin&apos;s identity (RES-4).
        </p>
        <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
          <div className="max-w-full overflow-x-auto">
            <DataTable data={sessions} dataKey="id">
              <DataTableTableContainer>
                <DataTableTable>
                  <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                    <DataTableTHeadRow>
                      <DataTableTHeadCell className={TH}>Affiliate</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Admin</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Acting as</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Started</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Ended</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Actions</DataTableTHeadCell>
                    </DataTableTHeadRow>
                  </DataTableTHead>
                  <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                    {({ item }) => {
                      const s = item as unknown as ImpersonationSessionRow;
                      return (
                        <DataTableRow key={s.id}>
                          <DataTableCell className="px-5 py-4 text-theme-sm text-gray-800 dark:text-white/90">{s.affiliate ?? "—"}</DataTableCell>
                          <DataTableCell className={TD}>{s.admin ?? "—"}</DataTableCell>
                          <DataTableCell className={TD}>{s.acting_as ?? "—"}</DataTableCell>
                          <DataTableCell className={TD}>{new Date(s.started_at).toLocaleString("en-MY")}</DataTableCell>
                          <DataTableCell className={TD}>
                            {s.ended_at ? `${new Date(s.ended_at).toLocaleString("en-MY")} (${s.ended_reason})` : <Tag severity="warn">active</Tag>}
                          </DataTableCell>
                          <DataTableCell className="px-5 py-4">
                            {s.active && (
                              <Button
                                size="small"
                                variant="outlined"
                                disabled={busy === s.id}
                                onClick={() =>
                                  guard(s.id, async () => {
                                    await endImpersonationSession(token, s.id);
                                    await refresh(token);
                                  })
                                }
                              >
                                End
                              </Button>
                            )}
                          </DataTableCell>
                        </DataTableRow>
                      );
                    }}
                  </DataTableTBody>
                </DataTableTable>
              </DataTableTableContainer>
            </DataTable>
          </div>
          {sessions.length === 0 && <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No impersonation sessions yet.</p>}
        </div>
      </div>

      <AffiliateFormModal
        isOpen={formOpen}
        onClose={() => { setFormOpen(false); setEditing(null); }}
        onSubmit={editing ? handleUpdate : handleCreate}
        editing={editing}
        tiers={tiers}
      />

      {detail && (
        <AffiliateDetailModal
          isOpen={detail !== null}
          onClose={() => setDetail(null)}
          token={token}
          detail={detail}
          tiers={tiers}
          onChanged={(d) => {
            setDetail(d);
            refresh(token);
          }}
          onRefresh={() => refresh(token)}
        />
      )}

      <AffiliateTierFormModal
        isOpen={tierFormOpen}
        onClose={() => { setTierFormOpen(false); setEditingTier(null); }}
        editing={editingTier}
        onSubmit={async (values) => {
          if (editingTier) {
            await updateAffiliateTier(token, editingTier.id, values);
          } else {
            await createAffiliateTier(token, values);
          }
          setTierFormOpen(false);
          setEditingTier(null);
          await refresh(token);
        }}
      />

      {/* Deactivate confirm */}
      <Dialog open={statusTarget !== null} onOpenChange={(e) => !e.value && setStatusTarget(null)}>
        <DialogPortal>
          <DialogBackdrop />
          <DialogPositioner>
            <DialogPopup>
              <DialogHeader>
                <DialogTitle>Deactivate {statusTarget?.business_name}?</DialogTitle>
              </DialogHeader>
              <DialogContent>
                <p className="text-sm text-gray-600 dark:text-gray-300">
                  Their branded storefront stops taking new orders (ADR-060 returns 503) and any live impersonation
                  session ends. Existing earnings stay withdrawable.
                </p>
              </DialogContent>
              <DialogFooter>
                <Button variant="outlined" onClick={() => setStatusTarget(null)}>Cancel</Button>
                <Button
                  severity="danger"
                  disabled={busy === statusTarget?.id}
                  onClick={() =>
                    statusTarget &&
                    guard(statusTarget.id, async () => {
                      await updateAffiliateStatus(token, statusTarget.id, "inactive");
                      setStatusTarget(null);
                      await refresh(token);
                    })
                  }
                >
                  Deactivate
                </Button>
              </DialogFooter>
            </DialogPopup>
          </DialogPositioner>
        </DialogPortal>
      </Dialog>

      {/* Delete confirm */}
      <Dialog open={deleteTarget !== null} onOpenChange={(e) => !e.value && setDeleteTarget(null)}>
        <DialogPortal>
          <DialogBackdrop />
          <DialogPositioner>
            <DialogPopup>
              <DialogHeader>
                <DialogTitle>Delete {deleteTarget?.business_name}?</DialogTitle>
              </DialogHeader>
              <DialogContent>
                <p className="text-sm text-gray-600 dark:text-gray-300">
                  Soft-delete — order history is kept. Only allowed when the earnings balance is exactly zero and no
                  withdrawal is pending or approved.
                </p>
              </DialogContent>
              <DialogFooter>
                <Button variant="outlined" onClick={() => setDeleteTarget(null)}>Cancel</Button>
                <Button
                  severity="danger"
                  disabled={busy === deleteTarget?.id}
                  onClick={() =>
                    deleteTarget &&
                    guard(deleteTarget.id, async () => {
                      await deleteAffiliate(token, deleteTarget.id);
                      setDeleteTarget(null);
                      await refresh(token);
                    })
                  }
                >
                  Delete
                </Button>
              </DialogFooter>
            </DialogPopup>
          </DialogPositioner>
        </DialogPortal>
      </Dialog>

      {/* Delete tier confirm */}
      <Dialog open={deleteTierTarget !== null} onOpenChange={(e) => !e.value && setDeleteTierTarget(null)}>
        <DialogPortal>
          <DialogBackdrop />
          <DialogPositioner>
            <DialogPopup>
              <DialogHeader>
                <DialogTitle>Delete tier {deleteTierTarget?.name}?</DialogTitle>
              </DialogHeader>
              <DialogContent>
                <p className="text-sm text-gray-600 dark:text-gray-300">
                  Soft-delete. Blocked if any affiliate has an active or grace subscription on this tier.
                </p>
              </DialogContent>
              <DialogFooter>
                <Button variant="outlined" onClick={() => setDeleteTierTarget(null)}>Cancel</Button>
                <Button
                  severity="danger"
                  disabled={busy === deleteTierTarget?.id}
                  onClick={() =>
                    deleteTierTarget &&
                    guard(deleteTierTarget.id, async () => {
                      await deleteAffiliateTier(token, deleteTierTarget.id);
                      setDeleteTierTarget(null);
                      await refresh(token);
                    })
                  }
                >
                  Delete
                </Button>
              </DialogFooter>
            </DialogPopup>
          </DialogPositioner>
        </DialogPortal>
      </Dialog>
    </div>
  );
}
