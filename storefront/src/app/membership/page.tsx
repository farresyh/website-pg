import type { Metadata } from "next";
import AnnouncementBar from "@/components/layout/AnnouncementBar";
import SiteHeader from "@/components/layout/SiteHeader";
import SiteFooter from "@/components/layout/SiteFooter";
import BottomNav from "@/components/layout/BottomNav";
import MembershipClient from "@/components/order/MembershipClient";

export const metadata: Metadata = {
  title: "Membership — Kedai Runcit Soloz",
};

// Same reasoning as track-order/page.tsx's own export — this page's
// state (verified session, dashboard data) must never be baked into a
// static build artifact.
export const dynamic = "force-dynamic";

export default function MembershipPage() {
  return (
    <>
      <AnnouncementBar />
      <SiteHeader />
      <main className="pb-16 lg:pb-0">
        <MembershipClient />
      </main>
      <SiteFooter />
      <BottomNav />
    </>
  );
}
