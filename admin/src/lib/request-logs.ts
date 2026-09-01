import { apiFetch } from "@/lib/api-client";

/**
 * ADR-051 (MID-10/11, MUI-9) — mirrors backend/app/Models/SupplierRequestLog.php.
 * request_payload/response_payload are already redacted server-side
 * (SupplierRequestPayloadRedactor, before the row is ever written) —
 * this screen never masks anything itself, it just renders what's stored.
 */
export type SupplierRequestCallType = "checkBalance" | "listProducts" | "createOrder" | "checkStatus" | "validatePlayer";

export type SupplierRequestOutcome = "success" | "failure" | "exception" | "skipped_breaker_open";

export interface SupplierRequestLog {
  id: number;
  supplier_id: number | null;
  supplier: { id: number; name: string; slug: string } | null;
  call_type: SupplierRequestCallType;
  order_id: number | null;
  method: string | null;
  url: string | null;
  status_code: number | null;
  outcome: SupplierRequestOutcome;
  duration_ms: number | null;
  request_payload: Record<string, unknown> | null;
  response_payload: Record<string, unknown> | null;
  error_message: string | null;
  created_at: string;
}

export interface SupplierRequestLogPage {
  data: SupplierRequestLog[];
  current_page: number;
  last_page: number;
  total: number;
}

export interface RequestLogFilters {
  supplier_id?: number;
  call_type?: SupplierRequestCallType;
  outcome?: SupplierRequestOutcome;
  from?: string;
  to?: string;
  page?: number;
  per_page?: number;
}

export function listRequestLogs(token: string, filters: RequestLogFilters = {}) {
  const params = new URLSearchParams();

  for (const [key, value] of Object.entries(filters)) {
    if (value !== undefined && value !== "") params.set(key, String(value));
  }

  const query = params.toString();

  return apiFetch<SupplierRequestLogPage>(`/api/middleware/request-logs${query ? `?${query}` : ""}`, { token });
}

export function getRequestLog(token: string, id: number) {
  return apiFetch<SupplierRequestLog>(`/api/middleware/request-logs/${id}`, { token });
}
