import type { Metadata } from "next";
import SiteHeader from "@/components/layout/SiteHeader";
import SiteFooter from "@/components/layout/SiteFooter";
import BottomNav from "@/components/layout/BottomNav";
import LegalPageContent from "@/components/legal/LegalPageContent";
import { getLegalContent } from "@/lib/branding";

export const metadata: Metadata = {
  title: "Terms & Conditions — PekanGame",
};

// Admin-editable (ADR-028 addendum) — never bake into a static build.
export const dynamic = "force-dynamic";

export default async function TermsPage() {
  const content = await getLegalContent("terms");

  return (
    <>
      <SiteHeader />
      <main className="pb-10 lg:pb-0">
        <LegalPageContent title="Terms & Conditions" content={content} />
      </main>
      <SiteFooter />
      <BottomNav />
    </>
  );
}
