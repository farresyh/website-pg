"use client";

import Link from "next/link";
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
 */
export default function SiteHeader() {
  const { query, setQuery } = useSearch();
  const { membershipEnabled } = useSiteConfig();

  return (
    <header className="sticky top-0 z-40 border-b-2 border-ink bg-surface">
      <div className="mx-auto flex max-w-[1200px] items-center justify-between gap-4 px-4 py-3">
        <Link href="/" className="flex shrink-0 items-center gap-2">
          <Logo size={34} />
          <span className="font-display text-xl font-bold leading-none tracking-tight">PEKANGAME</span>
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
          <Link href="/#popular-picks" className="border-b-2 border-primary pb-0.5 text-primary">
            All Products
          </Link>
          {membershipEnabled && (
            <Link href="/membership" className="pb-0.5 hover:text-primary">
              Membership
            </Link>
          )}
          <Link href="/track-order" className="pb-0.5 hover:text-primary">
            Track Order
          </Link>
        </nav>

        <Button href="/track-order" size="sm" variant="primary" className="shrink-0">
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
