"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useState } from "react";
import { House, Crown, Receipt, WhatsappLogo } from "@phosphor-icons/react/dist/ssr";
import { listPlans } from "@/lib/membership";
import { getBranding } from "@/lib/branding";
import { resolveWhatsappHref } from "@/lib/whatsapp";

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
 * The "Membership" slot only appears when membership is enabled for this
 * storefront (same dual kill-switch as SiteHeader).
 *
 * The "Support" slot's href comes from admin Store Branding
 * (`social_links.whatsapp`, else `support_phone`) — ADR-071 PR0, no
 * more hardcoded `wa.me/60000000000`. It only appears once a WhatsApp
 * target is configured, mirroring SiteFooter's own conditional WhatsApp
 * link. PR2 lifts this to a SiteConfigProvider so it's available from
 * SSR without the client-fetch pop-in.
 */
const BASE_ITEMS: NavItem[] = [
  { href: "/", label: "Home", icon: House },
  { href: "/track-order", label: "Track Order", icon: Receipt },
];

export default function BottomNav() {
  const pathname = usePathname();
  const [membershipEnabled, setMembershipEnabled] = useState(false);
  const [supportHref, setSupportHref] = useState<string | null>(null);

  useEffect(() => {
    listPlans()
      .then((plans) => setMembershipEnabled(plans.length > 0))
      .catch(() => setMembershipEnabled(false));

    getBranding()
      .then((branding) => setSupportHref(resolveWhatsappHref(branding)))
      .catch(() => setSupportHref(null));
  }, []);

  const supportItem: NavItem[] = supportHref
    ? [{ href: supportHref, label: "Support", icon: WhatsappLogo, external: true }]
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
