"use client";

/**
 * ADR-027 Phase 6.5 decision 9 — the member registry on /admin/membership:
 * a flat, filterable, paginated table with a Record Payment action and
 * (ADR-068 PR-3) a "View" link to the per-member detail. Split out of
 * page.tsx.
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
import { SimpleSelect } from "@/components/ui/select";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { PlusIcon } from "@/icons";
import { ApiError } from "@/lib/api-client";
import {
  listMemberships,
  listMembershipBrands,
  recordMembershipPayment,
  formatMemberRm,
  formatMemberDate,
  type MembershipPlan,
  type MembershipListItem,
  type MembershipBrand,
  type MembershipStatusFilter,
} from "@/lib/membership";
import RecordPaymentModal from "@/components/membership/RecordPaymentModal";

const statusSeverity: Record<MembershipListItem["status"], "success" | "secondary"> = {
  active: "success",
  expired: "secondary",
};

export default function MembersSection({
  token,
  plans,
  onChanged,
}: {
  token: string;
  plans: MembershipPlan[];
  onChanged: () => void;
}) {
  const router = useRouter();
  const [data, setData] = useState<MembershipListItem[] | null>(null);
  const [statusFilter, setStatusFilter] = useState<MembershipStatusFilter>("all");
  const [brands, setBrands] = useState<MembershipBrand[]>([]);
  const [brandFilter, setBrandFilter] = useState<string>("all");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [error, setError] = useState<string | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [presetEmail, setPresetEmail] = useState<string | undefined>(undefined);
  const [presetAffiliateId, setPresetAffiliateId] = useState<number | undefined>(undefined);

  function refresh(nextPage = 1) {
    listMemberships(token, {
      status: statusFilter,
      search: search || undefined,
      page: nextPage,
      affiliateId: brandFilter === "all" ? undefined : Number(brandFilter),
    })
      .then((res) => {
        setData(res.data);
        setLastPage(res.last_page);
        setTotal(res.total);
        setPage(res.current_page);
      })
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load members.");
      });
  }

  useEffect(() => {
    listMembershipBrands(token)
      .then(setBrands)
      .catch(() => setBrands([]));
  }, [token]);

  useEffect(() => {
    refresh(1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusFilter, brandFilter]);

  async function handleRecorded(values: Parameters<typeof recordMembershipPayment>[1]) {
    await recordMembershipPayment(token, values);
    setIsModalOpen(false);
    setPresetEmail(undefined);
    setPresetAffiliateId(undefined);
    await refresh(page);
    onChanged();
  }

  return (
    <div className="mt-8">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-base font-semibold text-gray-800 dark:text-white/90">Members</h2>
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">
            {total} member{total === 1 ? "" : "s"} — tier, status, and quota usage.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search email…" className="w-56" />
          <Button size="small" variant="outlined" onClick={() => refresh(1)}>
            Search
          </Button>
          <SimpleSelect
            options={[
              { value: "all", label: "All" },
              { value: "active", label: "Active" },
              { value: "expired", label: "Expired" },
            ]}
            value={statusFilter}
            onChange={(v) => setStatusFilter(v as MembershipStatusFilter)}
            className="w-36"
          />
          {brands.length > 1 && (
            <SimpleSelect
              options={[
                { value: "all", label: "All brands" },
                ...brands.map((b) => ({ value: String(b.id), label: b.business_name })),
              ]}
              value={brandFilter}
              onChange={setBrandFilter}
              className="w-44"
            />
          )}
          <Button
            size="small"
            onClick={() => {
              setPresetEmail(undefined);
              setPresetAffiliateId(undefined);
              setIsModalOpen(true);
            }}
          >
            <PlusIcon />
            Record Payment
          </Button>
        </div>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={data ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Email</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Brand</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Tier</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Expires</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Quota Used</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Orders</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item }) => {
                    const m = item as unknown as MembershipListItem;

                    return (
                      <DataTableRow key={m.id}>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                          {m.email}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {m.brand_name ?? `brand #${m.affiliate_id}`}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {m.plan_name ?? `plan #${m.plan_id}`}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <Tag severity={statusSeverity[m.status]}>{m.status}</Tag>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {formatMemberDate(m.expires_at)}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {formatMemberRm(m.quota_used_sen)} / {formatMemberRm(m.quota_total_sen)}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {m.orders_count}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <div className="flex items-center gap-2">
                            <Button
                              size="small"
                              variant="outlined"
                              onClick={() => router.push(`/admin/membership/${m.id}`)}
                            >
                              View
                            </Button>
                            <Button
                              size="small"
                              variant="outlined"
                              onClick={() => {
                                setPresetEmail(m.email);
                                setPresetAffiliateId(m.affiliate_id);
                                setIsModalOpen(true);
                              }}
                            >
                              Record Payment
                            </Button>
                          </div>
                        </DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>

          {data !== null && data.length === 0 && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No members yet.</p>
          )}
        </div>

        {lastPage > 1 && (
          <div className="flex items-center justify-between border-t border-gray-100 px-5 py-3 dark:border-gray-800">
            <span className="text-theme-xs text-gray-500 dark:text-gray-400">
              Page {page} of {lastPage} ({total} total)
            </span>
            <div className="flex items-center gap-2">
              <Button size="small" variant="outlined" disabled={page <= 1} onClick={() => refresh(page - 1)}>
                Previous
              </Button>
              <Button size="small" variant="outlined" disabled={page >= lastPage} onClick={() => refresh(page + 1)}>
                Next
              </Button>
            </div>
          </div>
        )}
      </div>

      <RecordPaymentModal
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        plans={plans}
        brands={brands}
        presetAffiliateId={presetAffiliateId}
        presetEmail={presetEmail}
        onSubmit={handleRecorded}
      />
    </div>
  );
}
