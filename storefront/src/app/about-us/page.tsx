import type { Metadata, ResolvingMetadata } from "next";
import { canonicalMetadata } from "@/lib/seo";
import SiteFooter from "@/components/layout/SiteFooter";
import LegalPageContent from "@/components/legal/LegalPageContent";
import { getBranding, getLegalContent } from "@/lib/branding";

export async function generateMetadata(_props: unknown, parent: ResolvingMetadata): Promise<Metadata> {
  const branding = await getBranding();
  return {
    ...(await canonicalMetadata("/about-us", parent)),
    title: `About Us - ${branding.storeName}`,
  };
}

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
