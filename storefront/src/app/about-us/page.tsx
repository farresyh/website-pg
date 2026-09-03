import type { Metadata } from "next";
import SiteFooter from "@/components/layout/SiteFooter";
import LegalPageContent from "@/components/legal/LegalPageContent";
import { getLegalContent } from "@/lib/branding";

export const metadata: Metadata = {
  title: "About Us — PekanGame",
};

// ADR-071 PR1: admin-editable content, cached in Next's Data Cache
// (`catalogCache`) with a `safeRead` fallback — no longer `force-dynamic`.

export default async function AboutUsPage() {
  const content = await getLegalContent("about-us");

  return (
    <>
      <main className="pb-nav lg:pb-0">
        <LegalPageContent title="About Us" content={content} />
      </main>
      <SiteFooter />
    </>
  );
}
