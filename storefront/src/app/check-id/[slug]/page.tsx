import type { Metadata, ResolvingMetadata } from "next";
import { notFound } from "next/navigation";
import SiteFooter from "@/components/layout/SiteFooter";
import CheckIdFaq from "@/components/player-check/CheckIdFaq";
import PlayerCheckClient, { FamilyTiles } from "@/components/player-check/PlayerCheckClient";
import { faqFor, guideFor } from "@/components/player-check/guides";
import { getBranding } from "@/lib/branding";
import { getPlayerCheckFamily, listPlayerCheckFamilies } from "@/lib/player-check";
import { canonicalMetadata } from "@/lib/seo";

interface CheckIdFamilyPageProps {
  params: Promise<{ slug: string }>;
}

export async function generateMetadata({ params }: CheckIdFamilyPageProps, parent: ResolvingMetadata): Promise<Metadata> {
  const { slug } = await params;
  const [branding, found] = await Promise.all([getBranding(), getPlayerCheckFamily(slug)]);
  if (!found) return { title: `Game ID Checker - ${branding.storeName}` };

  const { family, indexable } = found;
  const idWord = guideFor(family.key).idLabel;

  return {
    ...(await canonicalMetadata(`/check-id/${family.slug}`, parent, family.image_url)),
    title: `Check ${family.name} ${idWord}: Nickname & Region - ${branding.storeName}`,
    description: `Free ${family.name} ${idWord} checker. Enter your ${idWord} to see the account's nickname and the right ${branding.storeName} store before you top up.`,
    robots: indexable ? undefined : { index: false, follow: true },
  };
}

/** ADR-123: one game family's checker, with how to find the ID and a FAQ. */
export default async function CheckIdFamilyPage({ params }: CheckIdFamilyPageProps) {
  const { slug } = await params;
  const [branding, found, { families }] = await Promise.all([getBranding(), getPlayerCheckFamily(slug), listPlayerCheckFamilies()]);

  if (!found) {
    notFound();
  }

  const { family } = found;
  const guide = guideFor(family.key);

  return (
    <>
      <main className="pb-nav lg:pb-0">
        <div className="mx-auto max-w-[760px] px-4 py-12 lg:py-16">
          <h1 className="font-display text-3xl font-bold uppercase tracking-tight text-on-surface lg:text-headline-lg">
            {family.name} ID Checker
          </h1>
          <p className="mt-3 max-w-[62ch] text-sm leading-relaxed text-on-surface-variant">
            Enter your {family.name} {guide.idLabel}
            {family.requires_server_id ? " and Server ID" : ""} to see the account&apos;s nickname and the store that
            tops it up. Free, no login.
          </p>

          {families.length > 1 && (
            <div className="mt-6">
              <FamilyTiles families={families} activeSlug={family.slug} />
            </div>
          )}

          <div className="mt-6">
            <PlayerCheckClient key={family.slug} family={family} storeName={branding.storeName} />
          </div>

          <section className="mt-12">
            <h2 className="font-display text-xl font-bold uppercase tracking-tight text-on-surface">
              How to find your {family.name} {guide.idLabel}
            </h2>
            <ol className="mt-4 list-decimal space-y-2 pl-5 text-sm leading-relaxed text-on-surface-variant">
              {guide.findSteps.map((step) => (
                <li key={step}>{step}</li>
              ))}
            </ol>
          </section>

          <CheckIdFaq items={faqFor(family, branding.storeName)} />
        </div>
      </main>
      <SiteFooter />
    </>
  );
}
