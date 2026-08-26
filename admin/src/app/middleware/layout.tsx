"use client";

/**
 * Middleware Panel shell (§6.19-6.21 of docs/prd.md) — Supplier Middleware
 * business panel (product matching, price sync, request logs, developer API
 * tester with raw supplier credentials). This is a SUPER-ADMIN-ONLY area
 * per §3 Users & Roles ("Admin ... Cannot modify system settings, supplier
 * credentials, or blacklist rules") — every /api/middleware/* route is now
 * gated `admin.role:super_admin` server-side (fixed 2026-08-14, previously
 * also let a regular Admin through — see docs/prd.md §14).
 *
 * Note: the "/middleware" URL segment here refers to this business concept
 * (Supplier Middleware), unrelated to Next.js's proxy/middleware request
 * mechanism (src/proxy.ts) — coincidental naming overlap only.
 *
 * The redirect below is the optimistic/UX-only layer (mirrors
 * admin/users/page.tsx's own pattern) — Laravel's route middleware is the
 * real enforcement layer, not this.
 */
import Link from "next/link";
import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { getClientSession } from "@/lib/session";

// Only pages that actually exist are real links — the rest are still
// TODO (§6.20), same placeholder-until-built discipline as before.
const NAV_ITEMS: { label: string; href: string | null }[] = [
  { label: "Dashboard", href: "/middleware" },
  { label: "Product Manager", href: "/middleware/product-manager" },
  { label: "Payment Methods", href: "/middleware/payment-methods" },
  { label: "Price Sync", href: "/middleware/price-sync" },
  { label: "Validators", href: "/middleware/validators" },
  { label: "Validate Player", href: null },
  { label: "Sandbox", href: "/middleware/sandbox" },
  { label: "Backups", href: "/middleware/backups" },
  { label: "Request Logs", href: null },
  { label: "Developer / API Tester", href: null },
];

export default function MiddlewareLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const router = useRouter();

  useEffect(() => {
    const session = getClientSession();
    if (!session) {
      router.replace("/login");
      return;
    }
    if (session.role !== "super_admin") {
      router.replace("/admin");
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <div className="flex min-h-screen">
      <nav className="w-56 shrink-0 border-r border-black/10 p-4 dark:border-white/15">
        <p className="mb-4 text-sm font-semibold">Middleware Panel</p>
        <ul className="space-y-2 text-sm text-black/70 dark:text-white/70">
          {NAV_ITEMS.map((item) =>
            item.href ? (
              <li key={item.label}>
                <Link href={item.href} className="hover:text-brand-500 dark:hover:text-brand-400">
                  {item.label}
                </Link>
              </li>
            ) : (
              <li key={item.label} className="opacity-50">
                {item.label}
              </li>
            ),
          )}
        </ul>
      </nav>
      <main className="flex-1 p-6">{children}</main>
    </div>
  );
}
