"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { House, Crown, Receipt, WhatsappLogo, SquaresFour, X } from "@phosphor-icons/react/dist/ssr";
import { useSiteConfig } from "@/context/SiteConfigContext";

interface NavItem {
  href: string;
  label: string;
  icon: typeof House;
  external?: boolean;
}

interface DrawerLink {
  href: string;
  label: string;
}

/**
 * Floating pill bar (2026-09-18 redesign, founder-supplied reference).
 * The raised centre button was kept as "a fixed, obvious slot to
 * re-target at a future feature/page without another nav reshuffle";
 * ADR-123 decision 11 re-targets it to **More**, which opens a bottom
 * drawer (Menu: Game ID Checker, Price List; Info: About Us, Terms,
 * Privacy). Track Order moves into the right pill and stays a button in
 * the header. Left pill: Home, Membership; right pill: Track Order,
 * Support. "Account" was dropped (ADR-011), "Search" too (SiteHeader's
 * search row is always visible on mobile).
 *
 * `membershipEnabled` (the ADR-055 dual kill switch), the Support
 * WhatsApp href (admin Store Branding, ADR-071 PR0) and the drawer's
 * `priceListEnabled` / `checkIdEnabled` all come from `SiteConfigProvider`,
 * resolved once server-side (ADR-071 PR1 decision 5). "Support" only
 * appears once a WhatsApp target is configured, mirroring SiteFooter.
 */
const HOME_ITEM: NavItem = { href: "/", label: "Home", icon: House };
const TRACK_ORDER_ITEM: NavItem = { href: "/track-order", label: "Track Order", icon: Receipt };

const INFO_LINKS: DrawerLink[] = [
  { href: "/about-us", label: "About Us" },
  { href: "/terms", label: "Terms & Conditions" },
  { href: "/refund-policy", label: "Refund Policy" },
  { href: "/privacy", label: "Privacy Policy" },
];

export default function BottomNav() {
  const pathname = usePathname();
  const { membershipEnabled, whatsappHref, priceListEnabled, checkIdEnabled, jokiEnabled } = useSiteConfig();
  const [drawerOpen, setDrawerOpen] = useState(false);
  const dialogRef = useRef<HTMLDialogElement>(null);

  // A navigation from inside the drawer closes it.
  useEffect(() => {
    dialogRef.current?.close();
  }, [pathname]);

  // ADR-071 PR3 — a game order page (`/order/<slug>`, not the status
  // page) is a focused checkout task; its own sticky "Review & Pay" bar
  // takes this slot instead.
  // `/joki` is a checkout too (ADR-126 PR 5), with the same sticky bar.
  const onCheckout = (pathname.startsWith("/order/") && !pathname.startsWith("/order/status")) || pathname === "/joki";
  if (onCheckout) return null;

  const leftItems: NavItem[] = membershipEnabled
    ? [HOME_ITEM, { href: "/membership", label: "Membership", icon: Crown }]
    : [HOME_ITEM];
  const rightItems: NavItem[] = whatsappHref
    ? [TRACK_ORDER_ITEM, { href: whatsappHref, label: "Support", icon: WhatsappLogo, external: true }]
    : [TRACK_ORDER_ITEM];

  const menuLinks: DrawerLink[] = [
    ...(jokiEnabled ? [{ href: "/joki", label: "Joki Rank" }] : []),
    ...(checkIdEnabled ? [{ href: "/check-id", label: "Game ID Checker" }] : []),
    ...(priceListEnabled ? [{ href: "/price-list", label: "Price List" }] : []),
  ];
  const drawerLinks = [...menuLinks, ...INFO_LINKS];
  const moreActive = drawerOpen || drawerLinks.some((link) => pathname.startsWith(link.href));

  function isActive(href: string): boolean {
    if (href === "/") return pathname === "/";
    if (href === "/track-order") return pathname.startsWith("/track-order") || pathname.startsWith("/order/status");
    return pathname.startsWith(href);
  }

  function renderItem({ href, label, icon: Icon, external }: NavItem) {
    const active = !external && isActive(href);
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
    <>
      <div className="fixed inset-x-0 bottom-0 z-50 pb-[calc(0.75rem+env(safe-area-inset-bottom))] lg:hidden">
        <div className="relative mx-4 flex items-stretch justify-center gap-3">
          <div className="flex flex-1 items-center justify-around rounded-full border-2 border-ink bg-ink px-1 neo-sm">
            {leftItems.map(renderItem)}
          </div>
          <div className="flex flex-1 items-center justify-around rounded-full border-2 border-ink bg-ink px-1 neo-sm">
            {rightItems.map(renderItem)}
          </div>

          <button
            type="button"
            aria-label="More"
            aria-haspopup="dialog"
            aria-expanded={drawerOpen}
            onClick={() => {
              dialogRef.current?.showModal();
              setDrawerOpen(true);
            }}
            className={`absolute left-1/2 top-0 flex h-14 w-14 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-2 border-ink neo transition-transform active:scale-95 ${
              moreActive ? "bg-primary-container" : "bg-primary"
            }`}
          >
            <SquaresFour size={24} weight="fill" className="text-on-primary" />
          </button>
        </div>
      </div>

      <dialog
        ref={dialogRef}
        aria-labelledby="more-drawer-title"
        onClose={() => setDrawerOpen(false)}
        onClick={(e) => {
          // A click on the backdrop (the dialog element itself) or on a
          // link closes it; a link to the current page changes no pathname.
          if (e.target === e.currentTarget || (e.target as HTMLElement).closest("a")) e.currentTarget.close();
        }}
        className="m-0 mt-auto backdrop:bg-ink/60 w-full max-w-none rounded-t-2xl border-2 border-b-0 border-ink bg-surface p-0 text-on-surface lg:hidden"
      >
        <div className="px-5 pb-[calc(1.5rem+env(safe-area-inset-bottom))] pt-4">
          <div className="mb-3 flex items-center justify-between">
            <h2 id="more-drawer-title" className="font-display text-base font-bold uppercase tracking-wide">
              More
            </h2>
            <button
              type="button"
              aria-label="Close"
              onClick={() => dialogRef.current?.close()}
              className="flex h-11 w-11 items-center justify-center rounded-full border-2 border-ink bg-surface-container-lowest"
            >
              <X size={18} weight="bold" />
            </button>
          </div>

          {menuLinks.length > 0 && <DrawerGroup title="Menu" links={menuLinks} isActive={isActive} />}
          <DrawerGroup title="Info" links={INFO_LINKS} isActive={isActive} />
        </div>
      </dialog>
    </>
  );
}

function DrawerGroup({ title, links, isActive }: { title: string; links: DrawerLink[]; isActive: (href: string) => boolean }) {
  return (
    <section className="mt-3">
      <h3 className="mb-2 font-display text-[11px] font-bold uppercase tracking-wide text-on-surface-variant">{title}</h3>
      <ul className="overflow-hidden rounded-md border-2 border-ink bg-surface-container-lowest">
        {links.map((link) => (
          <li key={link.href} className="border-b-2 border-ink last:border-b-0">
            <Link
              href={link.href}
              aria-current={isActive(link.href) ? "page" : undefined}
              className={`flex min-h-12 items-center px-4 font-display text-sm font-bold ${
                isActive(link.href) ? "bg-primary-fixed text-primary-on-surface" : "text-on-surface hover:bg-surface-container-low"
              }`}
            >
              {link.label}
            </Link>
          </li>
        ))}
      </ul>
    </section>
  );
}
