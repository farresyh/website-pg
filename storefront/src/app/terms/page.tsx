import type { Metadata } from "next";
import SiteFooter from "@/components/layout/SiteFooter";
import LegalPageContent from "@/components/legal/LegalPageContent";
import { getBranding, getLegalContent } from "@/lib/branding";

export async function generateMetadata(): Promise<Metadata> {
  const branding = await getBranding();
  return {
    title: `Terms & Conditions — ${branding.storeName}`,
  };
}

// ADR-071 PR1: admin-editable content, cached in Next's Data Cache
// (`catalogCache`) with a `safeRead` fallback — no longer `force-dynamic`.

export default async function TermsPage() {
  const content = await getLegalContent("terms");

  return (
    <>
      <main className="pb-nav lg:pb-0">
        <LegalPageContent title="Terms & Conditions" content={content} />
      </main>
      <SiteFooter />
    </>
  );
}
