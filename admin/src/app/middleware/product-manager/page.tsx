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
import { ApiError } from "@/lib/api-client";
import {
  type SupplierProduct,
  type SupplierProductCategory,
  type LinkCategoryValues,
  type PromoteValues,
  listSupplierProductCategories,
  listAllSupplierProducts,
  linkSupplierProductCategory,
  promoteSupplierProduct,
} from "@/lib/supplier-products";
import {
  CUSTOMER_NO_SEPARATOR_OPTIONS,
  EXTRA_FIELD_OPTIONS,
  type Game,
  type GamePackage,
  type GameValidationRules,
  listGames,
  listGamePackages,
} from "@/lib/games";
import { SimpleSelect } from "@/components/ui/select";
import LinkCategoryModal from "@/components/middleware/LinkCategoryModal";
import PromoteProductModal from "@/components/middleware/PromoteProductModal";

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

function sameGroup(a: SupplierProductCategory, b: SupplierProductCategory): boolean {
  return groupKey(a) === groupKey(b);
}

/**
 * The admin, not any supplier API, decides what a game's checkout
 * needs (ADR-005 addendum) — LinkCategoryModal sets it once at
 * link time, but until this editor existed there was no way to
 * correct a wrong choice afterward (the "Link to Game" button
 * disappears once a category is linked). Reuses linkSupplierProductCategory
 * directly — game_id doesn't change, only validation_rules.
 *
 * ADR-097 decision 16: ONE "Update" button saves the WHOLE
 * `validation_rules` object (every field this editor knows about),
 * never a partial `{extra_field: X}`-only payload — the backend
 * (`SupplierProductController::linkCategory()`) does a straight
 * column replace, not a merge, so a partial save here would silently
 * wipe out `customer_no_separator` (decision 15) the next time either
 * control is touched. This is why there's one shared save action
 * instead of a separate button per field.
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
      });
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center gap-3">
        <label className="w-40 text-sm text-gray-600 dark:text-gray-300">Checkout field</label>
        <SimpleSelect value={extraField} onChange={setExtraField} options={EXTRA_FIELD_OPTIONS} className="w-56" />
      </div>
      {isDigiflazz && (
        <div className="flex flex-wrap items-center gap-3">
          <label className="w-40 text-sm text-gray-600 dark:text-gray-300">customer_no separator</label>
          <SimpleSelect value={separator} onChange={setSeparator} options={CUSTOMER_NO_SEPARATOR_OPTIONS} className="w-56" />
        </div>
      )}
      {isZoneId && (
        <div className="flex flex-col gap-1.5">
          <div className="flex flex-wrap items-center gap-3">
            <label className="w-40 text-sm text-gray-600 dark:text-gray-300">Zone ID options</label>
            <input
              type="text"
              value={zoneOptionsText}
              onChange={(e) => setZoneOptionsText(e.target.value)}
              placeholder="e.g. SouthEastAsia, MENA, Europe"
              className="w-96 rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-sm text-gray-800 outline-none focus:border-brand-300 dark:border-gray-700 dark:text-white/90"
            />
          </div>
          <p className="ml-[10.75rem] text-theme-xs text-gray-500 dark:text-gray-400">
            Comma-separated. Whatever string is entered here is forwarded to the supplier verbatim — customers pick
            from this exact list on checkout. Leave empty to keep today&apos;s free-text input for this game.
          </p>
        </div>
      )}
      <div className="flex flex-wrap items-center gap-3">
        <Button size="small" disabled={saving} onClick={handleUpdate}>
          {saving ? "Saving…" : "Update"}
        </Button>
        <code className="rounded bg-gray-100 px-1.5 py-0.5 text-theme-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">
          {JSON.stringify({
            extra_field: extraField === "" ? null : extraField,
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
  const [supplierFilter, setSupplierFilter] = useState("");
  const [error, setError] = useState<string | null>(null);

  const [selected, setSelected] = useState<SupplierProductCategory | null>(null);
  const [items, setItems] = useState<SupplierProduct[] | null>(null);
  const [catalogPackages, setCatalogPackages] = useState<GamePackage[] | null>(null);
  const [tab, setTab] = useState<"available" | "catalog" | "checkout_input">("available");

  const [linkingCategory, setLinkingCategory] = useState<SupplierProductCategory | null>(null);
  const [promoting, setPromoting] = useState<SupplierProduct | null>(null);

  async function refreshCategories(token: string) {
    try {
      setCategories(await listSupplierProductCategories(token, { search: categorySearch || undefined }));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load categories.");
    }
  }

  async function openCategory(token: string, category: SupplierProductCategory) {
    setSelected(category);
    setTab("available");
    setItems(null);
    setCatalogPackages(null);

    try {
      setItems(
        await listAllSupplierProducts(token, {
          supplier_id: category.supplier?.id,
          group_label: category.group_label,
        }),
      );
      if (category.game_id) {
        setCatalogPackages(await listGamePackages(token, category.game_id));
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

    listGames(s.token).then(setGames).catch(() => {
      // Non-fatal — "new game" mode in the link modal still works without the picker.
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!session) return;

    listSupplierProductCategories(session.token, { search: categorySearch || undefined })
      .then(setCategories)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load categories.");
      });
     
  }, [session, categorySearch]);

  async function handleLinkSubmit(values: LinkCategoryValues) {
    if (!session || !linkingCategory) return;
    await linkSupplierProductCategory(session.token, values);
    setLinkingCategory(null);
    await refreshCategories(session.token);

    // Re-open the category so it immediately shows as linked, instead
    // of the admin having to click it again.
    const refreshed = await listSupplierProductCategories(session.token, { search: categorySearch || undefined });
    setCategories(refreshed);
    const reopened = refreshed.find((c) => sameGroup(c, linkingCategory));
    if (reopened) await openCategory(session.token, reopened);
  }

  async function handleUpdateCheckoutInput(rules: GameValidationRules) {
    if (!session || !selected?.game || !selected.supplier) return;

    await linkSupplierProductCategory(session.token, {
      supplier_id: selected.supplier.id,
      group_label: selected.group_label,
      game_id: selected.game.id,
      validation_rules: rules,
    });

    const refreshed = await listSupplierProductCategories(session.token, { search: categorySearch || undefined });
    setCategories(refreshed);
    const reopened = refreshed.find((c) => sameGroup(c, selected));
    if (reopened) setSelected(reopened);
  }

  async function handlePromoteSubmit(values: PromoteValues) {
    if (!session || !promoting || !selected) return;
    await promoteSupplierProduct(session.token, promoting.id, values);
    setPromoting(null);
    await openCategory(session.token, selected);
    await refreshCategories(session.token);
  }

  if (selected) {
    return (
      <div>
        <button
          onClick={() => setSelected(null)}
          className="mb-4 text-sm text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white"
        >
          ← Back to categories
        </button>

        <div className="mb-6 flex items-center justify-between">
          <div>
            <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">
              {selected.group_label || "Uncategorized"}
            </h1>
            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
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
          {!selected.game && (
            <Button size="small" onClick={() => setLinkingCategory(selected)}>
              Link to Game
            </Button>
          )}
        </div>

        {error && (
          <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
            {error}
          </p>
        )}

        {!selected.game ? (
          <div className="rounded-2xl border border-gray-200 bg-white p-8 text-center dark:border-gray-800 dark:bg-white/[0.03]">
            <p className="text-sm text-gray-500 dark:text-gray-400">
              Link this group to a Game before curating its {selected.total} item{selected.total === 1 ? "" : "s"}.
            </p>
          </div>
        ) : (
          <>
            <div className="mb-4 flex gap-2 border-b border-gray-200 dark:border-gray-800">
              <button
                onClick={() => setTab("available")}
                className={`px-4 py-2 text-sm font-medium ${tab === "available" ? "border-b-2 border-brand-500 text-brand-500" : "text-gray-500 dark:text-gray-400"}`}
              >
                Available Packages
              </button>
              <button
                onClick={() => setTab("catalog")}
                className={`px-4 py-2 text-sm font-medium ${tab === "catalog" ? "border-b-2 border-brand-500 text-brand-500" : "text-gray-500 dark:text-gray-400"}`}
              >
                Catalog ({catalogPackages?.length ?? 0})
              </button>
              <button
                onClick={() => setTab("checkout_input")}
                className={`px-4 py-2 text-sm font-medium ${tab === "checkout_input" ? "border-b-2 border-brand-500 text-brand-500" : "text-gray-500 dark:text-gray-400"}`}
              >
                Checkout Input
              </button>
            </div>

            {tab === "available" && (
              <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div className="max-w-full overflow-x-auto">
                  <DataTable data={items ?? []} dataKey="id">
                    <DataTableTableContainer>
                      <DataTableTable>
                        <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                          <DataTableTHeadRow>
                            <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Name</DataTableTHeadCell>
                            <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Supplier Cost</DataTableTHeadCell>
                            <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Catalog</DataTableTHeadCell>
                            <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</DataTableTHeadCell>
                          </DataTableTHeadRow>
                        </DataTableTHead>
                        <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                          {({ item }) => {
                            const product = item as unknown as SupplierProduct;

                            return (
                              <DataTableRow key={product.id}>
                                <DataTableCell className="px-5 py-4 text-theme-sm">
                                  <span className="font-medium text-gray-800 dark:text-white/90">{product.name}</span>
                                  <br />
                                  <span className="text-theme-xs text-gray-400">ID: {product.external_ref}</span>
                                </DataTableCell>
                                <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                  {formatRm(product.price_sen)}
                                  {formatRaw(product.raw_price, product.raw_currency, product.price_sen) && (
                                    <>
                                      <br />
                                      <span className="text-theme-xs text-gray-400">
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
                                    <span className="text-gray-400">—</span>
                                  ) : (
                                    <Button
                                      size="small"
                                      disabled={product.price_sen === null}
                                      onClick={() => setPromoting(product)}
                                    >
                                      Add to Catalog
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
                  {items === null && <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>}
                </div>
              </div>
            )}

            {tab === "catalog" && (
              <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div className="max-w-full overflow-x-auto">
                  <DataTable data={catalogPackages ?? []} dataKey="id">
                    <DataTableTableContainer>
                      <DataTableTable>
                        <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                          <DataTableTHeadRow>
                            <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Final Name</DataTableTHeadCell>
                            <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Cost Price</DataTableTHeadCell>
                            <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Standard Selling Price</DataTableTHeadCell>
                            <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</DataTableTHeadCell>
                          </DataTableTHeadRow>
                        </DataTableTHead>
                        <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                          {({ item }) => {
                            const pkg = item as unknown as GamePackage;

                            return (
                              <DataTableRow key={pkg.id}>
                                <DataTableCell className="px-5 py-4 text-theme-sm">
                                  <span className="font-medium text-gray-800 dark:text-white/90">{pkg.name}</span>
                                  <br />
                                  <span className="text-theme-xs text-gray-400">Supplier ID: {pkg.supplier_package_ref}</span>
                                </DataTableCell>
                                <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{formatRm(pkg.cost_price)}</DataTableCell>
                                <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{formatRm(pkg.standard_selling_price)}</DataTableCell>
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
                    <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">
                      No packages in catalog yet. Go to &quot;Available Packages&quot; and add some.
                    </p>
                  )}
                </div>
              </div>
            )}

            {tab === "checkout_input" && selected.game && (
              <div className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                <p className="mb-4 text-sm text-gray-500 dark:text-gray-400">
                  What the supplier needs beyond Player ID (UID) for orders in this group — the admin decides this,
                  not a supplier API (ADR-005 addendum). Applies to every package under{" "}
                  <span className="font-medium text-gray-700 dark:text-gray-300">{selected.game.name}</span>.
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
          <PromoteProductModal
            isOpen={promoting !== null}
            onClose={() => setPromoting(null)}
            onSubmit={handlePromoteSubmit}
            product={promoting}
            gameId={selected.game.id}
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
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Product Manager</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Raw supplier catalogs, grouped per supplier ({categories ? categories.length : "…"} groups). Link a
            group to a Game once, then curate its packages. Gamevion and Digiflazz can each carry the same game —
            link both groups to one Game (ADR-067).
          </p>
        </div>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <div className="mb-4 flex flex-wrap items-center gap-3">
        <input
          type="text"
          placeholder="Search groups…"
          value={categorySearch}
          onChange={(e) => setCategorySearch(e.target.value)}
          className="h-11 w-full max-w-sm rounded-lg border border-gray-300 px-4 py-2.5 text-sm shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
        />
        <SimpleSelect value={supplierFilter} onChange={setSupplierFilter} options={supplierOptions} className="w-48" />
      </div>

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={visibleCategories} dataKey="_key">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Supplier</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Group</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Items</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">In Catalog</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Linked Game</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item }) => {
                    const category = item as unknown as SupplierProductCategory;

                    return (
                      <DataTableRow key={groupKey(category)}>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {category.supplier?.name ?? "—"}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                          {category.group_label || "Uncategorized"}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{category.total}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{category.promoted_count}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          {category.game ? (
                            <Tag severity="success">{category.game.name}</Tag>
                          ) : (
                            <Tag severity="secondary">Not linked</Tag>
                          )}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <div className="flex gap-2">
                            <Button size="small" variant="outlined" onClick={() => session && openCategory(session.token, category)}>
                              View
                            </Button>
                            {!category.game && (
                              <Button size="small" onClick={() => setLinkingCategory(category)}>
                                Link
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
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No groups found.</p>
          )}
          {categories === null && !error && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>
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
        games={games}
      />
    </div>
  );
}
