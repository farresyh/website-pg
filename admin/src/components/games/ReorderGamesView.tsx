"use client";

/**
 * GAME-6 — dedicated drag-drop reorder mode for /admin/games. Operates
 * on the full, unfiltered game list (not whatever search/status filter
 * the main list currently has) so the submitted order always covers
 * every game — a partial reorder would leave `sort_order` mismatched
 * against games left out of the drag list. Local-only until "Save
 * Order" is pressed (mirrors the reference the founder supplied);
 * "Cancel" discards the local order with no write at all.
 */

import { useState } from "react";
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
  DataTableRowReorder,
} from "@/components/ui/datatable";
import { Button } from "@/components/ui/button";
import type { Game } from "@/lib/games";

function GripIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" className="text-gray-400">
      <circle cx="5" cy="3" r="1.3" fill="currentColor" />
      <circle cx="11" cy="3" r="1.3" fill="currentColor" />
      <circle cx="5" cy="8" r="1.3" fill="currentColor" />
      <circle cx="11" cy="8" r="1.3" fill="currentColor" />
      <circle cx="5" cy="13" r="1.3" fill="currentColor" />
      <circle cx="11" cy="13" r="1.3" fill="currentColor" />
    </svg>
  );
}

export default function ReorderGamesView({
  initialGames,
  onSave,
  onCancel,
}: {
  initialGames: Game[];
  onSave: (orderedIds: number[]) => Promise<void>;
  onCancel: () => void;
}) {
  const [order, setOrder] = useState<Game[]>(initialGames);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSave() {
    setSaving(true);
    setError(null);
    try {
      await onSave(order.map((g) => g.id));
    } catch {
      setError("Could not save the new order.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div>
      <div className="mb-4 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Reorder Games</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Drag games to reorder. This is also the order Quick Top-Up picks its tiles from.
          </p>
        </div>
        <div className="flex gap-2">
          <Button size="small" variant="outlined" onClick={onCancel} disabled={saving}>
            Cancel
          </Button>
          <Button size="small" severity="success" onClick={handleSave} disabled={saving}>
            {saving ? "Saving…" : "Save Order"}
          </Button>
        </div>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <div className="mb-4 rounded-lg bg-brand-50 px-4 py-3 text-sm text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
        Drag the grip handle to reorder. Click &quot;Save Order&quot; when done — nothing is saved until then.
      </div>

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable
            data={order}
            dataKey="id"
            reorderableRows
            onRowReorder={(event) => setOrder(event.value as unknown as Game[])}
          >
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="w-10 px-3 py-3" />
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Name</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Category</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item, index }) => {
                    const game = item as unknown as Game;

                    return (
                      <DataTableRow key={game.id}>
                        <DataTableCell className="px-3 py-4">
                          <DataTableRowReorder rowIndex={index} className="cursor-grab active:cursor-grabbing">
                            <GripIcon />
                          </DataTableRowReorder>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">{game.name}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{game.category ?? "—"}</DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>
          {order.length === 0 && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No games to reorder.</p>
          )}
        </div>
      </div>
    </div>
  );
}
