import type { Metadata } from "next";
import { Suspense } from "react";
import SiteFooter from "@/components/layout/SiteFooter";
import MembershipClient from "@/components/order/MembershipClient";
import { getBranding } from "@/lib/branding";

export async function generateMetadata(): Promise<Metadata> {
  const branding = await getBranding();
  return {
    title: `Membership — ${branding.storeName}`,
  };
}

// ADR-071 PR1: no `force-dynamic`. The per-member state (verified
// session, dashboard data) lives entirely in the `"use client"`
// MembershipClient below — the page itself is a static shell.

export default function MembershipPage() {
  return (
    <>
      <main className="pb-nav lg:pb-0">
        <Suspense
          fallback={<p className="mx-auto max-w-[560px] px-4 py-10 text-sm text-on-surface-variant">Loading…</p>}
        >
          <MembershipClient />
        </Suspense>
      </main>
      <SiteFooter />
    </>
  );
}
