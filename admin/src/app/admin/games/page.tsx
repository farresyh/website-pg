"use client";

/**
 * GAME-1..5/7 — closes the loop from Price Sync Stage 2
 * (/middleware/product-manager): once a Package is promoted there, it
 * exists in the shared `games`/`packages` tables and is immediately
 * `is_active = true` (matches the legacy-confirmed "promote = go
 * live" behavior) — this screen is where admin reviews, edits, and
 * deactivates/deletes it afterward. See docs/prd.md §14's Product
 * Manager workflow note for the full pipeline this closes.
 *
 * Package row layout (STATUS toggle / MARKUP % + Update / computed
 * price, all inline) deliberately mirrors the legacy reference
 * system's own Games screen (legacy-reference-notes.md) rather than a
 * modal-per-field — founder-confirmed as the intended pattern.
 */

import React, { useEffect, useState } from "react";
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
import { Tag } from "@/components/ui/tag";
import { Button } from "@/components/ui/button";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import {
  type Game,
  type GamePackage,
  type UpdateGameValues,
  type UpdatePackageValues,
  type CreateComboPackageValues,
  type UpdateComboOverrideValues,
  listGames,
  listGamePackages,
  updateGame,
  deleteGame,
  updatePackage,
  updatePackageMarkup,
  updatePackageStatus,
  updatePackageDenomination,
  updatePackageCatalogCode,
  deletePackage,
  reorderGames,
  createComboPackage,
  updateComboOverride,
} from "@/lib/games";
import EditGameModal from "@/components/games/EditGameModal";
import EditPackageModal from "@/components/games/EditPackageModal";
import ReorderGamesView from "@/components/games/ReorderGamesView";
import CreateComboModal from "@/components/games/CreateComboModal";
import ComboOverrideModal from "@/components/games/ComboOverrideModal";
import { type PlayerValidatorProfile, listPlayerValidatorProfiles } from "@/lib/player-validators";
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
import { Times as CloseIcon } from "@primeicons/react/times";

/**
 * ADR-094 decision 13: the deactivate-cascade confirm — shown only
 * when the package being switched off is an active component of one
 * or more active combos (`active_combo_dependents`, already loaded
 * with the package list, no extra fetch). Mirrors AffiliateFormModal's
 * membership-disable warn-and-acknowledge pattern.
 */
function DeactivateCascadeDialog({
  pkg,
  onClose,
  onConfirm,
}: {
  pkg: GamePackage | null;
  onClose: () => void;
  onConfirm: () => Promise<void>;
}) {
  const [submitting, setSubmitting] = useState(false);

  async function handleConfirm() {
    setSubmitting(true);
    try {
      await onConfirm();
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Dialog open={pkg !== null} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-md">
            <DialogHeader>
              <DialogTitle>Deactivate Package</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {pkg && (
                <div className="space-y-4">
                  <p className="text-sm text-gray-700 dark:text-gray-300">
                    <span className="font-medium">{pkg.active_combo_dependents.length}</span> active combo(s) use{" "}
                    <span className="font-medium">{pkg.name}</span> — deactivating it will deactivate them too:
                  </p>
                  <ul className="list-inside list-disc text-sm text-gray-600 dark:text-gray-400">
                    {pkg.active_combo_dependents.map((c) => (
                      <li key={c.id}>{c.name}</li>
                    ))}
                  </ul>
                  <div className="flex items-center justify-end gap-3 pt-2">
                    <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>
                      Cancel
                    </Button>
                    <Button type="button" severity="danger" onClick={handleConfirm} disabled={submitting}>
                      {submitting ? "Deactivating…" : "Deactivate Anyway"}
                    </Button>
                  </div>
                </div>
              )}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

/**
 * Rendered with `key={`${pkg.id}-${pkg.markup_percent}`}` by the
 * caller — remounts fresh (resetting `value` to the latest server
 * state) whenever markup_percent actually changes, same fresh-mount
 * reasoning as the *FormFields components instead of syncing via
 * effect.
 */
function MarkupCell({ pkg, onUpdate }: { pkg: GamePackage; onUpdate: (markupPercent: number) => Promise<void> }) {
  const [value, setValue] = useState(pkg.markup_percent);
  const [saving, setSaving] = useState(false);

  async function handleUpdate() {
    const markupPercent = parseFloat(value);
    if (!Number.isFinite(markupPercent) || markupPercent < 0) return;

    setSaving(true);
    try {
      await onUpdate(markupPercent);
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="flex items-center gap-2">
      <div className="relative">
        <input
          type="text"
          value={value}
          onChange={(e) => setValue(e.target.value)}
          className="h-9 w-20 rounded-lg border border-gray-300 px-2 pr-5 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
        />
        <span className="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-xs text-gray-400">%</span>
      </div>
      <Button size="small" disabled={saving} onClick={handleUpdate}>
        {saving ? "…" : "Update"}
      </Button>
    </div>
  );
}

/**
 * ADR-034 decision 3: the edit-time half of curating denomination —
 * same fresh-mount-per-value-change reasoning as MarkupCell above
 * (`key={`${pkg.id}-${pkg.denomination}`}` at the call site). Empty
 * input submits `null` — an admin can clear a wrongly-set value.
 */
function DenominationCell({ pkg, onUpdate }: { pkg: GamePackage; onUpdate: (denomination: number | null) => Promise<void> }) {
  const [value, setValue] = useState(pkg.denomination === null ? "" : String(pkg.denomination));
  const [saving, setSaving] = useState(false);

  async function handleUpdate() {
    const trimmed = value.trim();
    const denomination = trimmed === "" ? null : parseInt(trimmed, 10);
    if (denomination !== null && (!Number.isFinite(denomination) || denomination < 1)) return;

    setSaving(true);
    try {
      await onUpdate(denomination);
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="flex items-center gap-2">
      <input
        type="text"
        value={value}
        onChange={(e) => setValue(e.target.value)}
        placeholder="—"
        className="h-9 w-16 rounded-lg border border-gray-300 px-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
      />
      <Button size="small" disabled={saving} onClick={handleUpdate}>
        {saving ? "…" : "Update"}
      </Button>
    </div>
  );
}

/**
 * ADR-075's catalog-code addendum (2026-09-04): the catalog_code
 * counterpart to DenominationCell above, for bundle/pass packages —
 * mutually exclusive with `denomination` (enforced server-side).
 * Uppercased client-side to match the backend's own normalization.
 */
function CatalogCodeCell({ pkg, onUpdate }: { pkg: GamePackage; onUpdate: (catalogCode: string | null) => Promise<void> }) {
  const [value, setValue] = useState(pkg.catalog_code ?? "");
  const [saving, setSaving] = useState(false);

  async function handleUpdate() {
    const trimmed = value.trim().toUpperCase();
    setSaving(true);
    try {
      await onUpdate(trimmed === "" ? null : trimmed);
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="flex items-center gap-2">
      <input
        type="text"
        value={value}
        onChange={(e) => setValue(e.target.value.toUpperCase())}
        placeholder="—"
        className="h-9 w-16 rounded-lg border border-gray-300 px-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
      />
      <Button size="small" disabled={saving} onClick={handleUpdate}>
        {saving ? "…" : "Update"}
      </Button>
    </div>
  );
}

export default function GamesPage() {
  const router = useRouter();
  // Read in an effect, not render body — see UserDropdown.tsx for why.
  const session = useClientSession();

  const [games, setGames] = useState<Game[] | null>(null);
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<"all" | "active" | "inactive">("all");
  const [error, setError] = useState<string | null>(null);

  const [selected, setSelected] = useState<Game | null>(null);
  const [packages, setPackages] = useState<GamePackage[] | null>(null);

  const [editingGame, setEditingGame] = useState<Game | null>(null);
  const [editingPackage, setEditingPackage] = useState<GamePackage | null>(null);
  const [validatorProfiles, setValidatorProfiles] = useState<PlayerValidatorProfile[]>([]);

  // ADR-094 Phase 4 — combo composition CRUD + churn-guard confirm state.
  const [creatingCombo, setCreatingCombo] = useState(false);
  const [editingCombo, setEditingCombo] = useState<GamePackage | null>(null);
  const [deactivateCascadeTarget, setDeactivateCascadeTarget] = useState<GamePackage | null>(null);

  // GAME-6 — null = not reordering; an array = reorder mode, loaded
  // unfiltered regardless of the list view's own search/status filter.
  const [reorderGamesList, setReorderGamesList] = useState<Game[] | null>(null);

  async function refreshGames(token: string) {
    try {
      setGames(await listGames(token, { search: search || undefined, status: status === "all" ? undefined : status }));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load games.");
    }
  }

  async function openGame(token: string, game: Game) {
    setSelected(game);
    setPackages(null);
    try {
      setPackages(await listGamePackages(token, game.id));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load packages.");
    }
  }

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!session) return;

    listGames(session.token, { search: search || undefined, status: status === "all" ? undefined : status })
      .then(setGames)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load games.");
      });
     
  }, [session, search, status]);

  useEffect(() => {
    if (!session) return;

    // Powers the "Player ID Validator" picker in EditGameModal — profiles
    // themselves are created/managed at /middleware/validators, not here.
    listPlayerValidatorProfiles(session.token)
      .then(setValidatorProfiles)
      .catch(() => {
        // Non-fatal — the games screen still works, the picker just shows "None" only.
      });
  }, [session]);

  async function handleEditGameSubmit(values: UpdateGameValues) {
    if (!session || !editingGame) return;
    const updated = await updateGame(session.token, editingGame.id, values);
    setEditingGame(null);
    await refreshGames(session.token);
    if (selected?.id === updated.id) setSelected(updated);
  }

  async function handleDeleteGame() {
    if (!session || !editingGame) return;
    await deleteGame(session.token, editingGame.id);
    setEditingGame(null);
    if (selected?.id === editingGame.id) setSelected(null);
    await refreshGames(session.token);
  }

  async function handleEditPackageSubmit(values: UpdatePackageValues) {
    if (!session || !editingPackage || !selected) return;
    await updatePackage(session.token, editingPackage.id, values);
    setEditingPackage(null);
    await openGame(session.token, selected);
  }

  async function handleDeletePackage() {
    if (!session || !editingPackage || !selected) return;
    await deletePackage(session.token, editingPackage.id);
    setEditingPackage(null);
    await openGame(session.token, selected);
    await refreshGames(session.token);
  }

  async function startReordering() {
    if (!session) return;
    setError(null);
    try {
      // Always the full unfiltered list — the current search/status
      // filter must never determine what gets a sort_order written.
      setReorderGamesList(await listGames(session.token, {}));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load games.");
    }
  }

  async function handleSaveOrder(orderedIds: number[]) {
    if (!session) return;
    await reorderGames(session.token, orderedIds);
    setReorderGamesList(null);
    await refreshGames(session.token);
  }

  /**
   * ADR-094 decisions 13/22: the two combo-aware guards are checked
   * client-side first, from data the package list already carries —
   * deactivating with active combo dependents opens the confirm
   * dialog instead of calling the API immediately (decision 13);
   * reactivating a combo with an inactive component is rejected
   * inline, no API call at all (decision 22). The backend re-checks
   * both regardless (defense in depth, not the primary discovery
   * mechanism) — same division of labor as AffiliateFormModal's
   * membership-disable warning.
   */
  async function handleToggleStatus(pkg: GamePackage) {
    if (!session) return;
    setError(null);

    const activating = !pkg.is_active;

    if (!activating && pkg.active_combo_dependents.length > 0) {
      setDeactivateCascadeTarget(pkg);
      return;
    }

    if (activating && pkg.is_combo) {
      const inactive = pkg.components.filter((c) => !c.is_active);
      if (inactive.length > 0) {
        setError(`Cannot reactivate — inactive component(s): ${inactive.map((c) => c.name).join(", ")}`);
        return;
      }
    }

    try {
      await updatePackageStatus(session.token, pkg.id, activating);
      setPackages((prev) => prev?.map((p) => (p.id === pkg.id ? { ...p, is_active: activating } : p)) ?? null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not update status.");
    }
  }

  /** Decision 13's actual cascade, once acknowledged in the confirm dialog. */
  async function handleConfirmDeactivateCascade() {
    if (!session || !deactivateCascadeTarget || !selected) return;
    try {
      await updatePackageStatus(session.token, deactivateCascadeTarget.id, false, true);
      setDeactivateCascadeTarget(null);
      await openGame(session.token, selected);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not deactivate.");
      setDeactivateCascadeTarget(null);
    }
  }

  async function handleCreateCombo(values: CreateComboPackageValues) {
    if (!session || !selected) return;
    await createComboPackage(session.token, selected.id, values);
    setCreatingCombo(false);
    await openGame(session.token, selected);
    await refreshGames(session.token);
  }

  async function handleUpdateComboOverride(values: UpdateComboOverrideValues) {
    if (!session || !editingCombo || !selected) return;
    await updateComboOverride(session.token, editingCombo.id, values);
    setEditingCombo(null);
    await openGame(session.token, selected);
  }

  async function handleDeleteCombo() {
    if (!session || !editingCombo || !selected) return;
    await deletePackage(session.token, editingCombo.id);
    setEditingCombo(null);
    await openGame(session.token, selected);
    await refreshGames(session.token);
  }

  async function handleUpdateMarkup(pkg: GamePackage, markupPercent: number) {
    if (!session) return;
    setError(null);
    try {
      // Merge, don't replace — this endpoint's response has no
      // `supplier_active` (only the list endpoint computes it), a
      // full replace would silently wipe that field from local state.
      const updated = await updatePackageMarkup(session.token, pkg.id, markupPercent);
      setPackages((prev) => prev?.map((p) => (p.id === pkg.id ? { ...p, ...updated } : p)) ?? null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not update markup.");
    }
  }

  async function handleUpdateDenomination(pkg: GamePackage, denomination: number | null) {
    if (!session) return;
    setError(null);
    try {
      // Merge, don't replace — same reasoning as handleUpdateMarkup.
      const updated = await updatePackageDenomination(session.token, pkg.id, denomination);
      setPackages((prev) => prev?.map((p) => (p.id === pkg.id ? { ...p, ...updated } : p)) ?? null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not update denomination.");
    }
  }

  async function handleUpdateCatalogCode(pkg: GamePackage, catalogCode: string | null) {
    if (!session) return;
    setError(null);
    try {
      // Merge, don't replace — same reasoning as handleUpdateMarkup.
      const updated = await updatePackageCatalogCode(session.token, pkg.id, catalogCode);
      setPackages((prev) => prev?.map((p) => (p.id === pkg.id ? { ...p, ...updated } : p)) ?? null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not update catalog code.");
    }
  }

  if (reorderGamesList) {
    return (
      <ReorderGamesView
        initialGames={reorderGamesList}
        onSave={handleSaveOrder}
        onCancel={() => setReorderGamesList(null)}
      />
    );
  }

  if (selected) {
    return (
      <div>
        <button
          onClick={() => setSelected(null)}
          className="mb-4 text-sm text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white"
        >
          ← Back to games
        </button>

        <div className="mb-6 flex items-center justify-between">
          <div>
            <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">{selected.name}</h1>
            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
              {selected.category ?? "Uncategorized"} — {selected.is_active ? "Active" : "Inactive"}
            </p>
          </div>
          <div className="flex gap-2">
            {/* ADR-094: assembles several of this game's own already-promoted packages into one combo SKU. */}
            <Button size="small" variant="outlined" onClick={() => setCreatingCombo(true)}>Create Combo…</Button>
            <Button size="small" onClick={() => setEditingGame(selected)}>Edit Game</Button>
          </div>
        </div>

        {error && (
          <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
            {error}
          </p>
        )}

        <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
          <div className="max-w-full overflow-x-auto">
            <DataTable data={packages ?? []} dataKey="id">
              <DataTableTableContainer>
                <DataTableTable>
                  <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                    <DataTableTHeadRow>
                      <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Package</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Denomination</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Catalog Code</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Cost Price</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Markup %</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Affiliate Price</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</DataTableTHeadCell>
                    </DataTableTHeadRow>
                  </DataTableTHead>
                  <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                    {({ item }) => {
                      const pkg = item as unknown as GamePackage;

                      return (
                        <DataTableRow key={pkg.id}>
                          <DataTableCell className="px-5 py-4 text-theme-sm">
                            <button
                              role="switch"
                              aria-checked={pkg.is_active}
                              onClick={() => handleToggleStatus(pkg)}
                              className={`h-6 w-11 rounded-full transition ${pkg.is_active ? "bg-brand-500" : "bg-gray-300 dark:bg-gray-700"}`}
                            >
                              <span
                                className={`block h-5 w-5 translate-x-0.5 rounded-full bg-white transition ${pkg.is_active ? "translate-x-[22px]" : ""}`}
                              />
                            </button>
                          </DataTableCell>
                          <DataTableCell className="px-5 py-4 text-theme-sm">
                            <span className="font-medium text-gray-800 dark:text-white/90">{pkg.name}</span>
                            {pkg.is_combo && <Tag severity="info">Combo</Tag>}
                            {!pkg.is_combo && !pkg.supplier_active && (
                              <Tag severity="warn">Non-Active</Tag>
                            )}
                            <br />
                            {/* ADR-094: a combo has no supplier of its own (decision 3) — show its component count instead. */}
                            <span className="text-theme-xs text-gray-400">
                              {pkg.is_combo ? `${pkg.components.length} component(s)` : `Supplier ID: ${pkg.supplier_package_ref}`}
                            </span>
                            {pkg.active_combo_dependents.length > 0 && (
                              <>
                                <br />
                                <span className="text-theme-xs text-amber-600 dark:text-amber-400">
                                  Used by {pkg.active_combo_dependents.length} combo(s)
                                </span>
                              </>
                            )}
                          </DataTableCell>
                          <DataTableCell className="px-5 py-4 text-theme-sm">
                            <DenominationCell
                              key={`${pkg.id}-${pkg.denomination}`}
                              pkg={pkg}
                              onUpdate={(denomination) => handleUpdateDenomination(pkg, denomination)}
                            />
                          </DataTableCell>
                          <DataTableCell className="px-5 py-4 text-theme-sm">
                            <CatalogCodeCell
                              key={`${pkg.id}-${pkg.catalog_code}`}
                              pkg={pkg}
                              onUpdate={(catalogCode) => handleUpdateCatalogCode(pkg, catalogCode)}
                            />
                          </DataTableCell>
                          <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{formatRm(pkg.cost_price)}</DataTableCell>
                          <DataTableCell className="px-5 py-4 text-theme-sm">
                            <MarkupCell
                              key={`${pkg.id}-${pkg.markup_percent}`}
                              pkg={pkg}
                              onUpdate={(markupPercent) => handleUpdateMarkup(pkg, markupPercent)}
                            />
                          </DataTableCell>
                          <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                            {formatRm(pkg.standard_selling_price)}
                          </DataTableCell>
                          <DataTableCell className="px-5 py-4 text-theme-sm">
                            <Button
                              size="small"
                              variant="outlined"
                              onClick={() => (pkg.is_combo ? setEditingCombo(pkg) : setEditingPackage(pkg))}
                            >
                              Edit
                            </Button>
                          </DataTableCell>
                        </DataTableRow>
                      );
                    }}
                  </DataTableTBody>
                </DataTableTable>
              </DataTableTableContainer>
            </DataTable>
            {packages?.length === 0 && (
              <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">
                No packages yet — add some via Product Manager (/middleware/product-manager).
              </p>
            )}
            {packages === null && <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>}
          </div>
        </div>

        <EditGameModal
          isOpen={editingGame !== null}
          onClose={() => setEditingGame(null)}
          onSubmit={handleEditGameSubmit}
          onDelete={handleDeleteGame}
          game={editingGame}
          validatorProfiles={validatorProfiles}
        />
        <EditPackageModal
          isOpen={editingPackage !== null}
          onClose={() => setEditingPackage(null)}
          onSubmit={handleEditPackageSubmit}
          onDelete={handleDeletePackage}
          pkg={editingPackage}
        />
        <CreateComboModal
          isOpen={creatingCombo}
          onClose={() => setCreatingCombo(false)}
          onSubmit={handleCreateCombo}
          packages={packages ?? []}
        />
        <ComboOverrideModal
          isOpen={editingCombo !== null}
          onClose={() => setEditingCombo(null)}
          onSubmit={handleUpdateComboOverride}
          onDelete={handleDeleteCombo}
          pkg={editingCombo}
        />
        <DeactivateCascadeDialog
          pkg={deactivateCascadeTarget}
          onClose={() => setDeactivateCascadeTarget(null)}
          onConfirm={handleConfirmDeactivateCascade}
        />
      </div>
    );
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Games & Packages</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Review, edit, and manage what&apos;s promoted from Product Manager.
          </p>
        </div>
        <Button size="small" variant="outlined" onClick={startReordering}>
          Reorder Games
        </Button>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <div className="mb-4 flex flex-wrap items-center gap-3">
        <input
          type="text"
          placeholder="Search games…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="h-11 w-full max-w-sm rounded-lg border border-gray-300 px-4 py-2.5 text-sm shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
        />
        <div className="flex gap-2">
          {(["all", "active", "inactive"] as const).map((s) => (
            <button
              key={s}
              onClick={() => setStatus(s)}
              className={`rounded-lg px-3 py-1.5 text-sm capitalize ${status === s ? "bg-brand-500 text-white" : "bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400"}`}
            >
              {s}
            </button>
          ))}
        </div>
      </div>

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={games ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Name</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Category</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Packages</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item }) => {
                    const game = item as unknown as Game;

                    return (
                      <DataTableRow key={game.id}>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">{game.name}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{game.category ?? "—"}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{game.packages_count ?? 0}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <Tag severity={game.is_active ? "success" : "secondary"}>
                            {game.is_active ? "Active" : "Inactive"}
                          </Tag>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <Button size="small" variant="outlined" onClick={() => session && openGame(session.token, game)}>
                            View
                          </Button>
                        </DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>
          {games?.length === 0 && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No games found.</p>
          )}
          {games === null && !error && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>
          )}
        </div>
      </div>
    </div>
  );
}
