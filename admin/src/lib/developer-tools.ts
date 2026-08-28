import { apiFetch } from "@/lib/api-client";

/**
 * ADR-054 (DEV-1/2, MUI-11) — mirrors
 * backend/app/Http/Controllers/Middleware/DeveloperToolController.php.
 * `payload` fields populate a typed adapter DTO server-side (decision
 * 2) — this client never builds or sends a raw supplier HTTP body.
 */
export type DeveloperToolMethod = "checkBalance" | "listProducts" | "checkStatus" | "validatePlayer" | "createOrder";

export interface DeveloperToolPayload {
  supplier_ref?: string;
  product_ref?: string;
  player_id?: string;
  server_id?: string;
  customer_phone?: string;
  callback_url?: string;
}

export interface TestSupplierAdapterValues {
  supplier_id: number;
  method: DeveloperToolMethod;
  dry_run: boolean;
  payload?: DeveloperToolPayload;
}

export interface DeveloperToolResult {
  dry_run: boolean;
  method: DeveloperToolMethod;
  /** Present only when dry_run is true — the exact DTO a real call would send. */
  request?: Record<string, unknown> | null;
  /** Present only when dry_run is false. */
  success?: boolean;
  outcome?: "success" | "pending" | "failure";
  data?: unknown;
  error_code?: string | null;
  error_message?: string | null;
}

export function testSupplierAdapter(token: string, values: TestSupplierAdapterValues) {
  return apiFetch<DeveloperToolResult>("/api/middleware/developer-tools/test", {
    method: "POST",
    body: values,
    token,
  });
}
