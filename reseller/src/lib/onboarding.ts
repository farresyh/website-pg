/**
 * ADR-133 decisions 14-16: the portal's first-run checklist. The backend
 * (`PartnerReadinessService`) says which steps are done; this file only holds
 * the words and the links, and the few rules a screen needs (progress,
 * banners). Nothing here decides readiness.
 */

export type OwnerType = "affiliate" | "reseller";
export type StepState = "done" | "todo" | "waiting_on_us" | "optional";

export interface OnboardingStep {
  key: string;
  state: StepState;
  required: boolean;
  /** The DNS step's teardown deadline (ISO), or the reseller order channels that are set up. */
  detail: string | null;
}

export interface Onboarding {
  ready: boolean;
  steps: OnboardingStep[];
}

interface StepCopy {
  title: string;
  /** Shown while the step is open. */
  text: string;
  href?: string;
  cta?: string;
}

const AFFILIATE_COPY: Record<string, StepCopy> = {
  password: { title: "Set your password", text: "You chose a password from your invite." },
  bank: {
    title: "Add your bank details",
    text: "PekanGame pays your earnings to this account on the 1st of each month. Until it is added, your payout is skipped.",
    href: "/profile",
    cta: "Add bank details",
  },
  domain: {
    title: "Connect your domain",
    text: "Your store needs its own web address, such as shop.yourbrand.com.",
    href: "/domains",
    cta: "Add a domain",
  },
  dns: {
    title: "Verify your DNS",
    text: "Add the DNS records we show you. We check them every day and the store goes online once they match.",
    href: "/domains",
    cta: "See the DNS records",
  },
  payments_approval: {
    title: "Payments approved by PekanGame",
    text: "We turn payments on after our payment partner approves your store. This step is ours, and we will email you when it is done.",
  },
  branding: {
    title: "Brand your store",
    text: "Add your logo and pick a theme. Until then your store looks like PekanGame's.",
    href: "/storefront",
    cta: "Open Storefront",
  },
  markup: {
    title: "Review your markup",
    text: "Your markup is what you earn on each order. Check it suits your customers.",
    href: "/storefront?tab=pricing",
    cta: "Review pricing",
  },
  first_order: {
    title: "Get your first order",
    text: "Place a small order on your own store and check it is delivered.",
  },
};

const RESELLER_COPY: Record<string, StepCopy> = {
  password: { title: "Set your password", text: "You chose a password from your invite." },
  tier: {
    title: "Pricing set up by PekanGame",
    text: "Your account needs a pricing tier before it can add balance or place orders. Contact us and we will set it up.",
  },
  add_balance: {
    title: "Add Purchase Balance",
    text: "Orders are paid from your Purchase Balance. The smallest top-up is RM10.",
    href: "/balance",
    cta: "Add Balance",
  },
  order_channel: {
    title: "Choose how you order",
    text: "Create an API key for your own system, or link a WhatsApp group so you can order from the bot.",
    href: "/api-keys",
    cta: "Create an API key",
  },
  first_order: {
    title: "Place your first order",
    text: "Orders are real and paid from your balance. Start with a small package.",
  },
  webhook: {
    title: "Set a delivery webhook",
    text: "Optional. We tell your system when an order is delivered, so you do not have to keep asking.",
    href: "/api-keys",
    cta: "Set a webhook",
  },
};

const COPY: Record<OwnerType, Record<string, StepCopy>> = { affiliate: AFFILIATE_COPY, reseller: RESELLER_COPY };

export function stepCopy(ownerType: OwnerType, key: string): StepCopy {
  return COPY[ownerType][key] ?? { title: key.replaceAll("_", " "), text: "" };
}

/** Optional steps are not counted: a checklist that can never finish would never close. */
export function progress(steps: OnboardingStep[]): { done: number; total: number } {
  const counted = steps.filter((s) => s.state !== "optional");
  return { done: counted.filter((s) => s.state === "done").length, total: counted.length };
}

/** Every step the account needs before it can sell or order is done. */
export function requiredDone(steps: OnboardingStep[]): boolean {
  return steps.filter((s) => s.required).every((s) => s.state === "done");
}

export interface PartnerBanner {
  key: string;
  tone: "warning" | "info";
  title: string;
  text: string;
  href?: string;
  cta?: string;
}

const step = (o: Onboarding, key: string) => o.steps.find((s) => s.key === key);

/**
 * ADR-133 decision 15: a banner that cannot be dismissed, only for what costs
 * money or stops selling. Everything else stays in the checklist.
 */
export function banners(ownerType: OwnerType, o: Onboarding): PartnerBanner[] {
  const out: PartnerBanner[] = [];

  if (ownerType === "affiliate") {
    if (step(o, "bank")?.state === "todo") {
      out.push({
        key: "bank",
        tone: "warning",
        title: "Add your bank details",
        text: "Your monthly payout is skipped until you do.",
        href: "/profile",
        cta: "Add bank details",
      });
    }
    if (step(o, "dns")?.state === "done" && step(o, "payments_approval")?.state === "waiting_on_us") {
      out.push({
        key: "payments",
        tone: "info",
        title: "Your store is online, payments are not enabled yet",
        text: "Customers can browse but cannot pay until PekanGame turns payments on. We will email you.",
      });
    }
  } else if (step(o, "tier")?.state === "todo") {
    out.push({
      key: "tier",
      tone: "warning",
      title: "Your account is not set up yet",
      text: "It needs a pricing tier before it can add balance or place orders. Please contact PekanGame.",
    });
  }

  return out;
}

/** The affiliate store's one-word status: never "ready" while payments are off. */
export function storeStatus(o: Onboarding | null, domainActive: boolean): "ready" | "online" | null {
  if (o?.ready) return "ready";
  return domainActive ? "online" : null;
}

/** Which order channels a reseller has set up, from the `order_channel` step. */
export function channelsSetUp(o: Onboarding | null): { apiKey: boolean; whatsAppGroup: boolean } {
  const done = (step(o ?? { ready: false, steps: [] }, "order_channel")?.detail ?? "").split(",");
  return { apiKey: done.includes("api_key"), whatsAppGroup: done.includes("whatsapp_group") };
}
