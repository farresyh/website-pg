import { apiFetch } from "@/lib/api-client";

/**
 * Real request/response contract for the public payment-methods
 * endpoint (PaymentMethodCatalogController, ADR-022's newest addendum
 * decision 5) — replaces placeholder-data.ts's hardcoded
 * PLACEHOLDER_PAYMENT_CHANNELS, which was never actually live for
 * either gateway (admin activate/deactivate in /middleware/payment-
 * methods had zero effect on what this page showed). Deliberately has
 * no `gateway` field — which processor handles a channel is an
 * internal routing detail the backend never serializes here.
 */
export interface PaymentChannel {
  channelCode: string;
  label: string;
  category: string;
}

interface PaymentChannelWire {
  channel_code: string;
  label: string;
  category: string;
}

export async function listPaymentChannels(): Promise<PaymentChannel[]> {
  const wire = await apiFetch<PaymentChannelWire[]>("/api/catalog/payment-methods");
  return wire.map((channel) => ({
    channelCode: channel.channel_code,
    label: channel.label,
    category: channel.category,
  }));
}
