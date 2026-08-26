"use client";

/** ADR-029 addendum 2 decision 13: ordered, multiple, reseller-scoped head/body_end scripts. */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Table, TableHeader, TableBody, TableRow, TableCell } from "@/components/ui/table";
import Badge from "@/components/ui/badge/Badge";
import Button from "@/components/ui/button/Button";
import { PlusIcon } from "@/icons";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { listSeoScripts, createSeoScript, updateSeoScript, deleteSeoScript, type SeoScript } from "@/lib/seo";
import SaveSeoScriptModal from "@/components/seo/SaveSeoScriptModal";

export default function SeoScriptsPage() {
  const router = useRouter();
  const session = useClientSession();
  const [scripts, setScripts] = useState<SeoScript[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<SeoScript | null>(null);
  const [deletingId, setDeletingId] = useState<number | null>(null);

  function refresh(token: string) {
    return listSeoScripts(token)
      .then(setScripts)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load scripts.");
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

  async function handleSubmit(values: Parameters<typeof createSeoScript>[1]) {
    if (!session) return;
    if (editing) {
      await updateSeoScript(session.token, editing.id, values);
    } else {
      await createSeoScript(session.token, values);
    }
    setModalOpen(false);
    setEditing(null);
    await refresh(session.token);
  }

  async function handleDelete(script: SeoScript) {
    if (!session) return;
    setDeletingId(script.id);
    try {
      await deleteSeoScript(session.token, script.id);
      await refresh(session.token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not delete script.");
    } finally {
      setDeletingId(null);
    }
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Scripts</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Head/end-of-body scripts — FB/TikTok pixels, custom tags.</p>
        </div>
        <Button size="sm" startIcon={<PlusIcon />} onClick={() => { setEditing(null); setModalOpen(true); }}>
          Add Script
        </Button>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <Table>
            <TableHeader className="border-b border-gray-100 dark:border-gray-800">
              <TableRow>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Name</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Location</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Priority</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</TableCell>
              </TableRow>
            </TableHeader>
            <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
              {scripts?.map((s) => (
                <TableRow key={s.id}>
                  <TableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">{s.name}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{s.location === "head" ? "Head" : "End of body"}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{s.priority}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">
                    <Badge size="sm" color={s.is_active ? "success" : "light"}>{s.is_active ? "Active" : "Inactive"}</Badge>
                  </TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">
                    <div className="flex gap-3">
                      <button type="button" className="text-brand-500 hover:underline" onClick={() => { setEditing(s); setModalOpen(true); }}>Edit</button>
                      <button type="button" className="text-error-500 hover:underline" disabled={deletingId === s.id} onClick={() => handleDelete(s)}>Delete</button>
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>

          {scripts?.length === 0 && <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No scripts yet.</p>}
          {scripts === null && !error && <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>}
        </div>
      </div>

      <SaveSeoScriptModal
        isOpen={modalOpen}
        script={editing}
        onClose={() => { setModalOpen(false); setEditing(null); }}
        onSubmit={handleSubmit}
      />
    </div>
  );
}
