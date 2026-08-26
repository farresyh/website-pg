import { z } from "zod";
import { apiFetch } from "@/lib/api-client";
import { parseResponse } from "@/lib/schema-validation";

/**
 * ADR-024 decision #1's "Apply" button — read-only preview, never
 * locks or spends a voucher's remaining balance (that only ever
 * happens later, inside the real POST /api/checkout submission — see
 * lib/checkout.ts's CheckoutPayload.voucher_code). Money is still
 * server-computed here (ORD-9): gameId/packageId, not a price, is
 * what's sent — VoucherPreviewController recomputes the real selling
 * price from the stored Package itself.
 *
 * ADR-044: schema is the source of truth for the response shape below.
 */

const VoucherPreviewResultSchema = z.object({
  selling_price: z.number(),
  discount: z.number(),
  remaining_after: z.number(),
});

export type VoucherPreviewResult = z.infer<typeof VoucherPreviewResultSchema>;

export async function previewVoucher(
  gameId: number,
  packageId: number,
  voucherCode: string,
  customerEmail: string,
  customerPhone?: string,
) {
  const path = "/api/vouchers/preview";
  const raw = await apiFetch<unknown>(path, {
    method: "POST",
    body: {
      game_id: gameId,
      package_id: packageId,
      voucher_code: voucherCode,
      customer_email: customerEmail,
      customer_phone: customerPhone || undefined,
    },
  });
  return parseResponse(VoucherPreviewResultSchema, raw, "VoucherPreviewResult", path);
}
