import { Suspense } from "react";
import type { Metadata } from "next";
import SiteFooter from "@/components/layout/SiteFooter";
import TrackOrderClient from "@/components/order/TrackOrderClient";
import { getBranding } from "@/lib/branding";

export async function generateMetadata(): Promise<Metadata> {
  const branding = await getBranding();
  return {
    title: `Track Order — ${branding.storeName}`,
  };
}

// ADR-071 PR1: no `force-dynamic`. The lookup itself is a `"use
// client"` component (TrackOrderClient); SiteFooter's branding read is
// cached (`catalogCache`) with a `safeRead` fallback.

export default function TrackOrderPage() {
  return (
    <>
      <main className="pb-nav lg:pb-0">
        <Suspense fallback={<div className="mx-auto max-w-[560px] px-4 py-10 text-sm text-on-surface-variant">Loading…</div>}>
          <TrackOrderClient />
        </Suspense>
      </main>
      <SiteFooter />
    </>
  );
}
