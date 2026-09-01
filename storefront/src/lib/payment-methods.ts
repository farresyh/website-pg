import { z } from "zod";
import { apiFetch } from "@/lib/api-client";
import { parseResponse } from "@/lib/schema-validation";

/**
 * Real request/response contract for the public payment-methods
 * endpoint (PaymentMethodCatalogController, ADR-022's newest addendum
 * decision 5) — replaces placeholder-data.ts's hardcoded
 * PLACEHOLDER_PAYMENT_CHANNELS, which was never actually live for
 * either gateway (admin activate/deactivate in /middleware/payment-
 * methods had zero effect on what this page showed). Deliberately has
 * no `gateway` field — which processor handles a channel is an
 * internal routing detail the backend never serializes here.
 *
 * ADR-044: schema is the source of truth for the wire shape below.
 */
export interface PaymentChannel {
  channelCode: string;
  label: string;
  category: string;
}

const PaymentChannelWireSchema = z.object({
  channel_code: z.string(),
  label: z.string(),
  category: z.string(),
});

export async function listPaymentChannels(): Promise<PaymentChannel[]> {
  const path = "/api/catalog/payment-methods";
  const raw = await apiFetch<unknown>(path);
  const wire = parseResponse(z.array(PaymentChannelWireSchema), raw, "PaymentChannelWire[]", path);
  return wire.map((channel) => ({
    channelCode: channel.channel_code,
    label: channel.label,
    category: channel.category,
  }));
}
