"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useState } from "react";
import { House, Crown, Receipt, WhatsappLogo } from "@phosphor-icons/react/dist/ssr";
import { listPlans } from "@/lib/membership";

/**
 * 4 items, not the original draft's 5 — "Account" is dropped (no
 * Customer accounts exist, ADR-011) and "Search" is dropped too
 * (SiteHeader's search row is always visible on mobile already).
 *
 * The "Membership" slot only appears when membership is enabled for this
 * storefront (same dual kill-switch as SiteHeader); until then the nav
 * is Home / Track Order / Support.
 */
const BASE_ITEMS = [
  { href: "/", label: "Home", icon: House },
  { href: "/track-order", label: "Track Order", icon: Receipt },
  { href: "https://wa.me/60000000000", label: "Support", icon: WhatsappLogo, external: true },
];

export default function BottomNav() {
  const pathname = usePathname();
  const [membershipEnabled, setMembershipEnabled] = useState(false);

  useEffect(() => {
    listPlans()
      .then((plans) => setMembershipEnabled(plans.length > 0))
      .catch(() => setMembershipEnabled(false));
  }, []);

  const items = membershipEnabled
    ? [BASE_ITEMS[0], { href: "/membership", label: "Membership", icon: Crown }, ...BASE_ITEMS.slice(1)]
    : BASE_ITEMS;

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
