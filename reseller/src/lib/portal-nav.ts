import type { PortalIconName } from "@/components/shell/PortalIcon";

/**
 * The portal's navigation per account type (ADR-072 decision 5: an
 * Affiliate never sees Wallet/API keys, a Reseller never sees
 * Earnings/Subscription/Withdrawal). ADR-112 fixes the destinations and the
 * phone bar (Dashboard, Orders, the role's money page, More); ADR-128 adds
 * the sidebar groups.
 */
export type PortalNavItem = { name: string; href: string; icon: PortalIconName };
export type PortalNavGroup = { label: string; items: PortalNavItem[] };

const AFFILIATE_NAV: PortalNavGroup[] = [
  { label: "Overview", items: [{ name: "Dashboard", href: "/dashboard", icon: "dashboard" }] },
  {
    label: "Sales",
    items: [
      { name: "Orders", href: "/orders", icon: "orders" },
      { name: "Earnings", href: "/earnings", icon: "trend" },
      { name: "Withdrawal", href: "/withdrawal", icon: "bank" },
    ],
  },
  {
    label: "Store",
    items: [
      { name: "Storefront", href: "/storefront", icon: "store" },
      { name: "Domains", href: "/domains", icon: "globe" },
      { name: "Subscription", href: "/subscription", icon: "box" },
    ],
  },
  { label: "Account", items: [{ name: "Profile", href: "/profile", icon: "user" }] },
];

const RESELLER_NAV: PortalNavGroup[] = [
  { label: "Overview", items: [{ name: "Dashboard", href: "/dashboard", icon: "dashboard" }] },
  {
    label: "Orders & money",
    items: [
      { name: "Orders", href: "/orders", icon: "orders" },
      { name: "Wallet", href: "/wallet", icon: "wallet" },
    ],
  },
  { label: "Integration", items: [{ name: "API keys", href: "/api-keys", icon: "key" }] },
  { label: "Account", items: [{ name: "Profile", href: "/profile", icon: "user" }] },
];

const PHONE_BAR: Record<"affiliate" | "reseller", string[]> = {
  affiliate: ["/dashboard", "/orders", "/earnings"],
  reseller: ["/dashboard", "/orders", "/wallet"],
};

export function portalNav(ownerType: "affiliate" | "reseller"): PortalNavGroup[] {
  return ownerType === "reseller" ? RESELLER_NAV : AFFILIATE_NAV;
}

/** The three phone-bar destinations, then everything else for the More drawer. */
export function phoneNav(ownerType: "affiliate" | "reseller"): { primary: PortalNavItem[]; more: PortalNavItem[] } {
  const all = portalNav(ownerType).flatMap((group) => group.items);
  const bar = PHONE_BAR[ownerType];
  return {
    primary: bar.map((href) => all.find((item) => item.href === href)!),
    more: all.filter((item) => !bar.includes(item.href)),
  };
}

export function isActivePath(pathname: string, href: string): boolean {
  return pathname === href || pathname.startsWith(`${href}/`);
}

/** The breadcrumb for the current page: its sidebar group, then its name. */
export function crumbsFor(ownerType: "affiliate" | "reseller", pathname: string): { group: string; page: string } | null {
  for (const group of portalNav(ownerType)) {
    const item = group.items.find((i) => isActivePath(pathname, i.href));
    if (item) return { group: group.label, page: item.name };
  }
  return null;
}
