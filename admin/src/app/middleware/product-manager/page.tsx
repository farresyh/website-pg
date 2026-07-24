"use client";

/**
 * Price Sync Stage 2 (MID-1..6/SUPP-3) — two-level view, per founder
 * feedback (docs/prd.md §14): browse raw Gamevion items grouped by
 * `category_raw` (~22 groups), link a whole group to a Game ONCE, then
 * curate individual items within that group. Re-deciding the Game on
 * every one of 316 items separately doesn't scale.
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Table, TableHeader, TableBody, TableRow, TableCell } from "@/components/ui/table";
import Badge from "@/components/ui/badge/Badge";
import Button from "@/components/ui/button/Button";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import {
  type SupplierProduct,
  type SupplierProductCategory,
  type LinkCategoryValues,
  type PromoteValues,
  listSupplierProductCategories,
  listSupplierProducts,
  linkSupplierProductCategory,
  promoteSupplierProduct,
} from "@/lib/supplier-products";
import { type Game, type GamePackage, listGames, listGamePackages } from "@/lib/games";
import LinkCategoryModal from "@/components/middleware/LinkCategoryModal";
import PromoteProductModal from "@/components/middleware/PromoteProductModal";

function formatRm(sen: number | null): string {
  return sen === null ? "—" : `RM ${(sen / 100).toFixed(2)}`;
}

export default function ProductManagerPage() {
  const router = useRouter();
  const session = getClientSession();

  const [categories, setCategories] = useState<SupplierProductCategory[] | null>(null);
  const [games, setGames] = useState<Game[]>([]);
  const [categorySearch, setCategorySearch] = useState("");
  const [error, setError] = useState<string | null>(null);

  const [selected, setSelected] = useState<SupplierProductCategory | null>(null);
  const [items, setItems] = useState<SupplierProduct[] | null>(null);
  const [catalogPackages, setCatalogPackages] = useState<GamePackage[] | null>(null);
  const [tab, setTab] = useState<"available" | "catalog">("available");

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
      setItems((await listSupplierProducts(token, { category: category.category_raw ?? undefined, page: 1 })).data);
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
    if (!session) {
      router.replace("/login");
      return;
    }

    listGames(session.token).then(setGames).catch(() => {
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
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [categorySearch]);

  async function handleLinkSubmit(values: LinkCategoryValues) {
    if (!session || !linkingCategory) return;
    await linkSupplierProductCategory(session.token, values);
    setLinkingCategory(null);
    await refreshCategories(session.token);

    // Re-open the category so it immediately shows as linked, instead
    // of the admin having to click it again.
    const refreshed = await listSupplierProductCategories(session.token, { search: categorySearch || undefined });
    setCategories(refreshed);
    const reopened = refreshed.find((c) => c.category_raw === linkingCategory.category_raw);
    if (reopened) await openCategory(session.token, reopened);
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
              {selected.category_raw ?? "Uncategorized"}
            </h1>
            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
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
            <Button size="sm" onClick={() => setLinkingCategory(selected)}>
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
              Link this category to a Game before curating its {selected.total} item{selected.total === 1 ? "" : "s"}.
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
            </div>

            {tab === "available" ? (
              <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div className="max-w-full overflow-x-auto">
                  <Table>
                    <TableHeader className="border-b border-gray-100 dark:border-gray-800">
                      <TableRow>
                        <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Name</TableCell>
                        <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Supplier Cost</TableCell>
                        <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Catalog</TableCell>
                        <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</TableCell>
                      </TableRow>
                    </TableHeader>
                    <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
                      {items?.map((product) => (
                        <TableRow key={product.id}>
                          <TableCell className="px-5 py-4 text-theme-sm">
                            <span className="font-medium text-gray-800 dark:text-white/90">{product.name}</span>
                            <br />
                            <span className="text-theme-xs text-gray-400">ID: {product.external_ref}</span>
                          </TableCell>
                          <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                            {formatRm(product.price_sen)}
                          </TableCell>
                          <TableCell className="px-5 py-4 text-theme-sm">
                            <Badge size="sm" color={product.is_promoted ? "success" : "light"}>
                              {product.is_promoted ? "In catalog" : "Not added"}
                            </Badge>
                          </TableCell>
                          <TableCell className="px-5 py-4 text-theme-sm">
                            {product.is_promoted ? (
                              <span className="text-gray-400">—</span>
                            ) : (
                              <Button
                                size="sm"
                                disabled={product.price_sen === null}
                                onClick={() => setPromoting(product)}
                              >
                                Add to Catalog
                              </Button>
                            )}
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                  {items === null && <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>}
                </div>
              </div>
            ) : (
              <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div className="max-w-full overflow-x-auto">
                  <Table>
                    <TableHeader className="border-b border-gray-100 dark:border-gray-800">
                      <TableRow>
                        <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Final Name</TableCell>
                        <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Cost Price</TableCell>
                        <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Reseller Cost Price</TableCell>
                        <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</TableCell>
                      </TableRow>
                    </TableHeader>
                    <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
                      {catalogPackages?.map((pkg) => (
                        <TableRow key={pkg.id}>
                          <TableCell className="px-5 py-4 text-theme-sm">
                            <span className="font-medium text-gray-800 dark:text-white/90">{pkg.name}</span>
                            <br />
                            <span className="text-theme-xs text-gray-400">Supplier ID: {pkg.supplier_package_ref}</span>
                          </TableCell>
                          <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{formatRm(pkg.cost_price)}</TableCell>
                          <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{formatRm(pkg.reseller_cost_price)}</TableCell>
                          <TableCell className="px-5 py-4 text-theme-sm">
                            <Badge size="sm" color={pkg.is_active ? "success" : "light"}>
                              {pkg.is_active ? "Active" : "Inactive"}
                            </Badge>
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                  {catalogPackages?.length === 0 && (
                    <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">
                      No packages in catalog yet. Go to &quot;Available Packages&quot; and add some.
                    </p>
                  )}
                </div>
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

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Product Manager</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Raw Gamevion catalog, grouped by category ({categories ? categories.length : "…"} groups). Link a
            category to a Game once, then curate its packages.
          </p>
        </div>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <div className="mb-4">
        <input
          type="text"
          placeholder="Search categories…"
          value={categorySearch}
          onChange={(e) => setCategorySearch(e.target.value)}
          className="h-11 w-full max-w-sm rounded-lg border border-gray-300 px-4 py-2.5 text-sm shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
        />
      </div>

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <Table>
            <TableHeader className="border-b border-gray-100 dark:border-gray-800">
              <TableRow>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Category</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Items</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">In Catalog</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Linked Game</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</TableCell>
              </TableRow>
            </TableHeader>
            <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
              {categories?.map((category) => (
                <TableRow key={category.category_raw ?? "uncategorized"}>
                  <TableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                    {category.category_raw ?? "Uncategorized"}
                  </TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{category.total}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{category.promoted_count}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">
                    {category.game ? (
                      <Badge size="sm" color="success">{category.game.name}</Badge>
                    ) : (
                      <Badge size="sm" color="light">Not linked</Badge>
                    )}
                  </TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">
                    <div className="flex gap-2">
                      <Button size="sm" variant="outline" onClick={() => session && openCategory(session.token, category)}>
                        View
                      </Button>
                      {!category.game && (
                        <Button size="sm" onClick={() => setLinkingCategory(category)}>
                          Link
                        </Button>
                      )}
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>

          {categories?.length === 0 && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No categories found.</p>
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
        categoryRaw={linkingCategory?.category_raw ?? null}
        itemCount={linkingCategory?.total ?? 0}
        games={games}
      />
    </div>
  );
}
