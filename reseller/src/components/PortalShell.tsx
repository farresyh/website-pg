"use client";

import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { SidebarProvider } from "@/context/SidebarContext";
import { useClientSession } from "@/hooks/useClientSession";
import { clearClientSession, getClientSession } from "@/lib/session";
import { crumbsFor, phoneNav, portalNav } from "@/lib/portal-nav";
import PortalSidebar from "@/layout/PortalSidebar";
import PortalTopBar, { ThemeToggle } from "@/layout/PortalTopBar";
import MobilePortalNav from "@/layout/MobilePortalNav";
import Backdrop from "@/layout/Backdrop";
import ImpersonationBanner from "@/components/ImpersonationBanner";
import TrustFooter from "@/components/shell/TrustFooter";

/**
 * Shared role-aware portal chrome: ADR-112's navigation model in ADR-128's
 * "PekanGame Partner" visual system.
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
    return <div className="min-h-screen bg-canvas" />;
  }

  const ownerType = session.owner_type;
  const roleLabel = ownerType === "reseller" ? "Reseller" : "Affiliate";
  const accountName = session.business_name || "—";
  const phone = phoneNav(ownerType);

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
    <div className="min-h-screen bg-canvas">
      <PortalSidebar
        groups={portalNav(ownerType)}
        moreItems={phone.more}
        pathname={pathname}
        accountName={accountName}
        roleLabel={roleLabel}
        phoneFooter={
          <div className="space-y-2">
            <p className="truncate text-xs text-ink-subtle">
              {session.name} · {session.email}
            </p>
            <div className="flex items-center justify-between gap-3">
              <ThemeToggle />
              <button
                type="button"
                onClick={handleLogout}
                disabled={loggingOut}
                className="min-h-11 rounded-md border border-border-strong px-4 text-sm font-semibold text-ink hover:bg-subtle disabled:opacity-45"
              >
                {loggingOut ? "Signing out…" : "Sign out"}
              </button>
            </div>
          </div>
        }
      />
      <Backdrop />

      <div className="min-w-0 pb-[calc(4rem+env(safe-area-inset-bottom))] lg:ml-[var(--width-sidebar)] lg:pb-0">
        <PortalTopBar
          crumbs={crumbsFor(ownerType, pathname)}
          accountName={accountName}
          roleLabel={roleLabel}
          personName={session.name}
          personEmail={session.email}
          onSignOut={handleLogout}
          signingOut={loggingOut}
        />
        <ImpersonationBanner />
        <main className="px-4 pt-5 pb-8 md:px-8 md:pt-7 md:pb-10">
          {children}
          <TrustFooter />
        </main>
      </div>
      <MobilePortalNav primaryItems={phone.primary} moreItems={phone.more} pathname={pathname} />
    </div>
  );
}
