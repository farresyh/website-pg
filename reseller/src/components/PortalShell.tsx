"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useState } from "react";
import { useClientSession } from "@/hooks/useClientSession";
import { clearClientSession, getClientSession } from "@/lib/session";
import { useTheme } from "@/context/ThemeContext";
import ImpersonationBanner from "@/components/ImpersonationBanner";

const AFFILIATE_NAV = [
  { href: "/dashboard", label: "Dashboard" },
  { href: "/orders", label: "Orders" },
  { href: "/earnings", label: "Earnings" },
  { href: "/withdrawal", label: "Withdrawal" },
  { href: "/subscription", label: "Subscription" },
  { href: "/profile", label: "Profile" },
];

// ADR-072 decision 5 / PR-G: a Reseller (wallet) portal account never
// sees Earnings/Subscription/Withdrawal (it only ever spends, never
// earns) — Wallet + API Keys replace them. An Affiliate never sees
// Wallet/API-Keys, the reverse of the same rule.
const RESELLER_NAV = [
  { href: "/dashboard", label: "Dashboard" },
  { href: "/orders", label: "Orders" },
  { href: "/wallet", label: "Wallet" },
  { href: "/api-keys", label: "API Keys" },
  { href: "/profile", label: "Profile" },
];

export default function PortalShell({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();
  const session = useClientSession();
  const { theme, toggleTheme } = useTheme();
  const [loggingOut, setLoggingOut] = useState(false);
  const NAV = session?.owner_type === "reseller" ? RESELLER_NAV : AFFILIATE_NAV;

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
    <div className="min-h-screen lg:flex">
      <aside className="border-b border-gray-200 bg-white px-4 py-4 lg:h-screen lg:w-64 lg:border-b-0 lg:border-r dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="mb-6 px-2">
          <p className="text-theme-xs font-medium uppercase tracking-wide text-gray-400">
            Reseller Portal
          </p>
          <p className="mt-0.5 truncate text-sm font-semibold text-gray-800 dark:text-white/90">
            {session?.business_name || "—"}
          </p>
        </div>
        <nav className="flex gap-1 lg:flex-col">
          {NAV.map((item) => {
            const active =
              pathname === item.href || pathname.startsWith(`${item.href}/`);
            return (
              <Link
                key={item.href}
                href={item.href}
                className={`menu-item ${active ? "menu-item-active" : "menu-item-inactive"}`}
              >
                {item.label}
              </Link>
            );
          })}
        </nav>
      </aside>

      <div className="flex-1">
        <header className="flex items-center justify-between border-b border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-white/[0.03]">
          <div className="text-theme-sm text-gray-500 dark:text-gray-400">
            {session ? `${session.name} · ${session.email}` : ""}
          </div>
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={toggleTheme}
              className="rounded-lg border border-gray-200 px-3 py-1.5 text-theme-xs text-gray-600 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
            >
              {theme === "dark" ? "Light" : "Dark"}
            </button>
            <button
              type="button"
              onClick={handleLogout}
              disabled={loggingOut}
              className="rounded-lg border border-gray-200 px-3 py-1.5 text-theme-xs text-gray-600 hover:bg-gray-100 disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
            >
              {loggingOut ? "Signing out…" : "Sign out"}
            </button>
          </div>
        </header>

        <ImpersonationBanner />

        <main className="mx-auto max-w-(--breakpoint-xl) p-4 md:p-6">{children}</main>
      </div>
    </div>
  );
}
