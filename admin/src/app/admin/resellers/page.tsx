"use client";

/**
 * ADR-072/073 PR-B — admin Reseller (prepaid-wallet) account management:
 * register account, assign tier, activate/deactivate + the reseller_tiers
 * CRUD (ADR-073 decision 1). super_admin tier, same as Affiliates.
 * PrimeReact-Tailwind primitives only (ADR-038) — this screen is new.
 *
 * PR-D added order-placing logic; PR-E (ADR-074) added the "API Keys"
 * row action (`ResellerApiKeysModal`, issue/revoke); PR-F (ADR-075)
 * added "WhatsApp Groups" (`ResellerWhatsAppGroupsModal`, link/unlink
 * against the platform-wide pending list); PR-G added "Portal Users"
 * (`ResellerPortalUsersModal`, admin-triggered set-password invite —
 * registration itself stays admin-created, no self-serve signup, PR-G
 * planning addendum decision 1). This closes the ADR-072..075 family —
 * see the phasing note in `docs/adr.md`.
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
  listResellers,
  createReseller,
  updateReseller,
  updateResellerStatus,
  deleteReseller,
  listResellerTiers,
  createResellerTier,
  updateResellerTier,
  deleteResellerTier,
  type ResellerRow,
  type ResellerTier,
} from "@/lib/resellers";
import ResellerFormModal, { type ResellerFormSubmitValues } from "@/components/resellers/ResellerFormModal";
import ResellerTierFormModal from "@/components/resellers/ResellerTierFormModal";
import ResellerWalletModal from "@/components/resellers/ResellerWalletModal";
import ResellerApiKeysModal from "@/components/resellers/ResellerApiKeysModal";
import ResellerWebhookModal from "@/components/resellers/ResellerWebhookModal";
import ResellerWhatsAppGroupsModal from "@/components/resellers/ResellerWhatsAppGroupsModal";
import ResellerPortalUsersModal from "@/components/resellers/ResellerPortalUsersModal";

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

const TH = "px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400";
const TD = "px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400";

export default function ResellersPage() {
  const router = useRouter();
  const session = useClientSession();
  const token = session?.token ?? null;
  const [resellers, setResellers] = useState<ResellerRow[] | null>(null);
  const [tiers, setTiers] = useState<ResellerTier[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<number | null>(null);

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<ResellerRow | null>(null);
  const [tierFormOpen, setTierFormOpen] = useState(false);
  const [editingTier, setEditingTier] = useState<ResellerTier | null>(null);
  const [statusTarget, setStatusTarget] = useState<ResellerRow | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<ResellerRow | null>(null);
  const [deleteTierTarget, setDeleteTierTarget] = useState<ResellerTier | null>(null);
  const [walletTarget, setWalletTarget] = useState<ResellerRow | null>(null);
  const [apiKeysTarget, setApiKeysTarget] = useState<ResellerRow | null>(null);
  const [webhookTarget, setWebhookTarget] = useState<ResellerRow | null>(null);
  const [whatsAppGroupsTarget, setWhatsAppGroupsTarget] = useState<ResellerRow | null>(null);
  const [portalUsersTarget, setPortalUsersTarget] = useState<ResellerRow | null>(null);

  function refresh(t: string) {
    return Promise.all([listResellers(t), listResellerTiers(t)])
      .then(([r, ti]) => {
        setResellers(r.resellers);
        setTiers(ti.tiers);
      })
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load resellers."));
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

  async function handleCreate(values: ResellerFormSubmitValues) {
    if (!token) return;
    await createReseller(token, values);
    setFormOpen(false);
    await refresh(token);
  }

  async function handleUpdate(values: ResellerFormSubmitValues) {
    if (!token || !editing) return;
    await updateReseller(token, editing.id, {
      business_name: values.business_name,
      contact_name: values.contact_name,
      email: values.email,
      phone: values.phone,
      notes: values.notes,
    });
    setFormOpen(false);
    setEditing(null);
    await refresh(token);
  }

  if (error && !resellers) {
    return <p className="rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>;
  }

  if (!token || !resellers) {
    return <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>;
  }

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Resellers</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Prepaid-wallet wholesale buyers (Reseller API / Bot channels). Distinct from Affiliates — a reseller
            spends from a deposited balance, never earns.
          </p>
        </div>
        <Button onClick={() => { setEditing(null); setFormOpen(true); }}>
          <PlusIcon />
          Add Reseller
        </Button>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={resellers} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className={TH}>Business</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Tier</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Wallet balance</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Status</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item }) => {
                    const r = item as unknown as ResellerRow;
                    return (
                      <DataTableRow key={r.id}>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                          {r.business_name}
                          {r.contact_name && <p className="text-theme-xs font-normal text-gray-500 dark:text-gray-400">{r.contact_name}</p>}
                        </DataTableCell>
                        <DataTableCell className={TD}>
                          {r.tier_name ? (
                            <span>
                              {r.tier_name} <span className="text-theme-xs text-gray-400">(+{r.markup_percent}%)</span>
                            </span>
                          ) : (
                            <span className="text-gray-400">— no tier —</span>
                          )}
                        </DataTableCell>
                        <DataTableCell className={TD}>{formatRm(r.wallet_balance_sen)}</DataTableCell>
                        <DataTableCell className="px-5 py-4">
                          <Tag severity={r.is_active ? "success" : "secondary"}>{r.is_active ? "active" : "inactive"}</Tag>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4">
                          <div className="flex flex-wrap gap-1.5">
                            <Button size="small" variant="outlined" onClick={() => setWalletTarget(r)}>
                              Wallet
                            </Button>
                            <Button size="small" variant="outlined" onClick={() => setApiKeysTarget(r)}>
                              API Keys
                            </Button>
                            <Button size="small" variant="outlined" onClick={() => setWebhookTarget(r)}>
                              Webhook
                            </Button>
                            <Button size="small" variant="outlined" onClick={() => setWhatsAppGroupsTarget(r)}>
                              WhatsApp Groups
                            </Button>
                            <Button size="small" variant="outlined" onClick={() => setPortalUsersTarget(r)}>
                              Portal Users
                            </Button>
                            <Button size="small" variant="outlined" onClick={() => { setEditing(r); setFormOpen(true); }}>
                              Edit
                            </Button>
                            <Button
                              size="small"
                              variant="outlined"
                              disabled={busy === r.id}
                              onClick={() =>
                                r.is_active
                                  ? setStatusTarget(r)
                                  : guard(r.id, async () => {
                                      await updateResellerStatus(token, r.id, true);
                                      await refresh(token);
                                    })
                              }
                            >
                              {r.is_active ? "Deactivate" : "Activate"}
                            </Button>
                            <Button size="small" variant="outlined" severity="danger" onClick={() => setDeleteTarget(r)}>
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
        {resellers.length === 0 && <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No resellers yet.</p>}
      </div>

      {/* Wallet tiers */}
      <div className="mt-10">
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 className="text-base font-semibold text-gray-800 dark:text-white/90">Wallet tiers</h2>
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">
              Fee-less prepaid-wallet tiers (ADR-073). Markup % is applied over supplier cost price on every wallet
              order — a direct FK assignment, no billing cycle.
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
                      <DataTableTHeadCell className={TH}>Markup %</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Resellers</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Active</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Actions</DataTableTHeadCell>
                    </DataTableTHeadRow>
                  </DataTableTHead>
                  <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                    {({ item }) => {
                      const t = item as unknown as ResellerTier;
                      return (
                        <DataTableRow key={t.id}>
                          <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">{t.name}</DataTableCell>
                          <DataTableCell className={TD}>{t.markup_percent}%</DataTableCell>
                          <DataTableCell className={TD}>{t.resellers_count}</DataTableCell>
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

      {walletTarget && (
        <ResellerWalletModal
          isOpen={walletTarget !== null}
          onClose={() => setWalletTarget(null)}
          token={token}
          reseller={walletTarget}
          onCredited={() => refresh(token)}
        />
      )}

      {apiKeysTarget && (
        <ResellerApiKeysModal
          isOpen={apiKeysTarget !== null}
          onClose={() => setApiKeysTarget(null)}
          token={token}
          reseller={apiKeysTarget}
        />
      )}

      {webhookTarget && (
        <ResellerWebhookModal
          isOpen={webhookTarget !== null}
          onClose={() => setWebhookTarget(null)}
          token={token}
          reseller={webhookTarget}
        />
      )}

      {whatsAppGroupsTarget && (
        <ResellerWhatsAppGroupsModal
          isOpen={whatsAppGroupsTarget !== null}
          onClose={() => setWhatsAppGroupsTarget(null)}
          token={token}
          reseller={whatsAppGroupsTarget}
        />
      )}

      {portalUsersTarget && (
        <ResellerPortalUsersModal
          isOpen={portalUsersTarget !== null}
          onClose={() => setPortalUsersTarget(null)}
          token={token}
          reseller={portalUsersTarget}
        />
      )}

      <ResellerFormModal
        isOpen={formOpen}
        onClose={() => { setFormOpen(false); setEditing(null); }}
        onSubmit={editing ? handleUpdate : handleCreate}
        editing={editing}
        tiers={tiers}
      />

      <ResellerTierFormModal
        isOpen={tierFormOpen}
        onClose={() => { setTierFormOpen(false); setEditingTier(null); }}
        editing={editingTier}
        onSubmit={async (values) => {
          if (editingTier) {
            await updateResellerTier(token, editingTier.id, values);
          } else {
            await createResellerTier(token, values);
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
                  This reseller can&apos;t place new orders on either channel. The wallet balance stays untouched and
                  refundable.
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
                      await updateResellerStatus(token, statusTarget.id, false);
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
                  Soft-delete — only allowed when the wallet balance is exactly zero.
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
                      await deleteReseller(token, deleteTarget.id);
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
                  Soft-delete. Blocked if any reseller is currently assigned to this tier.
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
                      await deleteResellerTier(token, deleteTierTarget.id);
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
