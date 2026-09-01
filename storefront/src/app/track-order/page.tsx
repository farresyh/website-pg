import { Suspense } from "react";
import type { Metadata } from "next";
import AnnouncementBar from "@/components/layout/AnnouncementBar";
import SiteHeader from "@/components/layout/SiteHeader";
import SiteFooter from "@/components/layout/SiteFooter";
import BottomNav from "@/components/layout/BottomNav";
import TrackOrderClient from "@/components/order/TrackOrderClient";

export const metadata: Metadata = {
  title: "Track Order — PekanGame",
};

// SiteFooter now fetches live branding data (ADR-028 addendum) —
// same reasoning as HomePage's own dynamic export: never bake this
// into a static build artifact.
export const dynamic = "force-dynamic";

export default function TrackOrderPage() {
  return (
    <>
      <AnnouncementBar />
      <SiteHeader />
      <main className="pb-16 lg:pb-0">
        <Suspense fallback={<div className="mx-auto max-w-[560px] px-4 py-10 text-sm text-text-muted">Loading…</div>}>
          <TrackOrderClient />
        </Suspense>
      </main>
      <SiteFooter />
      <BottomNav />
    </>
  );
}
