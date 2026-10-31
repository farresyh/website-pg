/**
 * ADR-133 decision 18: what the backend's `PartnerReadinessService` says about
 * one affiliate or reseller, in the shape the admin lists carry. The words for
 * each open step live here; the decision of what is open does not.
 */
export interface Readiness {
  ready: boolean;
  /** The required steps still open, in checklist order. */
  needs: string[];
  /** Only PekanGame can move it on: today, the payments approval. */
  waiting_on_us: boolean;
}

const NEED_LABEL: Record<string, string> = {
  domain: "domain",
  dns: "DNS",
  payments_approval: "approval",
  tier: "tier",
  add_balance: "balance",
  order_channel: "order channel",
};

export function needLabel(key: string): string {
  return NEED_LABEL[key] ?? key.replaceAll("_", " ");
}
