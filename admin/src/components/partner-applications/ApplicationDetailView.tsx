"use client";

import { useState } from "react";
import Link from "next/link";
import {
  Dialog,
  DialogPortal,
  DialogBackdrop,
  DialogPositioner,
  DialogPopup,
  DialogHeader,
  DialogTitle,
  DialogContent,
  DialogFooter,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { SimpleSelect } from "@/components/ui/select";
import { Tag } from "@/components/ui/tag";
import { notify } from "@/components/ui/toast";
import { StatusText } from "@/components/orders/StatusText";
import { User } from "@primeicons/react/user";
import { Building } from "@primeicons/react/building";
import { File } from "@primeicons/react/file";
import { InfoCircle } from "@primeicons/react/info-circle";
import { ApiError } from "@/lib/api-client";
import type { ResellerTier } from "@/lib/resellers";
import {
  approveApplication,
  declineApplication,
  deleteApplicationData,
  DOCUMENT_LABELS,
  documentLink,
  ORDER_CHANNEL_LABELS,
  SALES_RANGE_LABELS,
  sendStageTwoLink,
  STATUS_LABELS,
  STATUS_SEVERITY,
  type ApplicationDetail,
} from "@/lib/partner-applications";

/**
 * ADR-133 decision 6: one application, read like a form (the card layout of `OrderDetailCards`),
 * with the actions its status allows. Every action is checked again by `PartnerApplicationService`.
 */
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString() : "—");
const size = (bytes: number) => (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`);

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-wrap justify-between gap-x-4 gap-y-0.5">
      <dt className="text-ink-muted">{label}</dt>
      <dd className="min-w-0 text-right text-ink break-words">{children ?? "—"}</dd>
    </div>
  );
}

function Card({ icon, title, children }: { icon: React.ReactNode; title: string; children: React.ReactNode }) {
  return (
    <div className="rounded-2xl border border-gray-200 bg-surface p-6 dark:border-gray-800">
      <h2 className="mb-4 flex items-center gap-2 text-section-title font-semibold text-ink">
        {icon}
        {title}
      </h2>
      {children}
    </div>
  );
}

type Confirm = "approve" | "decline" | "delete" | null;

export default function ApplicationDetailView({
  application,
  tiers,
  onBack,
  onChanged,
  onDeleted,
}: {
  application: ApplicationDetail;
  tiers: ResellerTier[];
  onBack: () => void;
  onChanged: (application: ApplicationDetail) => void;
  onDeleted: () => void;
}) {
  const a = application;
  const [confirm, setConfirm] = useState<Confirm>(null);
  const [tierId, setTierId] = useState("");
  const [busy, setBusy] = useState(false);

  async function run(action: () => Promise<ApplicationDetail | null>, success: string) {
    setBusy(true);
    try {
      const updated = await action();
      notify.success(success);
      setConfirm(null);
      if (updated) onChanged(updated);
      else onDeleted();
    } catch (err) {
      notify.error(err instanceof ApiError ? err.message : "Something went wrong.");
    } finally {
      setBusy(false);
    }
  }

  async function openDocument(documentId: number) {
    // Opened before the await, so the browser does not treat it as an unrequested popup.
    const tab = window.open("about:blank", "_blank");
    try {
      const { url } = await documentLink(a.id, documentId);
      if (tab) tab.location.href = url;
      else window.location.assign(url);
    } catch (err) {
      tab?.close();
      notify.error(err instanceof ApiError ? err.message : "Could not open the file.");
    }
  }

  const isCompany = a.applicant_type === "company";
  const open = !["approved", "declined"].includes(a.status);

  return (
    <div>
      <button onClick={onBack} className="mb-4 text-sm text-ink-muted hover:text-ink">
        ← Back to applications
      </button>

      <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-page-title font-semibold text-ink">{a.business_name || a.owner_name}</h1>
          <p className="mt-1 flex flex-wrap items-center gap-3 text-sm text-ink-muted">
            <StatusText severity={STATUS_SEVERITY[a.status]}>{STATUS_LABELS[a.status]}</StatusText>
            <span>{isCompany ? "Company" : "Individual"}</span>
            {a.source === "admin" && <Tag severity="secondary">Invited directly</Tag>}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {a.status === "documents_submitted" && (
            <Button size="small" onClick={() => setConfirm("approve")}>
              Approve…
            </Button>
          )}
          {(a.status === "new" || a.status === "invited") && (
            <Button size="small" variant="outlined" disabled={busy} onClick={() => run(() => sendStageTwoLink(a.id), a.status === "new" ? "Stage-2 link sent" : "New link sent; the old one no longer works")}>
              {a.status === "new" ? "Send stage-2 link" : "Send a new link"}
            </Button>
          )}
          {open && (
            <Button size="small" variant="outlined" severity="danger" onClick={() => setConfirm("decline")}>
              Decline…
            </Button>
          )}
          <Button size="small" variant="outlined" severity="danger" onClick={() => setConfirm("delete")}>
            Delete data…
          </Button>
        </div>
      </div>

      {a.reseller && (
        <p className="mb-6 rounded-lg bg-success-surface px-4 py-3 text-sm text-success-ink">
          Approved as reseller{" "}
          <Link href="/admin/resellers" className="font-medium underline underline-offset-2">
            {a.reseller.business_name}
          </Link>
          {a.reseller.closed ? " (account closed; data is deleted 90 days after closure)." : "."}
        </p>
      )}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <Card icon={<User className="h-4 w-4 text-ink-muted" />} title="Applicant">
          <dl className="space-y-2 text-sm">
            <Row label="Owner">{a.owner_name}</Row>
            {isCompany && <Row label="Company">{a.business_name}</Row>}
            {!isCompany && a.business_name && <Row label="Business name">{a.business_name}</Row>}
            <Row label="Email">
              {a.email}
              <span className="block text-theme-xs text-ink-subtle">{a.email_verified_at ? `Confirmed ${when(a.email_verified_at)}` : "Not confirmed"}</span>
            </Row>
            <Row label="Phone">{a.phone}</Row>
            <Row label="Received">{when(a.created_at)}</Row>
          </dl>
        </Card>

        <Card icon={<Building className="h-4 w-4 text-ink-muted" />} title="Reselling (self-declared, not verified)">
          <dl className="space-y-2 text-sm">
            <Row label="Monthly sales">{a.monthly_sales_range ? SALES_RANGE_LABELS[a.monthly_sales_range] : null}</Row>
            <Row label="Plans to order by">{a.order_channel ? ORDER_CHANNEL_LABELS[a.order_channel] : null}</Row>
            <Row label="Main games">{a.main_games}</Row>
            <Row label="Heard from">{a.heard_from}</Row>
          </dl>
          {a.experience && <p className="mt-4 whitespace-pre-line rounded-lg bg-subtle px-3 py-2 text-sm text-ink">{a.experience}</p>}
        </Card>

        <Card icon={<File className="h-4 w-4 text-ink-muted" />} title="Stage 2: details and documents">
          <dl className="mb-4 space-y-2 text-sm">
            <Row label="Owner address">{a.owner_address}</Row>
            {isCompany && <Row label="Business address">{a.business_address}</Row>}
            <Row label="Tax number">{a.tax_number}</Row>
            <Row label="Submitted">{when(a.submitted_at)}</Row>
            {a.status === "invited" && <Row label="Link valid until">{when(a.link_expires_at)}</Row>}
          </dl>
          {a.documents.length === 0 ? (
            <p className="text-sm text-ink-muted">No documents yet.</p>
          ) : (
            <ul className="divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-gray-800 dark:border-gray-800">
              {a.documents.map((d) => (
                <li key={d.id} className="flex flex-wrap items-center justify-between gap-3 px-3 py-2.5">
                  <div className="min-w-0 text-sm">
                    <p className="font-medium text-ink">{DOCUMENT_LABELS[d.kind]}</p>
                    <p className="truncate text-theme-xs text-ink-muted">
                      {d.original_name} · {size(d.size_bytes)}
                    </p>
                    <p className="text-theme-xs text-ink-subtle">
                      {d.views.length === 0 ? "Not opened yet" : `Opened ${d.views.length}× · last by ${d.views[0].admin ?? "a removed admin"}, ${when(d.views[0].viewed_at)}`}
                    </p>
                  </div>
                  <Button size="small" variant="outlined" onClick={() => openDocument(d.id)}>
                    Open
                  </Button>
                </li>
              ))}
            </ul>
          )}
          <p className="mt-3 text-theme-xs text-ink-subtle">Each file opens through a link valid for 5 minutes, and every opening is recorded.</p>
        </Card>

        <Card icon={<InfoCircle className="h-4 w-4 text-ink-muted" />} title="Consent record">
          <dl className="space-y-2 text-sm">
            <Row label="Stage 1 notice">{a.stage1_notice_version ? `${a.stage1_notice_version}, agreed ${when(a.stage1_consented_at)}` : "Invited directly (no stage 1)"}</Row>
            <Row label="Stage 2 notice">{a.stage2_notice_version ? `${a.stage2_notice_version}, agreed ${when(a.stage2_consented_at)}` : null}</Row>
            <Row label="Decided">{when(a.decided_at)}</Row>
          </dl>
        </Card>
      </div>

      <Dialog open={confirm !== null} onOpenChange={(e) => !e.value && setConfirm(null)}>
        <DialogPortal>
          <DialogBackdrop />
          <DialogPositioner>
            <DialogPopup>
              <DialogHeader>
                <DialogTitle>
                  {confirm === "approve" && `Approve ${a.business_name || a.owner_name}?`}
                  {confirm === "decline" && `Decline ${a.business_name || a.owner_name}?`}
                  {confirm === "delete" && "Delete this applicant's data?"}
                </DialogTitle>
              </DialogHeader>
              <DialogContent>
                {confirm === "approve" && (
                  <div>
                    <Label htmlFor="approve_tier">Wallet tier</Label>
                    <SimpleSelect
                      options={[{ value: "", label: "Choose a tier" }, ...tiers.filter((t) => t.is_active).map((t) => ({ value: String(t.id), label: `${t.name} (+${t.markup_percent}% over cost)` }))]}
                      value={tierId}
                      onChange={setTierId}
                    />
                    <p className="mt-2 text-theme-xs text-ink-muted">
                      Creates the reseller account and its portal login ({a.email}) and emails a set-password link.
                    </p>
                  </div>
                )}
                {confirm === "decline" && (
                  <p className="text-sm text-ink-muted">
                    {a.email_verified_at
                      ? "We send one short email with no reasons. The application is deleted 90 days from now."
                      : "The email was never confirmed, so nothing is sent. The application is deleted 90 days from now."}
                  </p>
                )}
                {confirm === "delete" && (
                  <p className="text-sm text-ink-muted">
                    Deletes the application and every uploaded file now, for a data-subject request. This cannot be undone.
                    {a.reseller ? " The reseller account stays; it will show “KYC outside the system”." : ""}
                  </p>
                )}
              </DialogContent>
              <DialogFooter>
                <Button variant="outlined" onClick={() => setConfirm(null)}>
                  Cancel
                </Button>
                {confirm === "approve" && (
                  <Button disabled={busy || tierId === ""} onClick={() => run(() => approveApplication(a.id, Number(tierId)), "Approved; set-password email sent")}>
                    Approve
                  </Button>
                )}
                {confirm === "decline" && (
                  <Button severity="danger" disabled={busy} onClick={() => run(() => declineApplication(a.id), "Application declined")}>
                    Decline
                  </Button>
                )}
                {confirm === "delete" && (
                  <Button severity="danger" disabled={busy} onClick={() => run(async () => { await deleteApplicationData(a.id); return null; }, "Applicant data deleted")}>
                    Delete
                  </Button>
                )}
              </DialogFooter>
            </DialogPopup>
          </DialogPositioner>
        </DialogPortal>
      </Dialog>
    </div>
  );
}
