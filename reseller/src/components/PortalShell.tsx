"use client";

import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { SidebarProvider, useSidebar } from "@/context/SidebarContext";
import { useClientSession } from "@/hooks/useClientSession";
import { clearClientSession, getClientSession } from "@/lib/session";
import PanelSidebar, { type PanelNavLink, type PanelNavSection } from "@/layout/PanelSidebar";
import PortalHeader from "@/layout/PortalHeader";
import MobilePortalNav from "@/layout/MobilePortalNav";
import Backdrop from "@/layout/Backdrop";
import ImpersonationBanner from "@/components/ImpersonationBanner";
import { ThemeToggleButton } from "@/components/common/ThemeToggleButton";
import { GridIcon, ListIcon, DollarLineIcon, TrendUpIcon, BoxLineIcon, UserCircleIcon, KeyIcon, GlobeIcon, StorefrontIcon } from "@/icons";

const AFFILIATE_ITEMS: PanelNavLink[] = [
  { kind: "link", name: "Dashboard", href: "/dashboard", icon: <GridIcon /> },
  { kind: "link", name: "Orders", href: "/orders", icon: <ListIcon /> },
  { kind: "link", name: "Earnings", href: "/earnings", icon: <TrendUpIcon /> },
  { kind: "link", name: "Withdrawal", href: "/withdrawal", icon: <DollarLineIcon /> },
  { kind: "link", name: "Subscription", href: "/subscription", icon: <BoxLineIcon /> },
  { kind: "link", name: "Storefront", href: "/storefront", icon: <StorefrontIcon /> },
  { kind: "link", name: "Domains", href: "/domains", icon: <GlobeIcon /> },
  { kind: "link", name: "Profile", href: "/profile", icon: <UserCircleIcon /> },
];

// ADR-072 decision 5 / PR-G: a Reseller (wallet) portal account never
// sees Earnings/Subscription/Withdrawal (it only ever spends, never
// earns) — Wallet + API Keys replace them. An Affiliate never sees
// Wallet/API-Keys, the reverse of the same rule.
const RESELLER_ITEMS: PanelNavLink[] = [
  { kind: "link", name: "Dashboard", href: "/dashboard", icon: <GridIcon /> },
  { kind: "link", name: "Orders", href: "/orders", icon: <ListIcon /> },
  { kind: "link", name: "Wallet", href: "/wallet", icon: <DollarLineIcon /> },
  { kind: "link", name: "API Keys", href: "/api-keys", icon: <KeyIcon /> },
  { kind: "link", name: "Profile", href: "/profile", icon: <UserCircleIcon /> },
];

/**
 * Shared role-aware portal chrome. ADR-112 keeps the desktop rail and
 * introduces a four-slot bottom nav plus a secondary mobile drawer.
 */
export default function PortalShell({ children }: { children: React.ReactNode }) {
  return (
    <SidebarProvider>
      <PortalShellFrame>{children}</PortalShellFrame>
    </SidebarProvider>
  );
}

function PortalShellFrame({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const session = useClientSession();
  const [loggingOut, setLoggingOut] = useState(false);
  const { isExpanded, isHovered, isMobileOpen } = useSidebar();

  useEffect(() => {
    // The cookie can outlive this tab's sessionStorage (or belong to a
    // different tab). Never leave a cookie-only visitor on a dead dashboard.
    // Read storage directly: useClientSession's SSR snapshot is briefly null
    // even when a valid client session is present during hydration.
    if (getClientSession()) return;

    void fetch("/api/logout", { method: "POST" })
      .catch(() => null)
      .then(() => router.replace("/login"));
  }, [session, router]);

  // A null session must not render the Affiliate nav by default. It also
  // keeps child pages from making requests for the wrong account type.
  if (!session) {
    return <div className="min-h-screen bg-gray-50 dark:bg-gray-900" />;
  }

  const isReseller = session.owner_type === "reseller";
  const items = isReseller ? RESELLER_ITEMS : AFFILIATE_ITEMS;
  const sections: PanelNavSection[] = [{ title: "Menu", items }];
  const mobileSections: PanelNavSection[] = [{ title: "More", items: items.slice(3) }];
  const isActive = (href: string) => pathname === href || pathname.startsWith(`${href}/`);

  const mainContentMargin = isMobileOpen
    ? "ml-0"
    : isExpanded || isHovered
      ? "lg:ml-[290px]"
      : "lg:ml-[90px]";

  async function handleLogout() {
    setLoggingOut(true);
    const current = getClientSession();
    try {
      await fetch("/api/logout", {
        method: "POST",
        headers: current ? { Authorization: `Bearer ${current.token}` } : {},
      });
    } catch {
      // best-effort — clear locally regardless (mirrors admin)
    }
    clearClientSession();
    router.replace("/login");
  }

  return (
    <div className="min-h-screen xl:flex">
      <PanelSidebar
        homeHref="/dashboard"
        brandLabel="PekanGame"
        shortLabel="PG"
        sections={sections}
        mobileSections={mobileSections}
        isActive={isActive}
        mobileFooter={
          <div className="space-y-3">
            <p className="truncate text-sm text-gray-500 dark:text-gray-400">{session.name} · {session.email}</p>
            <div className="flex items-center justify-between gap-3">
              <ThemeToggleButton />
              <button
                type="button"
                onClick={handleLogout}
                disabled={loggingOut}
                className="min-h-11 rounded-lg border border-gray-200 px-4 text-sm font-medium text-gray-700 hover:bg-gray-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 disabled:opacity-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
              >
                {loggingOut ? "Signing out…" : "Sign out"}
              </button>
            </div>
          </div>
        }
        extra={
          <>
            <p className="mt-1 text-theme-xs font-medium uppercase tracking-wide text-gray-400">
              {isReseller ? "Reseller Portal" : "Affiliate Portal"}
            </p>
            <p className="mt-0.5 truncate text-sm font-medium text-gray-600 dark:text-gray-300">
              {session?.business_name || "—"}
            </p>
          </>
        }
      />
      <Backdrop />

      <div className={`min-w-0 flex-1 pb-[calc(5rem+env(safe-area-inset-bottom))] transition-all duration-300 ease-in-out lg:pb-0 ${mainContentMargin}`}>
        <PortalHeader
          roleLabel={isReseller ? "Reseller Portal" : "Affiliate Portal"}
          sessionLabel={session ? `${session.name} · ${session.email}` : ""}
          onSignOut={handleLogout}
          signingOut={loggingOut}
        />
        <ImpersonationBanner />
        <main className="mx-auto max-w-(--breakpoint-xl) p-4 md:p-6">{children}</main>
      </div>
      <MobilePortalNav
        primaryItems={items.slice(0, 3)}
        moreActive={items.slice(3).some((item) => isActive(item.href))}
        isActive={isActive}
      />
    </div>
  );
}
