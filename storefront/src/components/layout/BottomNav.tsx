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
 * Floating pill bar (2026-09-18 redesign, founder-supplied reference):
 * Track Order is pulled out of the flanking row into a raised center
 * button — the one action a guest-checkout customer with no account
 * (ADR-011) comes back to most. It's a deliberate duplicate of the
 * header's own "Track Order" button (founder call, not an oversight):
 * a fixed, obvious slot to re-target at a future feature/page without
 * another nav reshuffle. The flanking items split left/right around
 * it; "Account" was already dropped (ADR-011) and "Search" too
 * (SiteHeader's search row is always visible on mobile).
 *
 * `membershipEnabled` (the ADR-055 dual kill switch) and the Support
 * WhatsApp href (admin Store Branding, ADR-071 PR0) both come from
 * `SiteConfigProvider` — resolved once server-side (ADR-071 PR1
 * decision 5), no per-mount `listPlans()` / `getBranding()` fetch.
 * "Support" only appears once a WhatsApp target is configured,
 * mirroring SiteFooter's own conditional WhatsApp link.
 */
const HOME_ITEM: NavItem = { href: "/", label: "Home", icon: House };

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
  const flanking: NavItem[] = membershipEnabled
    ? [HOME_ITEM, { href: "/membership", label: "Membership", icon: Crown }, ...supportItem]
    : [HOME_ITEM, ...supportItem];

  const splitAt = Math.ceil(flanking.length / 2);
  const leftItems = flanking.slice(0, splitAt);
  const rightItems = flanking.slice(splitAt);

  const trackOrderActive = pathname.startsWith("/track-order") || pathname.startsWith("/order/status");

  function renderItem({ href, label, icon: Icon, external }: NavItem) {
    const active = !external && (href === "/" ? pathname === "/" : pathname.startsWith(href));
    const className = `flex min-h-11 flex-1 flex-col items-center gap-0.5 py-2 font-display text-[9.5px] font-bold uppercase tracking-wide ${
      active ? "text-on-primary" : "text-on-primary/60"
    }`;

    return external ? (
      <a key={label} href={href} target="_blank" rel="noopener noreferrer" className={className}>
        <Icon size={19} weight={active ? "fill" : "regular"} />
        {label}
      </a>
    ) : (
      <Link key={label} href={href} className={className}>
        <Icon size={19} weight={active ? "fill" : "regular"} />
        {label}
      </Link>
    );
  }

  return (
    <div className="fixed inset-x-0 bottom-0 z-50 pb-[calc(0.75rem+env(safe-area-inset-bottom))] lg:hidden">
      <div className="relative mx-4 flex items-stretch justify-center gap-3">
        {leftItems.length > 0 && (
          <div className="flex flex-1 items-center justify-around rounded-full border-2 border-ink bg-ink px-1 neo-sm">
            {leftItems.map(renderItem)}
          </div>
        )}
        {rightItems.length > 0 && (
          <div className="flex flex-1 items-center justify-around rounded-full border-2 border-ink bg-ink px-1 neo-sm">
            {rightItems.map(renderItem)}
          </div>
        )}

        <Link
          href="/track-order"
          aria-label="Track Order"
          className={`absolute left-1/2 top-0 flex h-14 w-14 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-2 border-ink neo transition-transform active:scale-95 ${
            trackOrderActive ? "bg-primary-container" : "bg-primary"
          }`}
        >
          <Receipt size={24} weight="fill" className="text-on-primary" />
        </Link>
      </div>
    </div>
  );
}
