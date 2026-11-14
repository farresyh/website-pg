import { apiFetch } from "@/lib/api-client";

/**
 * ADR-116 2026-11-14 addendum, decision 12 — mirrors
 * backend/app/Http/Controllers/Middleware/WhatsAppOutboxController.php.
 * The message text is never sent here; the recipient is already masked.
 */
export type WhatsAppOutboxStatus = "queued" | "sent" | "failed" | "skipped" | "unknown" | "expired" | "superseded";

export type WhatsAppOutboxSession = "customer_support" | "bot";

export interface WhatsAppOutboxRow {
  id: number;
  session: WhatsAppOutboxSession;
  event: string;
  status: WhatsAppOutboxStatus;
  recipient: string;
  order_number: string | null;
  attempts: number;
  error: string | null;
  created_at: string;
  due_at: string | null;
  sent_at: string | null;
}

export interface WhatsAppOutboxPage {
  data: WhatsAppOutboxRow[];
  current_page: number;
  last_page: number;
  total: number;
}

export function listWhatsAppOutbox(filters: { status?: WhatsAppOutboxStatus; session?: WhatsAppOutboxSession; page?: number }) {
  const params = new URLSearchParams();

  for (const [key, value] of Object.entries(filters)) {
    if (value !== undefined) params.set(key, String(value));
  }

  const query = params.toString();

  return apiFetch<WhatsAppOutboxPage>(`/api/middleware/whatsapp-outbox${query ? `?${query}` : ""}`);
}
