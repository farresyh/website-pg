import { apiFetch } from "@/lib/api-client";

/**
 * ADR-133 decision 6: the admin side of the reseller application with KYC. Mirrors
 * `Admin\PartnerApplicationController`. Every status change happens on the backend
 * (`PartnerApplicationService`); this only forwards the admin's choice.
 */

export type ApplicationStatus = "email_pending" | "new" | "invited" | "documents_submitted" | "approved" | "declined";
export type ApplicantType = "company" | "individual";

export interface ApplicationRow {
  id: number;
  status: ApplicationStatus;
  source: "public" | "admin";
  applicant_type: ApplicantType;
  owner_name: string;
  business_name: string | null;
  email: string;
  phone: string;
  monthly_sales_range: string | null;
  documents_count: number;
  created_at: string | null;
  submitted_at: string | null;
}

export interface ApplicationDocument {
  id: number;
  kind: "ssm" | "ic_front" | "ic_back" | "bank_statement";
  original_name: string;
  mime: string;
  size_bytes: number;
  views: { admin: string | null; viewed_at: string }[];
}

export interface ApplicationDetail extends ApplicationRow {
  experience: string | null;
  main_games: string | null;
  order_channel: string | null;
  heard_from: string | null;
  email_verified_at: string | null;
  stage1_notice_version: string | null;
  stage1_consented_at: string | null;
  link_expires_at: string | null;
  owner_address: string | null;
  business_address: string | null;
  tax_number: string | null;
  stage2_notice_version: string | null;
  stage2_consented_at: string | null;
  decided_at: string | null;
  reseller: { id: number; business_name: string; closed: boolean } | null;
  documents: ApplicationDocument[];
}

export interface ApplicationPage {
  data: ApplicationRow[];
  meta: { current_page: number; last_page: number; total: number };
  counts: Partial<Record<ApplicationStatus, number>>;
}

export interface InviteApplicantValues {
  applicant_type: ApplicantType;
  owner_name: string;
  business_name: string | null;
  email: string;
  phone: string;
  order_channel: string | null;
}

export const STATUS_LABELS: Record<ApplicationStatus, string> = {
  email_pending: "Email not confirmed",
  new: "New",
  invited: "Waiting for documents",
  documents_submitted: "Documents submitted",
  approved: "Approved",
  declined: "Declined",
};

export const STATUS_SEVERITY: Record<ApplicationStatus, "secondary" | "warn" | "success" | "danger" | "info" | "review"> = {
  email_pending: "secondary",
  new: "info",
  invited: "warn",
  documents_submitted: "review",
  approved: "success",
  declined: "danger",
};

export const SALES_RANGE_LABELS: Record<string, string> = {
  under_1k: "Under RM1,000 / month",
  "1k_5k": "RM1,000–5,000 / month",
  "5k_20k": "RM5,000–20,000 / month",
  over_20k: "Over RM20,000 / month",
};

export const ORDER_CHANNEL_LABELS: Record<string, string> = {
  api: "API",
  whatsapp: "WhatsApp bot",
  not_sure: "Not sure",
};

export const DOCUMENT_LABELS: Record<ApplicationDocument["kind"], string> = {
  ssm: "SSM certificate",
  ic_front: "Identity card (front)",
  ic_back: "Identity card (back)",
  bank_statement: "Bank statement",
};

const base = "/api/partner-applications";

export function listApplications(status: ApplicationStatus | "all", page: number) {
  const query = new URLSearchParams({ page: String(page) });
  if (status !== "all") query.set("status", status);
  return apiFetch<ApplicationPage>(`${base}?${query}`);
}

export const getApplication = (id: number) => apiFetch<ApplicationDetail>(`${base}/${id}`);

export const inviteApplicant = (values: InviteApplicantValues) =>
  apiFetch<ApplicationDetail>(`${base}/invite`, { method: "POST", body: values });

/** Send stage-2 link for a `new` application, or a fresh link for an `invited` one (the old link stops working). */
export const sendStageTwoLink = (id: number) => apiFetch<ApplicationDetail>(`${base}/${id}/send-link`, { method: "POST" });

export const declineApplication = (id: number) => apiFetch<ApplicationDetail>(`${base}/${id}/decline`, { method: "POST" });

export const approveApplication = (id: number, resellerTierId: number) =>
  apiFetch<ApplicationDetail>(`${base}/${id}/approve`, { method: "POST", body: { reseller_tier_id: resellerTierId } });

/** Delete applicant data (a data-subject request). Behind MFA step-up; api-client shows the prompt. */
export const deleteApplicationData = (id: number) => apiFetch<null>(`${base}/${id}`, { method: "DELETE" });

/** A link to the file, valid for 5 minutes; opening it is recorded. */
export const documentLink = (id: number, documentId: number) =>
  apiFetch<{ url: string }>(`${base}/${id}/documents/${documentId}/link`, { method: "POST" });

export const getApplicationSettings = () => apiFetch<{ partner_applications_open: boolean }>(`${base}/settings`);

export const updateApplicationSettings = (open: boolean) =>
  apiFetch<{ partner_applications_open: boolean }>(`${base}/settings`, { method: "PUT", body: { partner_applications_open: open } });
