"use client";

/**
 * ADR-133 decision 6 (PR 3c): reseller applications, the admin side of the public KYC form on
 * `onboarding.pekangame.space`. Laid out like /admin/orders (title row with Updated and Refresh,
 * underline status tabs, DataTable, View), with the detail at `?application=<id>`.
 */

import { Suspense, useCallback, useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
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
import { Switch } from "@/components/ui/switch";
import { Tag } from "@/components/ui/tag";
import { notify } from "@/components/ui/toast";
import { Plus as PlusIcon } from "@primeicons/react/plus";
import { StatusText } from "@/components/orders/StatusText";
import InviteApplicantModal from "@/components/partner-applications/InviteApplicantModal";
import ApplicationDetailView from "@/components/partner-applications/ApplicationDetailView";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import { listResellerTiers, type ResellerTier } from "@/lib/resellers";
import {
  getApplication,
  getApplicationSettings,
  listApplications,
  SALES_RANGE_LABELS,
  STATUS_LABELS,
  STATUS_SEVERITY,
  updateApplicationSettings,
  type ApplicationDetail,
  type ApplicationPage,
  type ApplicationStatus,
} from "@/lib/partner-applications";

const TABS: { value: ApplicationStatus | "all"; label: string }[] = [
  { value: "documents_submitted", label: "Documents submitted" },
  { value: "new", label: "New" },
  { value: "invited", label: "Waiting for documents" },
  { value: "email_pending", label: "Email not confirmed" },
  { value: "approved", label: "Approved" },
  { value: "declined", label: "Declined" },
  { value: "all", label: "All" },
];

const TH = "px-5 py-3 text-start text-theme-xs font-medium text-ink-muted";

function ApplicationsPageInner() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const selectedId = Number(searchParams.get("application")) || null;

  const [status, setStatus] = useState<ApplicationStatus | "all">("documents_submitted");
  const [pageNumber, setPageNumber] = useState(1);
  const [page, setPage] = useState<ApplicationPage | null>(null);
  const [selected, setSelected] = useState<ApplicationDetail | null>(null);
  const [tiers, setTiers] = useState<ResellerTier[]>([]);
  const [open, setOpen] = useState<boolean | null>(null);
  const [inviteOpen, setInviteOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [updatedAt, setUpdatedAt] = useState<Date | null>(null);

  const load = useCallback(() => {
    return listApplications(status, pageNumber)
      .then((p) => {
        setPage(p);
        setUpdatedAt(new Date());
        setError(null);
      })
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load applications."));
  }, [status, pageNumber]);

  useEffect(() => {
    if (!getClientSession()) {
      router.replace("/login");
      return;
    }
    load();
  }, [load, router]);

  useEffect(() => {
    getApplicationSettings().then((s) => setOpen(s.partner_applications_open), () => setOpen(null));
    listResellerTiers().then((t) => setTiers(t.tiers), () => setTiers([]));
  }, []);

  useEffect(() => {
    if (selectedId === null) return;
    getApplication(selectedId)
      .then(setSelected)
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load this application."));
  }, [selectedId]);

  const show = (id: number) => router.push(`/admin/applications?application=${id}`);
  const back = () => {
    setSelected(null);
    router.replace("/admin/applications");
    load();
  };

  async function toggleOpen(next: boolean) {
    try {
      setOpen((await updateApplicationSettings(next)).partner_applications_open);
      notify.success(next ? "Public applications are open" : "Public applications are closed");
    } catch (err) {
      notify.error(err instanceof ApiError ? err.message : "Could not change the switch.");
    }
  }

  if (selectedId !== null && selected?.id === selectedId) {
    return (
      <ApplicationDetailView
        application={selected}
        tiers={tiers}
        onBack={back}
        onChanged={setSelected}
        onDeleted={back}
      />
    );
  }

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-page-title font-semibold text-ink">Reseller applications</h1>
          <p className="mt-1 text-sm text-ink-muted">
            Applications from the public form at onboarding.pekangame.space and the people you invited directly. Documents are
            asked for only after you send the stage-2 link.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          {updatedAt && (
            <span className="flex items-center gap-1.5 text-theme-xs text-ink-muted">
              <span className="size-1.5 rounded-full bg-success-500" />
              Updated {updatedAt.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}
            </span>
          )}
          <Button size="small" variant="outlined" onClick={load}>
            Refresh
          </Button>
          <Button size="small" onClick={() => setInviteOpen(true)}>
            <PlusIcon />
            Invite directly
          </Button>
        </div>
      </div>

      {open !== null && (
        <div className="mb-4 flex flex-wrap items-center gap-3 rounded-2xl border border-gray-200 bg-surface px-5 py-4 dark:border-gray-800">
          <Switch checked={open} onChange={toggleOpen} ariaLabel="Public applications open" />
          <div className="text-sm">
            <p className="font-medium text-ink">Public applications {open ? "open" : "closed"}</p>
            <p className="text-theme-xs text-ink-muted">
              {open
                ? "Anyone can send stage 1 of the form."
                : "The public form says applications are closed. Links you already sent, and Invite directly, still work."}
            </p>
          </div>
        </div>
      )}

      {error && <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>}

      <div className="mb-4 flex flex-wrap gap-1 border-b border-gray-200 dark:border-gray-800">
        {TABS.map((tab) => {
          const count = tab.value === "all" ? null : page?.counts[tab.value];
          return (
            <button
              key={tab.value}
              onClick={() => {
                setStatus(tab.value);
                setPageNumber(1);
              }}
              className={`border-b-2 px-3 py-1.5 text-sm font-medium ${status === tab.value ? "border-cyan-600 text-cyan-ink" : "border-transparent text-ink-muted hover:text-ink"}`}
            >
              {tab.label}
              {count ? <span className="ml-1.5 text-theme-xs text-ink-subtle">{count}</span> : null}
            </button>
          );
        })}
      </div>

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-surface dark:border-gray-800">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={page?.data ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className={TH}>Applicant</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Contact</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Monthly sales (self-declared)</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Documents</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Status</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Received</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item }) => {
                    const a = item as unknown as ApplicationDetail;
                    return (
                      <DataTableRow key={a.id}>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-ink">
                          {a.business_name || a.owner_name}
                          <p className="text-theme-xs font-normal text-ink-muted">
                            {a.business_name ? `${a.owner_name} · ` : ""}
                            {a.applicant_type === "company" ? "Company" : "Individual"}
                          </p>
                          {a.source === "admin" && <Tag severity="secondary" className="mt-1">Invited directly</Tag>}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-ink-muted">
                          {a.email}
                          <p className="text-theme-xs text-ink-subtle">{a.phone}</p>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-ink-muted">
                          {a.monthly_sales_range ? SALES_RANGE_LABELS[a.monthly_sales_range] : "—"}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-ink-muted">{a.documents_count || "—"}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <StatusText severity={STATUS_SEVERITY[a.status]}>{STATUS_LABELS[a.status]}</StatusText>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-ink-muted">
                          {a.created_at ? new Date(a.created_at).toLocaleString() : "—"}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <Button size="small" variant={a.status === "documents_submitted" ? undefined : "outlined"} onClick={() => show(a.id)}>
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
          {page?.data.length === 0 && <p className="p-6 text-center text-sm text-ink-muted">No applications here.</p>}
          {page === null && !error && <p className="p-6 text-center text-sm text-ink-muted">Loading…</p>}
        </div>
      </div>

      {page && page.meta.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-sm text-ink-muted">
          <span>
            Page {page.meta.current_page} of {page.meta.last_page} ({page.meta.total} total)
          </span>
          <div className="flex gap-2">
            <Button size="small" variant="outlined" disabled={page.meta.current_page <= 1} onClick={() => setPageNumber((p) => p - 1)}>
              Previous
            </Button>
            <Button size="small" variant="outlined" disabled={page.meta.current_page >= page.meta.last_page} onClick={() => setPageNumber((p) => p + 1)}>
              Next
            </Button>
          </div>
        </div>
      )}

      <InviteApplicantModal
        isOpen={inviteOpen}
        onClose={() => setInviteOpen(false)}
        onInvited={(application) => {
          setInviteOpen(false);
          notify.success(`Invite sent to ${application.email}`);
          show(application.id);
        }}
      />
    </div>
  );
}

export default function ApplicationsPage() {
  return (
    <Suspense fallback={<p className="text-sm text-ink-muted">Loading…</p>}>
      <ApplicationsPageInner />
    </Suspense>
  );
}
