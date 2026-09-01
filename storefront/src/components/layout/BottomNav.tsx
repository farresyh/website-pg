"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { House, Tag, Receipt, WhatsappLogo } from "@phosphor-icons/react/dist/ssr";

/**
 * 4 items, not the original draft's 5 — "Account" is dropped (no
 * Customer accounts exist, ADR-011) and "Search" is dropped too
 * (SiteHeader's search row is always visible on mobile already, a
 * bottom-nav entry for it would just duplicate a reachable control).
 */
const ITEMS = [
  { href: "/", label: "Home", icon: House },
  { href: "/#promotions", label: "Promotions", icon: Tag },
  { href: "/track-order", label: "Track Order", icon: Receipt },
  { href: "https://wa.me/60000000000", label: "Support", icon: WhatsappLogo, external: true },
];

export default function BottomNav() {
  const pathname = usePathname();

  return (
    <nav className="fixed inset-x-0 bottom-0 z-50 flex border-t-2 border-ink bg-surface-container-lowest lg:hidden">
      {ITEMS.map(({ href, label, icon: Icon, external }) => {
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
