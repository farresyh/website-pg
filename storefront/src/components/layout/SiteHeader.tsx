"use client";

import Link from "next/link";
import { MagnifyingGlass } from "@phosphor-icons/react/dist/ssr";
import Logo from "@/components/ui/Logo";
import Button from "@/components/ui/Button";
import { useSearch } from "@/context/SearchContext";

/**
 * No "Log In" here and no "Account" anywhere on the site — storefront
 * is guest-checkout only, no Customer accounts exist (ADR-011). The
 * standalone small search icon button from the original draft is
 * dropped entirely (was 38px, under the 44px touch-target minimum) —
 * the real search input is always directly reachable instead: inline
 * in the header row on desktop, full-width row below it on mobile.
 */
export default function SiteHeader() {
  const { query, setQuery } = useSearch();

  return (
    <header className="sticky top-0 z-40 border-b border-border bg-bg">
      <div className="mx-auto flex max-w-[1200px] items-center justify-between gap-4 px-4 py-3.5">
        <Link href="/" className="flex shrink-0 items-center gap-2">
          <Logo size={32} />
          <span className="font-display text-lg leading-none tracking-wide">KEDAI RUNCIT SOLOZ</span>
        </Link>

        <div className="hidden max-w-[420px] flex-1 items-center gap-2 rounded-full border border-border bg-surface px-4 py-2.5 lg:flex">
          <MagnifyingGlass size={16} className="shrink-0 text-text-muted" />
          <input
            type="text"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Search for games or products..."
            className="w-full bg-transparent text-sm text-text placeholder:text-text-muted focus:outline-none"
          />
        </div>

        <nav className="hidden items-center gap-6 text-sm font-semibold text-text-muted lg:flex">
          <a href="#popular-picks" className="border-b-2 border-brand pb-1 text-text">
            All Products
          </a>
          <a href="#promotions" className="pb-1 hover:text-text">
            Promotions
          </a>
          <Link href="/track-order" className="pb-1 hover:text-text">
            Track Order
          </Link>
        </nav>

        <Button href="/track-order" size="sm" variant="outline" className="shrink-0">
          Track Order
        </Button>
      </div>

      <div className="flex items-center gap-2 rounded-none border-t border-border bg-surface px-4 py-2.5 lg:hidden">
        <MagnifyingGlass size={16} className="shrink-0 text-text-muted" />
        <input
          type="text"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Search for games or products..."
          className="w-full bg-transparent text-sm text-text placeholder:text-text-muted focus:outline-none"
        />
      </div>
    </header>
  );
}
