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
import { notify } from "@/components/ui/toast";
import { listGames, type Game } from "@/lib/games";
import {
  type Supplier,
  listSuppliers,
  listAvailableSupplierDrivers,
  createSupplier,
  updateSupplier,
  updateSupplierStatus,
  deleteSupplier,
  refreshSupplierBalance,
  updateSupplierPackagesStatus,
  isWebhookConfigured,
  supplierDisplayBalance,
  fundingModeLabel,
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
  const [availableDrivers, setAvailableDrivers] = useState<string[]>([]);
  const [games, setGames] = useState<Game[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busySlug, setBusySlug] = useState<string | null>(null);

  const [createOpen, setCreateOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<Supplier | null>(null);
  const [packagesTarget, setPackagesTarget] = useState<Supplier | null>(null);
  const [activateConfirmTarget, setActivateConfirmTarget] = useState<Supplier | null>(null);
  const [deactivateConfirmTarget, setDeactivateConfirmTarget] = useState<Supplier | null>(null);
  const [deleteConfirmTarget, setDeleteConfirmTarget] = useState<Supplier | null>(null);

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const refresh = useCallback(() => {
    listSuppliers()
      .then(setSuppliers)
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load suppliers."));
    listAvailableSupplierDrivers().then(setAvailableDrivers).catch(() => {});
    listGames().then(setGames).catch(() => {});
  }, []);

  useEffect(() => {
    if (!session) return;
    refresh();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session]);

  // ADR-125: an API driver backs one supplier row; any number of manual suppliers can exist.
  const creatableDrivers = availableDrivers.filter((driver) => driver === "manual" || !suppliers?.some((s) => s.driver === driver));

  async function handleCreate(values: Parameters<typeof createSupplier>[0]) {
    if (!session) return;
    await createSupplier(values);
    notify.success("Supplier created");
    setCreateOpen(false);
    refresh();
  }

  async function handleEdit(values: Parameters<typeof updateSupplier>[1]) {
    if (!session || !editTarget) return;
    const updated = await updateSupplier(editTarget.id, values);
    setEditTarget(null);
    refresh();

    // ADR-069 decision 11 — surface the post-save connection probe.
    // Stress-test Q3: a breaker-open result is not a credential problem.
    const probe = updated.connection_probe;
    if (!probe) {
      notify.success("Supplier saved");
    } else if (probe.connection_ok) {
      notify.success("Credentials saved", `Connection OK${probe.balance != null ? `, balance ${probe.balance}` : ""}.`);
    } else if (probe.breaker_open) {
      notify.info("Credentials saved", probe.error ?? undefined);
    } else {
      notify.warn("Saved, but the connection check failed", probe.error ?? "unknown error");
    }
  }

  async function handleToggleActive(supplier: Supplier) {
    if (!session) return;
    setBusySlug(supplier.slug);
    try {
      await updateSupplierStatus(supplier.id, !supplier.is_active);
      notify.success(supplier.is_active ? "Supplier deactivated" : "Supplier activated");
      refresh();
    } catch (err) {
      notify.error("Could not update this supplier", err instanceof ApiError ? err.message : "Action failed.");
    } finally {
      setBusySlug(null);
      setActivateConfirmTarget(null);
      setDeactivateConfirmTarget(null);
    }
  }

  async function handleRefreshBalance(supplier: Supplier) {
    if (!session) return;
    setBusySlug(supplier.slug);
    try {
      await refreshSupplierBalance(supplier.id);
      notify.success("Balance refreshed");
      refresh();
    } catch (err) {
      notify.error("Could not refresh balance", err instanceof ApiError ? err.message : "Action failed.");
    } finally {
      setBusySlug(null);
    }
  }

  async function handleDelete() {
    if (!session || !deleteConfirmTarget) return;
    setBusySlug(deleteConfirmTarget.slug);
    try {
      await deleteSupplier(deleteConfirmTarget.id);
      notify.success("Supplier deleted");
      setDeleteConfirmTarget(null);
      refresh();
    } catch (err) {
      notify.error("Could not delete this supplier", err instanceof ApiError ? err.message : "Action failed.");
    } finally {
      setBusySlug(null);
    }
  }

  async function handlePackagesSubmit(values: Parameters<typeof updateSupplierPackagesStatus>[1]) {
    if (!session || !packagesTarget) return;
    const result = await updateSupplierPackagesStatus(packagesTarget.id, values);
    // ADR-015 2026-11-05 addendum: a package still blocked (price review, supplier off, no longer listed) stays off.
    notify.success(
      "Packages updated",
      result.skipped
        ? `${result.skipped} kept off: a price review, the supplier's status or its catalog still blocks them.`
        : undefined,
    );
    setPackagesTarget(null);
    refresh();
  }

  return (
    <div>
      <div className="mb-6 flex items-start justify-between gap-3">
        <div>
          <h1 className="text-page-title font-semibold text-ink">Suppliers</h1>
          <p className="mt-1 text-sm text-ink-muted">
            Connection status, balance, and credentials per supplier (ADR-046). Browsing/adding games from a
            supplier&apos;s catalog stays on Product Manager — this screen is config/health only.
          </p>
        </div>
        <Button className="shrink-0" onClick={() => setCreateOpen(true)}>Add Supplier</Button>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-danger-surface px-4 py-3 text-sm text-danger-ink">
          {error}
        </p>
      )}

      {suppliers === null && !error && (
        <p className="text-sm text-ink-muted">Loading…</p>
      )}

      {suppliers?.length === 0 && (
        <p className="text-sm text-ink-muted">No suppliers yet — add one to get started.</p>
      )}

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        {suppliers?.map((supplier) => {
          const busy = busySlug === supplier.slug;
          const totalReferences = Object.values(supplier.reference_counts).reduce((a, b) => a + b, 0);

          return (
            <div
              key={supplier.id}
              className="rounded-2xl border border-border bg-surface p-5"
            >
              <div className="mb-3 flex items-start justify-between">
                <div className="flex items-center gap-3">
                  {supplier.logo_url ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={supplier.logo_url} alt="" className="h-10 w-10 rounded-lg object-cover" />
                  ) : (
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-subtle text-theme-xs font-medium text-ink-muted">
                      {supplier.name.slice(0, 2).toUpperCase()}
                    </div>
                  )}
                  <div>
                    <p className="text-section-title font-semibold text-ink">{supplier.name}</p>
                    <p className="text-theme-xs text-ink-subtle">{supplier.slug}</p>
                  </div>
                </div>
                <div className="flex flex-col items-end gap-1.5">
                  {/* ADR-125: a manual supplier has no API connection to report. */}
                  <Tag severity={!supplier.is_api_backed ? "secondary" : supplier.circuit_state === "closed" ? "success" : "danger"}>
                    {!supplier.is_api_backed ? "manual" : supplier.circuit_state === "closed" ? "connected" : "circuit open"}
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
                  <dt className="text-ink-muted">Status</dt>
                  <dd>
                    <Tag severity={supplier.is_active ? "success" : "secondary"}>
                      {supplier.is_active ? "active" : "inactive"}
                    </Tag>
                  </dd>
                </div>
                {supplier.is_api_backed ? (
                  <div className="flex justify-between">
                    <dt className="text-ink-muted">Credentials</dt>
                    <dd>
                      <Tag severity={supplier.is_fully_configured ? "success" : supplier.has_credentials ? "warn" : "secondary"}>
                        {supplier.is_fully_configured ? "Configured" : supplier.has_credentials ? "Partial" : "Not configured"}
                      </Tag>
                    </dd>
                  </div>
                ) : (
                  <div className="flex justify-between">
                    <dt className="text-ink-muted">Type</dt>
                    <dd className="text-ink">Manual · {fundingModeLabel(supplier)}</dd>
                  </div>
                )}
                {supplier.slug === "digiflazz" && (
                  <div className="flex justify-between">
                    <dt className="text-ink-muted">Webhook</dt>
                    <dd>
                      <Tag severity={isWebhookConfigured(supplier) ? "success" : "warn"}>
                        {isWebhookConfigured(supplier) ? "configured" : "not configured"}
                      </Tag>
                    </dd>
                  </div>
                )}
                <div className="flex justify-between">
                  <dt className="text-ink-muted">Balance</dt>
                  <dd className="text-ink">
                    {supplierDisplayBalance(supplier)}
                  </dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-ink-muted">Last checked</dt>
                  <dd className="text-ink">{formatDateTime(supplier.last_tested_at)}</dd>
                </div>
                {supplier.last_test_result && (
                  <div className="flex justify-between gap-2">
                    <dt className="shrink-0 text-ink-muted">Last result</dt>
                    <dd className="truncate text-right text-ink" title={supplier.last_test_result}>
                      {supplier.last_test_result}
                    </dd>
                  </div>
                )}
                <div className="flex justify-between">
                  <dt className="text-ink-muted">Packages</dt>
                  <dd className="text-ink">{supplier.reference_counts.packages}</dd>
                </div>
              </dl>

              <div className="flex flex-wrap gap-2">
                <Button size="small" variant="outlined" disabled={busy} onClick={() => setEditTarget(supplier)}>
                  Edit
                </Button>
                {supplier.is_api_backed && (
                  <Button size="small" variant="outlined" disabled={busy} onClick={() => handleRefreshBalance(supplier)}>
                    {busy ? "Working…" : "Refresh Balance"}
                  </Button>
                )}
                <Button
                  size="small"
                  variant="outlined"
                  disabled={busy}
                  onClick={() =>
                    supplier.is_active ? setDeactivateConfirmTarget(supplier) : setActivateConfirmTarget(supplier)
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
        availableDrivers={creatableDrivers}
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
            <DialogPopup className="w-full max-w-md">
              <DialogHeader>
                <DialogTitle>Activate {activateConfirmTarget?.name}?</DialogTitle>
              </DialogHeader>
              <DialogContent>
                <p className="text-sm text-ink-muted">
                  This supplier will be included in the next scheduled price sync — make sure its credentials are
                  correct first (Edit → Refresh Balance to verify).
                </p>
                {activateConfirmTarget?.is_api_backed && (
                  <p className="mt-2 text-sm text-ink-muted">
                    Its packages stay off. After the next sync, use Manage Packages → Reactivate to put them back on sale.
                  </p>
                )}
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

      {/* ADR-138 decision 20: switching a supplier off takes its packages and combos off sale. */}
      <Dialog open={deactivateConfirmTarget !== null} onOpenChange={(e) => !e.value && setDeactivateConfirmTarget(null)}>
        <DialogPortal>
          <DialogBackdrop />
          <DialogPositioner>
            <DialogPopup className="w-full max-w-md">
              <DialogHeader>
                <DialogTitle>Deactivate {deactivateConfirmTarget?.name}?</DialogTitle>
              </DialogHeader>
              <DialogContent>
                <p className="text-sm text-ink-muted">
                  On sale now, and going off: {deactivateConfirmTarget?.on_sale.packages} package(s),{" "}
                  {deactivateConfirmTarget?.on_sale.combos} combo(s).
                </p>
                <p className="mt-2 text-sm text-ink-muted">
                  {deactivateConfirmTarget?.is_api_backed
                    ? "Activating it again restores nothing: after the next sync, use Manage Packages → Reactivate."
                    : "They come back when you activate it again."}
                </p>
              </DialogContent>
              <DialogFooter>
                <Button variant="outlined" onClick={() => setDeactivateConfirmTarget(null)}>
                  Cancel
                </Button>
                <Button
                  severity="danger"
                  disabled={busySlug === deactivateConfirmTarget?.slug}
                  onClick={() => deactivateConfirmTarget && handleToggleActive(deactivateConfirmTarget)}
                >
                  Deactivate
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
            <DialogPopup className="w-full max-w-md">
              <DialogHeader>
                <DialogTitle>Delete {deleteConfirmTarget?.name}?</DialogTitle>
              </DialogHeader>
              <DialogContent>
                <p className="text-sm text-ink-muted">
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
