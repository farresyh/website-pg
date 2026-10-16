import type { Metadata, ResolvingMetadata } from "next";
import { notFound } from "next/navigation";
import SiteFooter from "@/components/layout/SiteFooter";
import CheckIdFaq from "@/components/player-check/CheckIdFaq";
import { FamilyCards } from "@/components/player-check/PlayerCheckClient";
import { faqFor } from "@/components/player-check/guides";
import { getBranding } from "@/lib/branding";
import { listPlayerCheckFamilies } from "@/lib/player-check";
import { canonicalMetadata } from "@/lib/seo";

export async function generateMetadata(_props: unknown, parent: ResolvingMetadata): Promise<Metadata> {
  const [branding, { indexable }] = await Promise.all([getBranding(), listPlayerCheckFamilies()]);
  return {
    ...(await canonicalMetadata("/check-id", parent)),
    title: `Game ID Checker: Find Your Nickname & Region - ${branding.storeName}`,
    description: `Check a game ID for free on ${branding.storeName}. See the account's nickname and the right store to top up before you pay.`,
    // ADR-123 decision 10: an affiliate brand's checker works but isn't indexed.
    robots: indexable ? undefined : { index: false, follow: true },
  };
}

/** ADR-123: pick a game family; each tile is its own indexable page. */
export default async function CheckIdPage() {
  const [branding, { families }] = await Promise.all([getBranding(), listPlayerCheckFamilies()]);

  if (families.length === 0) {
    notFound();
  }

  return (
    <>
      <main className="pb-nav lg:pb-0">
        <div className="mx-auto max-w-[1200px] px-4 py-12 lg:py-16">
          <h1 className="font-display text-3xl font-bold uppercase tracking-tight text-on-surface lg:text-headline-lg">
            Game ID Checker
          </h1>
          <p className="mt-3 max-w-[62ch] text-sm leading-relaxed text-on-surface-variant">
            Check an account before you top up. Pick your game, enter the ID, and we&apos;ll show the nickname and the
            store that tops up that account. Free, no login.
          </p>

          <div className="mt-8">
            <FamilyCards families={families} />
          </div>

          <div className="max-w-[760px]">
            <CheckIdFaq items={faqFor(null, branding.storeName)} />
          </div>
        </div>
      </main>
      <SiteFooter />
    </>
  );
}
