"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { MagnifyingGlass } from "@phosphor-icons/react/dist/ssr";
import Logo from "@/components/ui/Logo";
import Button from "@/components/ui/Button";
import { useSearch } from "@/context/SearchContext";
import { useSiteConfig } from "@/context/SiteConfigContext";

/**
 * No "Log In" and no "Account" anywhere — storefront is guest-checkout
 * only (ADR-011). The search input is always directly reachable: inline
 * in the header row on desktop, full-width row below it on mobile.
 *
 * The "Membership" nav item only appears when membership is actually
 * enabled for this storefront (ADR-061's dual kill-switch). ADR-071
 * PR1: `membershipEnabled` comes from `SiteConfigProvider` (resolved
 * once server-side), not a per-mount `listPlans()` fetch.
 *
 * The nav highlight follows the page (2026-10-16): "All Products" on the
 * home page and a game's order page, "Membership" and "Game ID Checker"
 * on their own pages, none elsewhere. It used to be fixed on "All Products".
 */
const NAV_LINK = "border-b-2 pb-0.5";
const NAV_ACTIVE = "border-header-accent text-on-header-accent";
const NAV_IDLE = "border-transparent hover:text-on-header-accent";
export default function SiteHeader() {
  const { query, setQuery } = useSearch();
  const { membershipEnabled, checkIdEnabled, jokiEnabled, branding } = useSiteConfig();
  const pathname = usePathname();
  const productsActive = pathname === "/" || (pathname.startsWith("/order/") && !pathname.startsWith("/order/status"));

  function navClass(active: boolean): string {
    return `${NAV_LINK} ${active ? NAV_ACTIVE : NAV_IDLE}`;
  }

  return (
    <header className="sticky top-0 z-40 border-b-2 border-ink bg-header">
      <div className="mx-auto flex max-w-[1200px] items-center justify-between gap-4 px-4 py-3">
        <Link href="/" className="flex shrink-0 items-center gap-2">
          <Logo maxHeight={48} maxWidth={180} src={branding.logoUrl} alt={branding.storeName} />
          <span className="font-display text-xl font-bold uppercase leading-none tracking-tight">{branding.storeName}</span>
        </Link>

        <div className="hidden max-w-[420px] flex-1 items-center gap-2 rounded-full border-2 border-ink bg-surface-container-lowest px-4 py-2 focus-within:border-secondary lg:flex">
          <MagnifyingGlass size={16} className="shrink-0 text-outline" />
          <input
            type="text"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Search for games or products..."
            className="w-full bg-transparent text-sm text-on-surface placeholder:text-outline focus:outline-none"
          />
        </div>

        <nav className="hidden items-center gap-6 font-display text-[13px] font-bold uppercase tracking-wide text-on-surface-variant lg:flex">
          <Link href="/#popular-picks" aria-current={productsActive ? "page" : undefined} className={navClass(productsActive)}>
            All Products
          </Link>
          {jokiEnabled && (
            <Link
              href="/joki"
              aria-current={pathname.startsWith("/joki") ? "page" : undefined}
              className={navClass(pathname.startsWith("/joki"))}
            >
              Joki Rank
            </Link>
          )}
          {membershipEnabled && (
            <Link
              href="/membership"
              aria-current={pathname.startsWith("/membership") ? "page" : undefined}
              className={navClass(pathname.startsWith("/membership"))}
            >
              Membership
            </Link>
          )}
          {checkIdEnabled && (
            <Link
              href="/check-id"
              aria-current={pathname.startsWith("/check-id") ? "page" : undefined}
              className={navClass(pathname.startsWith("/check-id"))}
            >
              Game ID Checker
            </Link>
          )}
        </nav>

        <Button href="/track-order" size="sm" variant="header" className="shrink-0">
          Track Order
        </Button>
      </div>

      <div className="flex items-center gap-2 border-t-2 border-ink bg-surface-container-lowest px-4 py-2.5 lg:hidden">
        <MagnifyingGlass size={16} className="shrink-0 text-outline" />
        <input
          type="text"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Search for games or products..."
          className="w-full bg-transparent text-sm text-on-surface placeholder:text-outline focus:outline-none"
        />
      </div>
    </header>
  );
}
