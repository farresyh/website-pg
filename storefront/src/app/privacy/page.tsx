import type { Metadata } from "next";
import SiteFooter from "@/components/layout/SiteFooter";
import LegalPageContent from "@/components/legal/LegalPageContent";
import { getLegalContent } from "@/lib/branding";

export const metadata: Metadata = {
  title: "Privacy Policy — PekanGame",
};

// ADR-071 PR1: admin-editable content, cached in Next's Data Cache
// (`catalogCache`) with a `safeRead` fallback — no longer `force-dynamic`.

export default async function PrivacyPage() {
  const content = await getLegalContent("privacy");

  return (
    <>
      <main className="pb-nav lg:pb-0">
        <LegalPageContent title="Privacy Policy" content={content} />
      </main>
      <SiteFooter />
    </>
  );
}
