import { apiFetch } from "@/lib/api-client";

/**
 * ADR-024 decision #1's "Apply" button — read-only preview, never
 * locks or spends a voucher's remaining balance (that only ever
 * happens later, inside the real POST /api/checkout submission — see
 * lib/checkout.ts's CheckoutPayload.voucher_code). Money is still
 * server-computed here (ORD-9): gameId/packageId, not a price, is
 * what's sent — VoucherPreviewController recomputes the real selling
 * price from the stored Package itself.
 */

export interface VoucherPreviewResult {
  selling_price: number;
  discount: number;
  remaining_after: number;
}

export function previewVoucher(
  gameId: number,
  packageId: number,
  voucherCode: string,
  customerEmail: string,
  customerPhone?: string,
) {
  return apiFetch<VoucherPreviewResult>("/api/vouchers/preview", {
    method: "POST",
    body: {
      game_id: gameId,
      package_id: packageId,
      voucher_code: voucherCode,
      customer_email: customerEmail,
      customer_phone: customerPhone || undefined,
    },
  });
}
