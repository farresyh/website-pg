"use client";

/**
 * ADR-046 — Supplier Management, trimmed to SUPP-1/CRUD/SUPP-5.
 * SUPP-2/3/4 (browse/add/search supplier catalog) deliberately not
 * built here — Product Manager (/middleware/product-manager) already
 * covers that ground. New here: activate/deactivate with a real
 * money-adjacent-action warning, live "Refresh Balance", and the
 * bulk Deactivate/Reactivate packages tool (decisions 9/10) that
 * closes the "one supplier, 500+ packages, no fast remediation path"
 * gap the founder raised while grilling this ADR.
 */

import React, { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Tag } from "@/components/ui/tag";
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
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { listGames, type Game } from "@/lib/games";
import {
  type Supplier,
  listSuppliers,
  listAvailableSupplierSlugs,
  createSupplier,
  updateSupplier,
  updateSupplierStatus,
  deleteSupplier,
  refreshSupplierBalance,
  updateSupplierPackagesStatus,
  isWebhookConfigured,
} from "@/lib/suppliers";
import CreateSupplierModal from "@/components/middleware/suppliers/CreateSupplierModal";
import EditSupplierModal from "@/components/middleware/suppliers/EditSupplierModal";
import SupplierPackagesModal from "@/components/middleware/suppliers/SupplierPackagesModal";

function formatDateTime(value: string | null): string {
  return value ? new Date(value).toLocaleString() : "—";
}

export default function SuppliersPage() {
  const router = useRouter();
  const session = useClientSession();

  const [suppliers, setSuppliers] = useState<Supplier[] | null>(null);
  const [availableSlugs, setAvailableSlugs] = useState<string[]>([]);
  const [games, setGames] = useState<Game[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ tone: "ok" | "warn"; text: string } | null>(null);
  const [busySlug, setBusySlug] = useState<string | null>(null);

  const [createOpen, setCreateOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<Supplier | null>(null);
  const [packagesTarget, setPackagesTarget] = useState<Supplier | null>(null);
  const [activateConfirmTarget, setActivateConfirmTarget] = useState<Supplier | null>(null);
  const [deleteConfirmTarget, setDeleteConfirmTarget] = useState<Supplier | null>(null);

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const refresh = useCallback((token: string) => {
    listSuppliers(token)
      .then(setSuppliers)
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load suppliers."));
    listAvailableSupplierSlugs(token).then(setAvailableSlugs).catch(() => {});
    listGames(token).then(setGames).catch(() => {});
  }, []);

  useEffect(() => {
    if (!session) return;
    refresh(session.token);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session]);

  const creatableSlugs = availableSlugs.filter((slug) => !suppliers?.some((s) => s.slug === slug));

  async function handleCreate(values: Parameters<typeof createSupplier>[1]) {
    if (!session) return;
    await createSupplier(session.token, values);
    setCreateOpen(false);
    refresh(session.token);
  }

  async function handleEdit(values: Parameters<typeof updateSupplier>[2]) {
    if (!session || !editTarget) return;
    setNotice(null);
    const updated = await updateSupplier(session.token, editTarget.id, values);
    setEditTarget(null);
    refresh(session.token);

    // ADR-069 decision 11 — surface the post-save connection probe.
    // Stress-test Q3: a breaker-open result is not a credential problem.
    const probe = updated.connection_probe;
    if (probe) {
      let text: string;
      if (probe.connection_ok) {
        text = `Credentials saved — connection OK${probe.balance != null ? `, balance ${probe.balance}` : ""}.`;
      } else if (probe.breaker_open) {
        text = `Credentials saved. ${probe.error ?? ""}`.trim();
      } else {
        text = `Saved, but the connection check failed: ${probe.error ?? "unknown error"}.`;
      }
      setNotice({ tone: probe.connection_ok ? "ok" : "warn", text });
    }
  }

  async function handleToggleActive(supplier: Supplier) {
    if (!session) return;
    setError(null);
    setBusySlug(supplier.slug);
    try {
      await updateSupplierStatus(session.token, supplier.id, !supplier.is_active);
      refresh(session.token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not update this supplier.");
    } finally {
      setBusySlug(null);
      setActivateConfirmTarget(null);
    }
  }

  async function handleRefreshBalance(supplier: Supplier) {
    if (!session) return;
    setError(null);
    setBusySlug(supplier.slug);
    try {
      await refreshSupplierBalance(session.token, supplier.id);
      refresh(session.token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not refresh balance.");
    } finally {
      setBusySlug(null);
    }
  }

  async function handleDelete() {
    if (!session || !deleteConfirmTarget) return;
    setError(null);
    setBusySlug(deleteConfirmTarget.slug);
    try {
      await deleteSupplier(session.token, deleteConfirmTarget.id);
      setDeleteConfirmTarget(null);
      refresh(session.token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not delete this supplier.");
    } finally {
      setBusySlug(null);
    }
  }

  async function handlePackagesSubmit(values: Parameters<typeof updateSupplierPackagesStatus>[2]) {
    if (!session || !packagesTarget) return;
    await updateSupplierPackagesStatus(session.token, packagesTarget.id, values);
    setPackagesTarget(null);
    refresh(session.token);
  }

  return (
    <div>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Suppliers</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Connection status, balance, and credentials per supplier (ADR-046). Browsing/adding games from a
            supplier&apos;s catalog stays on Product Manager — this screen is config/health only.
          </p>
        </div>
        <Button onClick={() => setCreateOpen(true)}>Add Supplier</Button>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      {notice && (
        <p
          className={
            notice.tone === "ok"
              ? "mb-4 rounded-lg bg-success-50 px-4 py-3 text-sm text-success-700 dark:bg-success-500/15 dark:text-success-400"
              : "mb-4 rounded-lg bg-warning-50 px-4 py-3 text-sm text-warning-700 dark:bg-warning-500/15 dark:text-warning-400"
          }
        >
          {notice.text}
        </p>
      )}

      {suppliers === null && !error && (
        <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>
      )}

      {suppliers?.length === 0 && (
        <p className="text-sm text-gray-500 dark:text-gray-400">No suppliers yet — add one to get started.</p>
      )}

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        {suppliers?.map((supplier) => {
          const busy = busySlug === supplier.slug;
          const totalReferences = Object.values(supplier.reference_counts).reduce((a, b) => a + b, 0);

          return (
            <div
              key={supplier.id}
              className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]"
            >
              <div className="mb-3 flex items-start justify-between">
                <div className="flex items-center gap-3">
                  {supplier.logo_url ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={supplier.logo_url} alt="" className="h-10 w-10 rounded-lg object-cover" />
                  ) : (
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-100 text-theme-xs font-medium text-gray-500 dark:bg-white/[0.05] dark:text-gray-400">
                      {supplier.name.slice(0, 2).toUpperCase()}
                    </div>
                  )}
                  <div>
                    <p className="font-medium text-gray-800 dark:text-white/90">{supplier.name}</p>
                    <p className="text-theme-xs text-gray-400">{supplier.slug}</p>
                  </div>
                </div>
                <div className="flex flex-col items-end gap-1.5">
                  <Tag severity={supplier.circuit_state === "closed" ? "success" : "danger"}>
                    {supplier.circuit_state === "closed" ? "connected" : "circuit open"}
                  </Tag>
                  {supplier.is_sandbox !== null && (
                    <Tag severity={supplier.is_sandbox ? "warn" : "info"}>
                      {supplier.is_sandbox ? "sandbox" : "production"}
                    </Tag>
                  )}
                </div>
              </div>

              <dl className="mb-4 space-y-1.5 text-theme-sm">
                <div className="flex justify-between">
                  <dt className="text-gray-500 dark:text-gray-400">Status</dt>
                  <dd>
                    <Tag severity={supplier.is_active ? "success" : "secondary"}>
                      {supplier.is_active ? "active" : "inactive"}
                    </Tag>
                  </dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-gray-500 dark:text-gray-400">Credentials</dt>
                  <dd>
                    <Tag severity={supplier.is_fully_configured ? "success" : supplier.has_credentials ? "warn" : "secondary"}>
                      {supplier.is_fully_configured ? "Configured" : supplier.has_credentials ? "Partial" : "Not configured"}
                    </Tag>
                  </dd>
                </div>
                {supplier.slug === "digiflazz" && (
                  <div className="flex justify-between">
                    <dt className="text-gray-500 dark:text-gray-400">Webhook</dt>
                    <dd>
                      <Tag severity={isWebhookConfigured(supplier) ? "success" : "warn"}>
                        {isWebhookConfigured(supplier) ? "configured" : "not configured"}
                      </Tag>
                    </dd>
                  </div>
                )}
                <div className="flex justify-between">
                  <dt className="text-gray-500 dark:text-gray-400">Balance</dt>
                  <dd className="text-gray-800 dark:text-white/90">
                    {supplier.balance ?? "—"} {supplier.currency}
                  </dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-gray-500 dark:text-gray-400">Last checked</dt>
                  <dd className="text-gray-800 dark:text-white/90">{formatDateTime(supplier.last_tested_at)}</dd>
                </div>
                {supplier.last_test_result && (
                  <div className="flex justify-between gap-2">
                    <dt className="shrink-0 text-gray-500 dark:text-gray-400">Last result</dt>
                    <dd className="truncate text-right text-gray-800 dark:text-white/90" title={supplier.last_test_result}>
                      {supplier.last_test_result}
                    </dd>
                  </div>
                )}
                <div className="flex justify-between">
                  <dt className="text-gray-500 dark:text-gray-400">Packages</dt>
                  <dd className="text-gray-800 dark:text-white/90">{supplier.reference_counts.packages}</dd>
                </div>
              </dl>

              <div className="flex flex-wrap gap-2">
                <Button size="small" variant="outlined" disabled={busy} onClick={() => setEditTarget(supplier)}>
                  Edit
                </Button>
                <Button size="small" variant="outlined" disabled={busy} onClick={() => handleRefreshBalance(supplier)}>
                  {busy ? "Working…" : "Refresh Balance"}
                </Button>
                <Button
                  size="small"
                  variant="outlined"
                  disabled={busy}
                  onClick={() =>
                    supplier.is_active ? handleToggleActive(supplier) : setActivateConfirmTarget(supplier)
                  }
                >
                  {supplier.is_active ? "Deactivate" : "Activate"}
                </Button>
                <Button
                  size="small"
                  variant="outlined"
                  disabled={busy || supplier.reference_counts.packages === 0}
                  onClick={() => setPackagesTarget(supplier)}
                >
                  Manage Packages
                </Button>
                <Button
                  size="small"
                  variant="outlined"
                  severity="danger"
                  disabled={busy || totalReferences > 0}
                  onClick={() => setDeleteConfirmTarget(supplier)}
                  title={totalReferences > 0 ? "Has existing packages/orders — deactivate instead" : undefined}
                >
                  Delete
                </Button>
              </div>
            </div>
          );
        })}
      </div>

      <CreateSupplierModal
        isOpen={createOpen}
        onClose={() => setCreateOpen(false)}
        onSubmit={handleCreate}
        availableSlugs={creatableSlugs}
      />

      <EditSupplierModal isOpen={editTarget !== null} onClose={() => setEditTarget(null)} onSubmit={handleEdit} supplier={editTarget} />

      <SupplierPackagesModal
        isOpen={packagesTarget !== null}
        onClose={() => setPackagesTarget(null)}
        onSubmit={handlePackagesSubmit}
        supplier={packagesTarget}
        games={games.filter((g) => g.is_active !== false)}
      />

      {/* ADR-046 decision 5: activating is a real, immediate action —
          the next scheduled sync/price-sync run picks this supplier up
          automatically (ADR-031 decision 4). */}
      <Dialog open={activateConfirmTarget !== null} onOpenChange={(e) => !e.value && setActivateConfirmTarget(null)}>
        <DialogPortal>
          <DialogBackdrop />
          <DialogPositioner>
            <DialogPopup>
              <DialogHeader>
                <DialogTitle>Activate {activateConfirmTarget?.name}?</DialogTitle>
              </DialogHeader>
              <DialogContent>
                <p className="text-sm text-gray-600 dark:text-gray-300">
                  This supplier will be included in the next scheduled price sync — make sure its credentials are
                  correct first (Edit → Refresh Balance to verify).
                </p>
              </DialogContent>
              <DialogFooter>
                <Button variant="outlined" onClick={() => setActivateConfirmTarget(null)}>
                  Cancel
                </Button>
                <Button disabled={busySlug === activateConfirmTarget?.slug} onClick={() => activateConfirmTarget && handleToggleActive(activateConfirmTarget)}>
                  Activate
                </Button>
              </DialogFooter>
            </DialogPopup>
          </DialogPositioner>
        </DialogPortal>
      </Dialog>

      <Dialog open={deleteConfirmTarget !== null} onOpenChange={(e) => !e.value && setDeleteConfirmTarget(null)}>
        <DialogPortal>
          <DialogBackdrop />
          <DialogPositioner>
            <DialogPopup>
              <DialogHeader>
                <DialogTitle>Delete {deleteConfirmTarget?.name}?</DialogTitle>
              </DialogHeader>
              <DialogContent>
                <p className="text-sm text-gray-600 dark:text-gray-300">
                  Permanently removes this supplier row. This cannot be undone.
                </p>
              </DialogContent>
              <DialogFooter>
                <Button variant="outlined" onClick={() => setDeleteConfirmTarget(null)}>
                  Cancel
                </Button>
                <Button severity="danger" disabled={busySlug === deleteConfirmTarget?.slug} onClick={handleDelete}>
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
