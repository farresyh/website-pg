"use client";

/**
 * Price Sync Stage 2 (MID-1..6/SUPP-3) — two-level view, per founder
 * feedback (docs/prd.md §14): browse raw supplier items grouped by
 * `(supplier, group_label)`, link a whole group to a Game ONCE, then
 * curate individual items within that group. Re-deciding the Game on
 * every one of hundreds of items separately doesn't scale.
 *
 * ADR-067 decision 6: groups are per-supplier. `group_label` is the
 * adapter-set grouping string — Gamevion's edition-level category
 * ("Free Fire Global"), or Digiflazz's `brand` (its `category` is a
 * flat "Games"). A supplier column + filter keeps two suppliers'
 * near-identical groups apart so the founder can link both to one Game
 * and let ADR-034's denomination dedup pick the storefront winner.
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
import { Tag } from "@/components/ui/tag";
import { Button } from "@/components/ui/button";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { useDebouncedValue } from "@/hooks/useDebouncedValue";
import { useLatestRequest } from "@/hooks/useLatestRequest";
import { ApiError } from "@/lib/api-client";
import { notify } from "@/components/ui/toast";
import {
  type SupplierProduct,
  type SupplierProductCategory,
  type LinkCategoryValues,
  type PromoteValues,
  listSupplierProductCategories,
  listAllSupplierProducts,
  linkSupplierProductCategory,
  promoteSupplierProduct,
  repointSupplierProduct,
} from "@/lib/supplier-products";
import {
  CUSTOMER_NO_SEPARATOR_OPTIONS,
  EXTRA_FIELD_OPTIONS,
  PLAYER_ID_FORMAT_OPTIONS,
  type Game,
  type GamePackage,
  type GameValidationRules,
  listGames,
  listGamePackages,
} from "@/lib/games";
import { SimpleSelect } from "@/components/ui/select";
import LinkCategoryModal from "@/components/middleware/LinkCategoryModal";
import PromoteProductModal from "@/components/middleware/PromoteProductModal";
import RepointProductModal from "@/components/middleware/RepointProductModal";

function formatRm(sen: number | null): string {
  return sen === null ? "—" : `RM ${(sen / 100).toFixed(2)}`;
}

/**
 * ADR-069 — the "IDR 20,000" sanity line under the converted MYR price.
 * Stress-test Q4: only meaningful when a real conversion happened —
 * hidden for MYR-native suppliers (Gamevion), where raw == converted
 * and the line is pure noise.
 */
function formatRaw(price: string | null, currency: string | null, priceSen: number | null): string | null {
  if (price === null || currency === null || currency === "MYR") return null;
  const n = Number(price);
  if (!Number.isFinite(n)) return null;
  if (priceSen !== null && Math.round(n * 100) === priceSen) return null;
  return `${currency} ${n.toLocaleString(undefined, { maximumFractionDigits: 2 })}`;
}

/** ADR-067 decision 6: a group is identified by (supplier id, group_label). */
function groupKey(c: SupplierProductCategory): string {
  return `${c.supplier?.id ?? "?"}:${c.group_label}`;
}

/** ADR-067 2026-11-07 addendum: a package can't change game, so a group with one keeps its link. */
function canLink(c: SupplierProductCategory): boolean {
  return !c.game || c.promoted_count === 0;
}

function sameGroup(a: SupplierProductCategory, b: SupplierProductCategory): boolean {
  return groupKey(a) === groupKey(b);
}

/**
 * The admin, not any supplier API, decides what a game's checkout
 * needs (ADR-005 addendum). LinkCategoryModal sets it for a new game
 * only; this editor is the one place an existing game's rule changes
 * (ADR-067 2026-11-07 addendum). Reuses linkSupplierProductCategory
 * directly — game_id doesn't change, only validation_rules.
 *
 * ADR-097 decision 16: ONE "Update" button saves the WHOLE
 * `validation_rules` object (every field this editor knows about),
 * never a partial `{extra_field: X}`-only payload. The backend
 * (`SupplierProductController::linkCategory()`) merges since the
 * 2026-10-05 addendum (decision 30), but this editor still owns every
 * key it shows, so one shared save action sends them all.
 */
function CheckoutInputEditor({
  initial,
  supplierSlug,
  onUpdate,
}: {
  initial: GameValidationRules | null | undefined;
  /** ADR-097 decision 20 — customer_no_separator only applies to a Digiflazz-linked game; hidden here to match the server-side restriction, not just decoration. */
  supplierSlug: string | null | undefined;
  onUpdate: (rules: GameValidationRules) => Promise<void>;
}) {
  const [extraField, setExtraField] = useState(initial?.extra_field ?? "");
  const [separator, setSeparator] = useState(initial?.customer_no_separator ?? "");
  const [playerIdFormat, setPlayerIdFormat] = useState<string>(initial?.player_id_format ?? "numeric");
  // ADR-097 decision 8 — comma-separated text, same house pattern
  // EditSupplierModal already uses for a "list"-type api_config field
  // (split/trim/filter on save), not a bespoke chip editor.
  const [zoneOptionsText, setZoneOptionsText] = useState((initial?.zone_options ?? []).join(", "));
  const [saving, setSaving] = useState(false);
  const isDigiflazz = supplierSlug === "digiflazz";
  const isZoneId = extraField === "zone_id";

  function parsedZoneOptions(): string[] {
    return zoneOptionsText
      .split(",")
      .map((s) => s.trim())
      .filter(Boolean);
  }

  async function handleUpdate() {
    setSaving(true);
    try {
      await onUpdate({
        extra_field: extraField === "" ? null : (extraField as "server_id" | "zone_id"),
        // Never send a stale separator for a non-Digiflazz game, even
        // if local state somehow still holds one from a prior game.
        customer_no_separator: isDigiflazz && separator !== "" ? (separator as "concat" | "space" | "pipe") : null,
        // Same discipline: never send a stale list for a non-zone_id game.
        zone_options: isZoneId ? parsedZoneOptions() : null,
        player_id_format: playerIdFormat === "text" ? "text" : "numeric",
      });
      notify.success("Checkout input updated");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center gap-3">
        <label className="w-40 text-sm text-ink-muted">Checkout field</label>
        <SimpleSelect value={extraField} onChange={setExtraField} options={EXTRA_FIELD_OPTIONS} className="w-56" />
      </div>
      <div className="flex flex-col gap-1.5">
        <div className="flex flex-wrap items-center gap-3">
          <label className="w-40 text-sm text-ink-muted">User ID format</label>
          <SimpleSelect value={playerIdFormat} onChange={setPlayerIdFormat} options={PLAYER_ID_FORMAT_OPTIONS} className="w-56" />
        </div>
        <p className="ml-[10.75rem] text-theme-xs text-ink-muted">
          Checked on every channel (storefront, Reseller API, WhatsApp bot). Text allows letters, digits and # . _ -
          (e.g. a Riot ID, JettMain#1234). Server ID is always digits only.
        </p>
      </div>
      {isDigiflazz && (
        <div className="flex flex-wrap items-center gap-3">
          <label className="w-40 text-sm text-ink-muted">customer_no separator</label>
          <SimpleSelect value={separator} onChange={setSeparator} options={CUSTOMER_NO_SEPARATOR_OPTIONS} className="w-56" />
        </div>
      )}
      {isZoneId && (
        <div className="flex flex-col gap-1.5">
          <div className="flex flex-wrap items-center gap-3">
            <label className="w-40 text-sm text-ink-muted">Zone ID options</label>
            <input
              type="text"
              value={zoneOptionsText}
              onChange={(e) => setZoneOptionsText(e.target.value)}
              placeholder="e.g. SouthEastAsia, MENA, Europe"
              className="w-96 rounded-lg border border-border-control bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-subtle outline-none focus:border-cyan-600"
            />
          </div>
          <p className="ml-[10.75rem] text-theme-xs text-ink-muted">
            Comma-separated. Whatever string is entered here is forwarded to the supplier verbatim — customers pick
            from this exact list on checkout. Leave empty to keep today&apos;s free-text input for this game.
          </p>
        </div>
      )}
      <div className="flex flex-wrap items-center gap-3">
        <Button size="small" disabled={saving} onClick={handleUpdate}>
          {saving ? "Saving…" : "Update"}
        </Button>
        <code className="rounded bg-subtle px-1.5 py-0.5 text-theme-xs text-ink-muted">
          {JSON.stringify({
            extra_field: extraField === "" ? null : extraField,
            player_id_format: playerIdFormat,
            ...(isDigiflazz ? { customer_no_separator: separator === "" ? null : separator } : {}),
            ...(isZoneId ? { zone_options: parsedZoneOptions() } : {}),
          })}
        </code>
      </div>
    </div>
  );
}

export default function ProductManagerPage() {
  const router = useRouter();
  // Read in an effect, not render body — see UserDropdown.tsx for why.
  const session = useClientSession();

  const [categories, setCategories] = useState<SupplierProductCategory[] | null>(null);
  const [games, setGames] = useState<Game[]>([]);
  const [categorySearch, setCategorySearch] = useState("");
  const debouncedCategorySearch = useDebouncedValue(categorySearch);
  const beginCategoriesLoad = useLatestRequest();
  const [supplierFilter, setSupplierFilter] = useState("");
  const [error, setError] = useState<string | null>(null);

  const [selected, setSelected] = useState<SupplierProductCategory | null>(null);
  const [items, setItems] = useState<SupplierProduct[] | null>(null);
  const [catalogPackages, setCatalogPackages] = useState<GamePackage[] | null>(null);
  const [tab, setTab] = useState<"available" | "catalog" | "checkout_input">("available");

  const [linkingCategory, setLinkingCategory] = useState<SupplierProductCategory | null>(null);
  const [promoting, setPromoting] = useState<SupplierProduct | null>(null);
  const [repointing, setRepointing] = useState<SupplierProduct | null>(null);

  /** Reloads the group list; resolves to it, or null when it failed or a newer load superseded this one. */
  function refreshCategories(): Promise<SupplierProductCategory[] | null> {
    const isLatest = beginCategoriesLoad();
    return listSupplierProductCategories({ search: debouncedCategorySearch || undefined })
      .then((list) => {
        if (!isLatest()) return null;
        setCategories(list);
        return list;
      })
      .catch((err: unknown) => {
        if (isLatest()) setError(err instanceof ApiError ? err.message : "Could not load categories.");
        return null;
      });
  }

  async function openCategory(category: SupplierProductCategory) {
    setSelected(category);
    setTab("available");
    setItems(null);
    setCatalogPackages(null);

    try {
      setItems(
        await listAllSupplierProducts({
          supplier_id: category.supplier?.id,
          group_label: category.group_label,
        }),
      );
      if (category.game_id) {
        setCatalogPackages(await listGamePackages(category.game_id));
      } else {
        setCatalogPackages([]);
      }
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load this category.");
    }
  }

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }

    listGames().then(setGames).catch(() => {
      // Non-fatal — "new game" mode in the link modal still works without the picker.
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!session) return;

    refreshCategories();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, debouncedCategorySearch]);

  async function handleLinkSubmit(values: LinkCategoryValues) {
    if (!session || !linkingCategory) return;
    await linkSupplierProductCategory(values);
    notify.success("Category linked");
    setLinkingCategory(null);

    // Re-open the category so it immediately shows as linked, instead
    // of the admin having to click it again.
    const refreshed = await refreshCategories();
    const reopened = refreshed?.find((c) => sameGroup(c, linkingCategory));
    if (reopened) await openCategory(reopened);
  }

  async function handleUpdateCheckoutInput(rules: GameValidationRules) {
    if (!session || !selected?.game || !selected.supplier) return;

    await linkSupplierProductCategory({
      supplier_id: selected.supplier.id,
      group_label: selected.group_label,
      game_id: selected.game.id,
      validation_rules: rules,
    });

    notify.success("Checkout input updated");
    const refreshed = await refreshCategories();
    const reopened = refreshed?.find((c) => sameGroup(c, selected));
    if (reopened) setSelected(reopened);
  }

  async function handleRepointSubmit(packageId: number) {
    if (!session || !repointing || !selected) return;
    await repointSupplierProduct(repointing.id, packageId);
    notify.success("Package linked to the new item");
    setRepointing(null);
    await openCategory(selected);
    await refreshCategories();
  }

  async function handlePromoteSubmit(values: PromoteValues) {
    if (!session || !promoting || !selected) return;
    const { notice } = await promoteSupplierProduct(promoting.id, values);
    if (notice) notify.warn("Added, but not on sale", notice);
    else notify.success("Product promoted");
    setPromoting(null);
    await openCategory(selected);
    await refreshCategories();
  }

  if (selected) {
    return (
      <div>
        <button
          onClick={() => setSelected(null)}
          className="mb-4 text-sm text-ink-muted hover:text-ink"
        >
          ← Back to categories
        </button>

        <div className="mb-6 flex items-center justify-between">
          <div>
            <h1 className="text-page-title font-semibold text-ink">
              {selected.group_label || "Uncategorized"}
            </h1>
            <p className="mt-1 text-sm text-ink-muted">
              <span className="font-medium">{selected.supplier?.name ?? "Unknown supplier"}</span>
              {" · "}
              {selected.game ? (
                <>
                  Linked to <span className="font-medium">{selected.game.name}</span>
                </>
              ) : (
                "Not linked to a game yet"
              )}
            </p>
          </div>
          {canLink(selected) && (
            <Button size="small" onClick={() => setLinkingCategory(selected)}>
              {selected.game ? "Change Game" : "Link to Game"}
            </Button>
          )}
        </div>

        {error && (
          <p className="mb-4 rounded-lg bg-danger-surface px-4 py-3 text-sm text-danger-ink">
            {error}
          </p>
        )}

        {!selected.game ? (
          <div className="rounded-2xl border border-border bg-surface p-8 text-center">
            <p className="text-sm text-ink-muted">
              Link this group to a Game before curating its {selected.total} item{selected.total === 1 ? "" : "s"}.
            </p>
          </div>
        ) : (
          <>
            <div className="mb-4 flex gap-2 border-b border-border">
              <button
                onClick={() => setTab("available")}
                className={`px-4 py-2 text-sm font-medium ${tab === "available" ? "border-b-2 border-cyan-600 text-cyan-ink" : "text-ink-muted hover:text-ink"}`}
              >
                Available Packages
              </button>
              <button
                onClick={() => setTab("catalog")}
                className={`px-4 py-2 text-sm font-medium ${tab === "catalog" ? "border-b-2 border-cyan-600 text-cyan-ink" : "text-ink-muted hover:text-ink"}`}
              >
                Catalog ({catalogPackages?.length ?? 0})
              </button>
              <button
                onClick={() => setTab("checkout_input")}
                className={`px-4 py-2 text-sm font-medium ${tab === "checkout_input" ? "border-b-2 border-cyan-600 text-cyan-ink" : "text-ink-muted hover:text-ink"}`}
              >
                Checkout Input
              </button>
            </div>

            {tab === "available" && (
              <div className="overflow-hidden rounded-2xl border border-border bg-surface">
                <div className="max-w-full overflow-x-auto">
                  <DataTable data={items ?? []} dataKey="id">
                    <DataTableTableContainer>
                      <DataTableTable>
                        <DataTableTHead className="border-b border-border">
                          <DataTableTHeadRow>
                            <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Name</DataTableTHeadCell>
                            <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Supplier Cost</DataTableTHeadCell>
                            <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Catalog</DataTableTHeadCell>
                            <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Actions</DataTableTHeadCell>
                          </DataTableTHeadRow>
                        </DataTableTHead>
                        <DataTableTBody className="divide-y divide-border">
                          {({ item }) => {
                            const product = item as unknown as SupplierProduct;

                            return (
                              <DataTableRow key={product.id}>
                                <DataTableCell className="px-5 py-4 text-theme-sm">
                                  <span className="font-medium text-ink">{product.name}</span>
                                  <br />
                                  <span className="text-theme-xs text-ink-subtle">ID: {product.external_ref}</span>
                                </DataTableCell>
                                <DataTableCell className="px-5 py-4 text-theme-sm text-ink-muted">
                                  {formatRm(product.price_sen)}
                                  {formatRaw(product.raw_price, product.raw_currency, product.price_sen) && (
                                    <>
                                      <br />
                                      <span className="text-theme-xs text-ink-subtle">
                                        {formatRaw(product.raw_price, product.raw_currency, product.price_sen)}
                                      </span>
                                    </>
                                  )}
                                </DataTableCell>
                                <DataTableCell className="px-5 py-4 text-theme-sm">
                                  <Tag severity={product.is_promoted ? "success" : "secondary"}>
                                    {product.is_promoted ? "In catalog" : "Not added"}
                                  </Tag>
                                </DataTableCell>
                                <DataTableCell className="px-5 py-4 text-theme-sm">
                                  {product.is_promoted ? (
                                    <span className="text-ink-subtle">—</span>
                                  ) : (
                                    <div className="flex flex-wrap gap-2">
                                      <Button
                                        size="small"
                                        disabled={product.price_sen === null}
                                        onClick={() => setPromoting(product)}
                                      >
                                        Add to Catalog
                                      </Button>
                                      <Button
                                        size="small"
                                        variant="outlined"
                                        disabled={product.price_sen === null}
                                        onClick={() => setRepointing(product)}
                                      >
                                        Link to existing
                                      </Button>
                                    </div>
                                  )}
                                </DataTableCell>
                              </DataTableRow>
                            );
                          }}
                        </DataTableTBody>
                      </DataTableTable>
                    </DataTableTableContainer>
                  </DataTable>
                  {items === null && <p className="p-6 text-center text-sm text-ink-muted">Loading…</p>}
                </div>
              </div>
            )}

            {tab === "catalog" && (
              <div className="overflow-hidden rounded-2xl border border-border bg-surface">
                <div className="max-w-full overflow-x-auto">
                  <DataTable data={catalogPackages ?? []} dataKey="id">
                    <DataTableTableContainer>
                      <DataTableTable>
                        <DataTableTHead className="border-b border-border">
                          <DataTableTHeadRow>
                            <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Final Name</DataTableTHeadCell>
                            <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Cost Price</DataTableTHeadCell>
                            <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Standard Selling Price</DataTableTHeadCell>
                            <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Status</DataTableTHeadCell>
                          </DataTableTHeadRow>
                        </DataTableTHead>
                        <DataTableTBody className="divide-y divide-border">
                          {({ item }) => {
                            const pkg = item as unknown as GamePackage;

                            return (
                              <DataTableRow key={pkg.id}>
                                <DataTableCell className="px-5 py-4 text-theme-sm">
                                  <span className="font-medium text-ink">{pkg.name}</span>
                                  <br />
                                  <span className="text-theme-xs text-ink-subtle">Supplier ID: {pkg.supplier_package_ref}</span>
                                </DataTableCell>
                                <DataTableCell className="px-5 py-4 text-theme-sm text-ink-muted">{formatRm(pkg.cost_price)}</DataTableCell>
                                <DataTableCell className="px-5 py-4 text-theme-sm text-ink-muted">{formatRm(pkg.standard_selling_price)}</DataTableCell>
                                <DataTableCell className="px-5 py-4 text-theme-sm">
                                  <Tag severity={pkg.is_active ? "success" : "secondary"}>
                                    {pkg.is_active ? "Active" : "Inactive"}
                                  </Tag>
                                </DataTableCell>
                              </DataTableRow>
                            );
                          }}
                        </DataTableTBody>
                      </DataTableTable>
                    </DataTableTableContainer>
                  </DataTable>
                  {catalogPackages?.length === 0 && (
                    <p className="p-6 text-center text-sm text-ink-muted">
                      No packages in catalog yet. Go to &quot;Available Packages&quot; and add some.
                    </p>
                  )}
                </div>
              </div>
            )}

            {tab === "checkout_input" && selected.game && (
              <div className="rounded-2xl border border-border bg-surface p-6">
                <p className="mb-4 text-sm text-ink-muted">
                  What the supplier needs beyond Player ID (UID) for orders in this group — the admin decides this,
                  not a supplier API (ADR-005 addendum). Applies to every package under{" "}
                  <span className="font-medium text-ink">{selected.game.name}</span>.
                </p>
                <CheckoutInputEditor
                  key={`${groupKey(selected)}-${selected.game.id}`}
                  initial={selected.game.validation_rules}
                  supplierSlug={selected.supplier?.slug}
                  onUpdate={handleUpdateCheckoutInput}
                />
              </div>
            )}
          </>
        )}

        {selected.game && (
          <RepointProductModal
            isOpen={repointing !== null}
            onClose={() => setRepointing(null)}
            onSubmit={handleRepointSubmit}
            product={repointing}
            gameId={selected.game.id}
            gameName={selected.game.name}
          />
        )}

        {selected.game && (
          <PromoteProductModal
            isOpen={promoting !== null}
            onClose={() => setPromoting(null)}
            onSubmit={handlePromoteSubmit}
            product={promoting}
            gameName={selected.game.name}
          />
        )}
      </div>
    );
  }

  const supplierOptions = [
    { value: "", label: "All suppliers" },
    ...Array.from(
      new Map((categories ?? []).flatMap((c) => (c.supplier ? [[c.supplier.slug, c.supplier.name] as const] : []))).entries(),
    ).map(([slug, name]) => ({ value: slug, label: name })),
  ];

  const visibleCategories = (categories ?? [])
    .filter((c) => supplierFilter === "" || c.supplier?.slug === supplierFilter)
    .map((c) => ({ ...c, _key: groupKey(c) }));

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-page-title font-semibold text-ink">Product Manager</h1>
          <p className="mt-1 text-sm text-ink-muted">
            Raw supplier catalogs, grouped per supplier ({categories ? categories.length : "…"} groups). Link a
            group to a Game once, then curate its packages. Gamevion and Digiflazz can each carry the same game —
            link both groups to one Game (ADR-067).
          </p>
        </div>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-danger-surface px-4 py-3 text-sm text-danger-ink">
          {error}
        </p>
      )}

      <div className="mb-4 flex flex-wrap items-center gap-3">
        <input
          type="text"
          placeholder="Search groups…"
          value={categorySearch}
          onChange={(e) => setCategorySearch(e.target.value)}
          className="h-11 w-full max-w-sm rounded-lg border border-border-control bg-surface px-4 py-2.5 text-sm text-ink placeholder:text-ink-subtle focus:border-cyan-600 focus:outline-hidden focus:ring-3 focus:ring-focus-ring/10"
        />
        <SimpleSelect value={supplierFilter} onChange={setSupplierFilter} options={supplierOptions} className="w-48" />
      </div>

      <div className="overflow-hidden rounded-2xl border border-border bg-surface">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={visibleCategories} dataKey="_key">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-border">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Supplier</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Group</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Items</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">In Catalog</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Linked Game</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-border">
                  {({ item }) => {
                    const category = item as unknown as SupplierProductCategory;

                    return (
                      <DataTableRow key={groupKey(category)}>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-ink-muted">
                          {category.supplier?.name ?? "—"}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-ink">
                          {category.group_label || "Uncategorized"}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-ink-muted">{category.total}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-ink-muted">{category.promoted_count}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          {category.game ? (
                            <Tag severity="success">{category.game.name}</Tag>
                          ) : (
                            <Tag severity="secondary">Not linked</Tag>
                          )}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <div className="flex gap-2">
                            <Button size="small" variant="outlined" onClick={() => session && openCategory(category)}>
                              View
                            </Button>
                            {canLink(category) && (
                              <Button size="small" onClick={() => setLinkingCategory(category)}>
                                {category.game ? "Change" : "Link"}
                              </Button>
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

          {categories !== null && visibleCategories.length === 0 && (
            <p className="p-6 text-center text-sm text-ink-muted">No groups found.</p>
          )}
          {categories === null && !error && (
            <p className="p-6 text-center text-sm text-ink-muted">Loading…</p>
          )}
        </div>
      </div>

      <LinkCategoryModal
        isOpen={linkingCategory !== null}
        onClose={() => setLinkingCategory(null)}
        onSubmit={handleLinkSubmit}
        supplierId={linkingCategory?.supplier?.id ?? null}
        groupLabel={linkingCategory?.group_label ?? null}
        itemCount={linkingCategory?.total ?? 0}
        currentGameId={linkingCategory?.game?.id}
        games={games}
      />
    </div>
  );
}
