"use client";

/** ADR-120 decision 13: the platform FAQ — homepage + order-status text and the homepage `FAQPage` JSON-LD, for every brand. */

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
import { Plus as PlusIcon } from "@primeicons/react/plus";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { listFaqs, createFaq, updateFaq, deleteFaq, type Faq } from "@/lib/seo";
import SaveFaqModal from "@/components/seo/SaveFaqModal";

export default function FaqPage() {
  const router = useRouter();
  const session = useClientSession();
  const [faqs, setFaqs] = useState<Faq[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<Faq | null>(null);
  const [deletingId, setDeletingId] = useState<number | null>(null);

  function refresh(token: string) {
    return listFaqs(token)
      .then(setFaqs)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load the FAQ.");
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

  async function handleSubmit(values: Parameters<typeof createFaq>[1]) {
    if (!session) return;
    if (editing) {
      await updateFaq(session.token, editing.id, values);
    } else {
      await createFaq(session.token, values);
    }
    setModalOpen(false);
    setEditing(null);
    await refresh(session.token);
  }

  async function handleDelete(faq: Faq) {
    if (!session) return;
    setDeletingId(faq.id);
    try {
      await deleteFaq(session.token, faq.id);
      await refresh(session.token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not delete the question.");
    } finally {
      setDeletingId(null);
    }
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">FAQ</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Shown on the homepage and order status page of every brand, and read by search engines and AI assistants. Use <code>{"{store_name}"}</code> for the store name.
          </p>
        </div>
        <Button size="small" onClick={() => { setEditing(null); setModalOpen(true); }}>
          <PlusIcon />
          Add Question
        </Button>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={faqs ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">#</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Question</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item }) => {
                    const f = item as unknown as Faq;

                    return (
                      <DataTableRow key={f.id}>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{f.sort_order}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-800 dark:text-white/90">
                          <p className="font-medium">{f.question}</p>
                          <p className="mt-1 line-clamp-2 text-gray-500 dark:text-gray-400">{f.answer}</p>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{f.is_active ? "Shown" : "Hidden"}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <div className="flex gap-3">
                            <button type="button" className="text-brand-500 hover:underline" onClick={() => { setEditing(f); setModalOpen(true); }}>Edit</button>
                            <button type="button" className="text-error-500 hover:underline" disabled={deletingId === f.id} onClick={() => handleDelete(f)}>Delete</button>
                          </div>
                        </DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>

          {faqs?.length === 0 && <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No questions yet — the FAQ section is hidden on the storefront.</p>}
          {faqs === null && !error && <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>}
        </div>
      </div>

      <SaveFaqModal
        isOpen={modalOpen}
        faq={editing}
        nextSortOrder={(faqs ?? []).reduce((max, f) => Math.max(max, f.sort_order), 0) + 1}
        onClose={() => { setModalOpen(false); setEditing(null); }}
        onSubmit={handleSubmit}
      />
    </div>
  );
}
