import Image from "next/image";
import Link from "next/link";
import { notFound } from "next/navigation";
import type { Metadata, ResolvingMetadata } from "next";
import { Trophy, Clock } from "@phosphor-icons/react/dist/ssr";
import SiteFooter from "@/components/layout/SiteFooter";
import JokiOrderForm from "@/components/joki/JokiOrderForm";
import { getJokiCatalog } from "@/lib/joki";
import { listPaymentChannels } from "@/lib/payment-methods";
import { getBranding } from "@/lib/branding";
import { canonicalMetadata } from "@/lib/seo";
import { resolveWhatsappHref } from "@/lib/whatsapp";

export async function generateMetadata(_props: unknown, parent: ResolvingMetadata): Promise<Metadata> {
  const branding = await getBranding();

  return {
    ...(await canonicalMetadata("/joki", parent, null)),
    title: `Joki Rank MLBB - ${branding.storeName}`,
    description: `Rank up in Mobile Legends with ${branding.storeName}: pick your rank and target, see the price at once, and follow the climb on Track Order.`,
  };
}

/**
 * ADR-126 decisions 1, 2, 12: the joki page. It exists only on a brand
 * that sells joki (both switches, an owned brand) — anywhere else
 * `/api/joki/catalog` refuses and this is a 404, like the hidden menu
 * item. Laid out like the top-up order page (`/order/[slug]`).
 */
export default async function JokiPage() {
  const [catalog, paymentChannels, branding] = await Promise.all([getJokiCatalog(), listPaymentChannels(), getBranding()]);
  if (!catalog) notFound();

  const { game, settings } = catalog;

  return (
    <>
      <main className="pb-nav lg:pb-0">
        <div className="mx-auto max-w-[1200px] px-4 pt-5">
          <nav className="mb-4 flex items-center gap-2 font-display text-[11px] font-bold uppercase tracking-wide text-on-surface-variant">
            <Link href="/" className="hover:text-primary-on-surface">
              Home
            </Link>
            <span className="text-on-surface-variant/60">›</span>
            <span className="text-primary-on-surface">Joki Rank</span>
          </nav>
        </div>

        <div className="mx-auto max-w-[1200px] px-4 pb-10">
          <div className="mb-6 flex items-center gap-5 rounded-lg border-2 border-ink bg-surface-container-lowest p-5 neo">
            <div className="relative flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-md border-2 border-ink bg-surface-variant neo-sm">
              {game.image_url ? (
                <Image src={game.image_url} alt={game.name} fill className="object-cover" sizes="64px" priority />
              ) : (
                <Trophy size={30} weight="fill" className="text-primary-on-surface" />
              )}
            </div>
            <div>
              <h1 className="font-display text-xl font-bold uppercase tracking-tight lg:text-2xl">Joki Rank MLBB</h1>
              <p className="text-[13px] text-on-surface-variant">Our penjoki play your account to the rank you choose.</p>
              <div className="mt-2 flex flex-wrap items-center gap-2.5">
                <span className="inline-flex items-center gap-1.5 rounded-full border border-ink bg-surface-container px-2.5 py-0.5 font-display text-[11px] font-bold uppercase tracking-wide">
                  <Clock size={12} weight="fill" className="text-secondary" />
                  Played by a penjoki
                </span>
                <span className="text-xs text-on-surface-variant">Follow every rank on Track Order.</span>
              </div>
            </div>
          </div>

          <JokiOrderForm catalog={catalog} paymentChannels={paymentChannels} whatsappHref={resolveWhatsappHref(branding)} paymentsEnabled={branding.paymentsEnabled} />

          {settings.terms_html && (
            <section className="mt-8 rounded-lg border-2 border-ink bg-surface-container-lowest p-6 neo">
              <h2 className="font-display text-lg font-bold uppercase tracking-tight">Joki terms</h2>
              {/* Purified on save (the `rich_text` profile), like the legal pages. */}
              <div
                className="mt-3 text-sm leading-relaxed text-on-surface-variant [&_a]:text-primary-on-surface [&_a]:underline [&_li]:my-1 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:my-3 [&_ul]:list-disc [&_ul]:pl-5"
                dangerouslySetInnerHTML={{ __html: settings.terms_html }}
              />
            </section>
          )}
        </div>
      </main>
      <SiteFooter />
    </>
  );
}
