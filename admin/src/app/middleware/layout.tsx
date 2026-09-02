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
 * Shares the exact chrome (PanelShell + PanelSidebar) as /admin — the
 * drawer, header toggle and collapse behavior are identical (founder
 * feedback, 2026-09-02: middleware previously had a static hand-rolled
 * nav with no mobile drawer and its own styling).
 *
 * The redirect below is the optimistic/UX-only layer (mirrors
 * admin/users/page.tsx's own pattern) — Laravel's route middleware is the
 * real enforcement layer, not this.
 */
import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { getClientSession } from "@/lib/session";
import MiddlewareSidebar from "@/layout/MiddlewareSidebar";
import PanelShell from "@/layout/PanelShell";

export default function MiddlewareLayout({ children }: { children: React.ReactNode }) {
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
    <PanelShell sidebar={<MiddlewareSidebar />} headerTitle="Middleware Panel">
      {children}
    </PanelShell>
  );
}
