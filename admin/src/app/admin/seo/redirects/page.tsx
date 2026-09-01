"use client";

/** ADR-029 decision 3/9, addendum 2 decision 15: reseller-scoped exact-path redirects, hit-counted. */

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
import { Button } from "@/components/ui/button";
import { PlusIcon } from "@/icons";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { listRedirects, createRedirect, updateRedirect, deleteRedirect, type Redirect } from "@/lib/seo";
import SaveRedirectModal from "@/components/seo/SaveRedirectModal";

export default function RedirectsPage() {
  const router = useRouter();
  const session = useClientSession();
  const [redirects, setRedirects] = useState<Redirect[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<Redirect | null>(null);
  const [deletingId, setDeletingId] = useState<number | null>(null);

  function refresh(token: string) {
    return listRedirects(token)
      .then(setRedirects)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load redirects.");
      });
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

  async function handleSubmit(values: Parameters<typeof createRedirect>[1]) {
    if (!session) return;
    if (editing) {
      await updateRedirect(session.token, editing.id, values);
    } else {
      await createRedirect(session.token, values);
    }
    setModalOpen(false);
    setEditing(null);
    await refresh(session.token);
  }

  async function handleDelete(redirect: Redirect) {
    if (!session) return;
    setDeletingId(redirect.id);
    try {
      await deleteRedirect(session.token, redirect.id);
      await refresh(session.token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not delete redirect.");
    } finally {
      setDeletingId(null);
    }
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Redirects</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Exact-path 301/302 redirects, tracked by hit count.</p>
        </div>
        <Button size="small" onClick={() => { setEditing(null); setModalOpen(true); }}>
          <PlusIcon />
          Add Redirect
        </Button>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={redirects ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">From</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">To</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Hits</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item }) => {
                    const r = item as unknown as Redirect;

                    return (
                      <DataTableRow key={r.id}>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">{r.from_path}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{r.to_path}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{r.status_code}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{r.hit_count}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <div className="flex gap-3">
                            <button type="button" className="text-brand-500 hover:underline" onClick={() => { setEditing(r); setModalOpen(true); }}>Edit</button>
                            <button type="button" className="text-error-500 hover:underline" disabled={deletingId === r.id} onClick={() => handleDelete(r)}>Delete</button>
                          </div>
                        </DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>

          {redirects?.length === 0 && <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No redirects yet.</p>}
          {redirects === null && !error && <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>}
        </div>
      </div>

      <SaveRedirectModal
        isOpen={modalOpen}
        redirect={editing}
        onClose={() => { setModalOpen(false); setEditing(null); }}
        onSubmit={handleSubmit}
      />
    </div>
  );
}
