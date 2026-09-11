import { apiFetch, apiUpload, ApiError } from "@/lib/api-client";

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://backend.test";

/**
 * ADR-083 decision 2 (PR-1): the supplier funding ledger — under
 * `/accounting`, not `/middleware` (bookkeeping, not supplier-integration
 * config; see `SupplierTransferController`'s own docblock). `amount`
 * fields are strings — decimal(18,4)/foreign-currency, never MYR sen.
 */
export interface SupplierTransfer {
  id: number;
  supplier_id: number;
  source_channel: "wise" | "airwallex" | "bank";
  amount_myr_sent: number;
  fee_myr: number;
  currency: string;
  amount_foreign_received: string;
  effective_rate: string | null;
  receipt_path: string | null;
  reference_no: string | null;
  created_by: number | null;
  created_at: string;
}

export interface SupplierTransfersPage {
  data: SupplierTransfer[];
  current_page: number;
  last_page: number;
  total: number;
}

export interface SupplierFundingLedger {
  ledger_balance: string;
  currency: string;
  transfers: SupplierTransfersPage;
}

export function getSupplierTransfers(token: string, supplierId: number, page?: number) {
  const qs = page ? `?page=${page}` : "";

  return apiFetch<SupplierFundingLedger>(`/api/accounting/suppliers/${supplierId}/transfers${qs}`, { token });
}

export interface RecordSupplierTransferValues {
  source_channel: "wise" | "airwallex" | "bank";
  amount_myr_sent: number;
  fee_myr?: number;
  currency: string;
  amount_foreign_received: string;
  reference_no?: string | null;
  receipt?: File | null;
}

export function recordSupplierTransfer(token: string, supplierId: number, values: RecordSupplierTransferValues) {
  const formData = new FormData();
  formData.append("source_channel", values.source_channel);
  formData.append("amount_myr_sent", String(values.amount_myr_sent));
  formData.append("fee_myr", String(values.fee_myr ?? 0));
  formData.append("currency", values.currency);
  formData.append("amount_foreign_received", values.amount_foreign_received);
  if (values.reference_no) formData.append("reference_no", values.reference_no);
  if (values.receipt) formData.append("receipt", values.receipt);

  return apiUpload<{ transfer: SupplierTransfer; ledger_balance: string }>(
    `/api/accounting/suppliers/${supplierId}/transfers`,
    formData,
    { token },
  );
}

/**
 * Bearer-token-gated route (`accounting_disk` is deliberately private,
 * ADR-083 decision 10) — not a plain `<a href>`, same Blob +
 * object-URL pattern as `downloadWalletTopupReceipt`.
 */
export async function downloadSupplierTransferReceipt(token: string, transferId: number, filename: string): Promise<void> {
  const response = await fetch(`${API_BASE_URL}/api/accounting/supplier-transfers/${transferId}/receipt`, {
    headers: { Authorization: `Bearer ${token}` },
  });

  if (!response.ok) {
    throw new ApiError(response.status, undefined, `Download failed (${response.status})`);
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
