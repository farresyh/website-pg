import type { Metadata } from "next";
import SiteHeader from "@/components/layout/SiteHeader";
import SiteFooter from "@/components/layout/SiteFooter";
import BottomNav from "@/components/layout/BottomNav";
import LegalPageContent from "@/components/legal/LegalPageContent";
import { getLegalContent } from "@/lib/branding";

export const metadata: Metadata = {
  title: "Privacy Policy — PekanGame",
};

// Admin-editable (ADR-028 addendum) — never bake into a static build.
export const dynamic = "force-dynamic";

export default async function PrivacyPage() {
  const content = await getLegalContent("privacy");

  return (
    <>
      <SiteHeader />
      <main className="pb-16 lg:pb-0">
        <LegalPageContent title="Privacy Policy" content={content} />
      </main>
      <SiteFooter />
      <BottomNav />
    </>
  );
}
