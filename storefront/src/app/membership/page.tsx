import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import SiteFooter from "@/components/layout/SiteFooter";
import MembershipClient from "@/components/order/MembershipClient";
import { getBranding } from "@/lib/branding";
import { listPlans } from "@/lib/membership";

export async function generateMetadata(): Promise<Metadata> {
  const branding = await getBranding();
  return {
    title: `Membership - ${branding.storeName}`,
  };
}

// ADR-071 PR1: no `force-dynamic`. The per-member state (verified
// session, dashboard data) lives entirely in the `"use client"`
// MembershipClient below — the page itself is a static shell.

export default async function MembershipPage() {
  // ADR-080 decision 3: on a brand where Membership is not enabled
  // (`membershipEnabledEffective()` false — the brand's own toggle or
  // the global kill switch), the whole `/membership` surface is closed.
  // `listPlans()` returns `[]` in that case; the layout has already
  // called it this request, so this is a cache hit, not a round-trip.
  // The backend `/api/membership/me` stays reachable for an existing
  // member's own read-only view — this only removes the storefront page.
  const plans = await listPlans();
  if (plans.length === 0) notFound();

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
