import { apiFetch, apiUpload } from "@/lib/api-client";

/**
 * ADR-133 PR 3b: the public reseller application on `onboarding.pekangame.space`. No login; stage 2
 * is reached only through the emailed token. Mirrors `Onboarding\PartnerApplicationController`.
 * (The portal's onboarding checklist is `lib/onboarding.ts`, a different thing.)
 */

export type ApplicantType = "company" | "individual";
export type DocumentKind = "ssm" | "ic_front" | "ic_back" | "bank_statement";

export interface Notice {
  version: string;
  en: string;
  ms: string;
}

export interface ApplicationStatus {
  open: boolean;
  notice: Notice;
}

export interface StageOneAnswers {
  applicant_type: ApplicantType;
  owner_name: string;
  business_name: string | null;
  email: string;
  phone: string;
  experience: string | null;
  monthly_sales_range: string | null;
  main_games: string | null;
  order_channel: string | null;
  heard_from: string | null;
  consent: boolean;
  turnstile_token: string;
}

export interface StageTwo {
  applicant_type: ApplicantType;
  owner_name: string;
  business_name: string | null;
  email: string;
  owner_address: string | null;
  business_address: string | null;
  tax_number: string | null;
  expires_at: string | null;
  required_documents: DocumentKind[];
  document_limits: Record<DocumentKind, number>;
  documents: { id: number; kind: DocumentKind; original_name: string; size_bytes: number }[];
  notice: Notice;
}

export const SALES_RANGES = [
  { value: "under_1k", label: "Under RM1,000 a month" },
  { value: "1k_5k", label: "RM1,000 to RM5,000 a month" },
  { value: "5k_20k", label: "RM5,000 to RM20,000 a month" },
  { value: "over_20k", label: "Over RM20,000 a month" },
];

export const ORDER_CHANNELS = [
  { value: "api", label: "Through the API (my own app or website)" },
  { value: "whatsapp", label: "Through the WhatsApp bot" },
  { value: "not_sure", label: "Not sure yet" },
];

export const DOCUMENT_LABELS: Record<DocumentKind, { title: string; hint: string }> = {
  ssm: { title: "SSM certificate", hint: "Your company's registration certificate." },
  ic_front: { title: "Identity card (front)", hint: "A clear photo or scan of the front of your MyKad." },
  ic_back: { title: "Identity card (back)", hint: "A clear photo or scan of the back of your MyKad." },
  bank_statement: { title: "Bank statement", hint: "One or two recent months. You may black out the transaction lines." },
};

export const MAX_FILE_BYTES = 10 * 1024 * 1024;

const base = "/api/onboarding";

export const getApplicationStatus = () => apiFetch<ApplicationStatus>(`${base}/status`);

export const submitStageOne = (answers: StageOneAnswers) =>
  apiFetch<{ message: string }>(`${base}/applications`, { method: "POST", body: answers });

export const verifyApplicationEmail = (token: string) =>
  apiFetch<{ message: string }>(`${base}/verify-email`, { method: "POST", body: { token } });

export const getStageTwo = (token: string) => apiFetch<StageTwo>(`${base}/stage-two/${encodeURIComponent(token)}`);

export const saveStageTwo = (token: string, answers: Pick<StageTwo, "owner_address" | "business_address" | "tax_number">) =>
  apiFetch<StageTwo>(`${base}/stage-two/${encodeURIComponent(token)}`, { method: "PUT", body: answers });

export function uploadDocument(token: string, kind: DocumentKind, file: File): Promise<StageTwo> {
  const form = new FormData();
  form.append("kind", kind);
  form.append("file", file);
  return apiUpload<StageTwo>(`${base}/stage-two/${encodeURIComponent(token)}/documents`, form);
}

export const deleteDocument = (token: string, id: number) =>
  apiFetch<StageTwo>(`${base}/stage-two/${encodeURIComponent(token)}/documents/${id}`, { method: "DELETE" });

export const submitStageTwo = (token: string) =>
  apiFetch<{ message: string }>(`${base}/stage-two/${encodeURIComponent(token)}/submit`, { method: "POST", body: { consent: true } });

/** "1.2 MB" for a byte count. */
export function formatBytes(bytes: number): string {
  return bytes >= 1024 * 1024 ? `${(bytes / (1024 * 1024)).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

