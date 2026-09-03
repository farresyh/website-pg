import type { Metadata } from "next";
import { Suspense } from "react";
import SiteHeader from "@/components/layout/SiteHeader";
import SiteFooter from "@/components/layout/SiteFooter";
import BottomNav from "@/components/layout/BottomNav";
import MembershipClient from "@/components/order/MembershipClient";

export const metadata: Metadata = {
  title: "Membership — PekanGame",
};

// Same reasoning as track-order/page.tsx's own export — this page's
// state (verified session, dashboard data) must never be baked into a
// static build artifact.
export const dynamic = "force-dynamic";

export default function MembershipPage() {
  return (
    <>
      <SiteHeader />
      <main className="pb-10 lg:pb-0">
        <Suspense
          fallback={<p className="mx-auto max-w-[560px] px-4 py-10 text-sm text-on-surface-variant">Loading…</p>}
        >
          <MembershipClient />
        </Suspense>
      </main>
      <SiteFooter />
      <BottomNav />
    </>
  );
}
