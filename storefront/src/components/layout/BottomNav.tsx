"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { House, Crown, Receipt, WhatsappLogo } from "@phosphor-icons/react/dist/ssr";
import { useSiteConfig } from "@/context/SiteConfigContext";

interface NavItem {
  href: string;
  label: string;
  icon: typeof House;
  external?: boolean;
}

/**
 * 4 items, not the original draft's 5 — "Account" is dropped (no
 * Customer accounts exist, ADR-011) and "Search" is dropped too
 * (SiteHeader's search row is always visible on mobile already).
 *
 * `membershipEnabled` (the ADR-055 dual kill switch) and the Support
 * WhatsApp href (admin Store Branding, ADR-071 PR0) both come from
 * `SiteConfigProvider` — resolved once server-side (ADR-071 PR1
 * decision 5), no per-mount `listPlans()` / `getBranding()` fetch.
 * "Support" only appears once a WhatsApp target is configured,
 * mirroring SiteFooter's own conditional WhatsApp link.
 */
const BASE_ITEMS: NavItem[] = [
  { href: "/", label: "Home", icon: House },
  { href: "/track-order", label: "Track Order", icon: Receipt },
];

export default function BottomNav() {
  const pathname = usePathname();
  const { membershipEnabled, whatsappHref } = useSiteConfig();

  // ADR-071 PR3 — a game order page (`/order/<slug>`, not the status
  // page) is a focused checkout task; its own sticky "Review & Pay" bar
  // takes this slot instead. Home / Track Order / Support aren't what
  // the customer needs mid-purchase.
  const onCheckout = pathname.startsWith("/order/") && !pathname.startsWith("/order/status");
  if (onCheckout) return null;

  const supportItem: NavItem[] = whatsappHref
    ? [{ href: whatsappHref, label: "Support", icon: WhatsappLogo, external: true }]
    : [];
  const items: NavItem[] = membershipEnabled
    ? [BASE_ITEMS[0], { href: "/membership", label: "Membership", icon: Crown }, BASE_ITEMS[1], ...supportItem]
    : [...BASE_ITEMS, ...supportItem];

  return (
    <nav className="fixed inset-x-0 bottom-0 z-50 flex border-t-2 border-ink bg-surface-container-lowest pb-[env(safe-area-inset-bottom)] lg:hidden">
      {items.map(({ href, label, icon: Icon, external }) => {
        const active = !external && (href === "/" ? pathname === "/" : pathname.startsWith(href));
        const className = `flex min-h-11 flex-1 flex-col items-center gap-1 py-2.5 font-display text-[10.5px] font-bold uppercase tracking-wide ${
          active ? "text-primary" : "text-on-surface-variant"
        }`;

        return external ? (
          <a key={label} href={href} target="_blank" rel="noopener noreferrer" className={className}>
            <Icon size={20} weight={active ? "fill" : "regular"} />
            {label}
          </a>
        ) : (
          <Link key={label} href={href} className={className}>
            <Icon size={20} weight={active ? "fill" : "regular"} />
            {label}
          </Link>
        );
      })}
    </nav>
  );
}
