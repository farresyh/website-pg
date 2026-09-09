import { Suspense } from "react";
import { notFound } from "next/navigation";
import Link from "next/link";
import type { Metadata } from "next";
import SiteFooter from "@/components/layout/SiteFooter";
import MemberAwareOrderForm from "@/components/order/MemberAwareOrderForm";
import OrderFormSkeleton from "@/components/skeletons/OrderFormSkeleton";
import ProductHeaderCard from "@/components/order/ProductHeaderCard";
import GameReviewsSection from "@/components/order/GameReviewsSection";
import { getGame, getGamePackages } from "@/lib/catalog";
import { listPaymentChannels } from "@/lib/payment-methods";
import { listPlans } from "@/lib/membership";
import { getGameReviews } from "@/lib/review";
import { getBranding } from "@/lib/branding";
import { getSeoSettings, renderTemplate } from "@/lib/seo";
import { SITE_URL } from "@/lib/site";

interface OrderPageProps {
  params: Promise<{ slug: string }>;
}

/**
 * ADR-029 decision 1/2/3/8/18: per-game override (Game.seo_*) wins,
 * then the template pattern substituted with {game_name}/{store_name},
 * then the storefront-wide default, then this file's own original
 * hardcoded fallback. `no_index` (decision 18) sets robots: noindex.
 */
export async function generateMetadata({ params }: OrderPageProps): Promise<Metadata> {
  const { slug } = await params;
  const [game, settings, branding] = await Promise.all([getGame(slug), getSeoSettings(), getBranding()]);
  if (!game) return { title: "Top Up — PekanGame" };

  const tokens = { game_name: game.name, store_name: branding.storeName };
  const templatedTitle = settings.meta_title_template ? renderTemplate(settings.meta_title_template, tokens) : null;
  const templatedDescription = settings.meta_description_template
    ? renderTemplate(settings.meta_description_template, tokens)
    : null;

  return {
    title: game.seoTitle || templatedTitle || settings.default_meta_title || `Top Up ${game.name} — PekanGame`,
    description: game.seoDescription || templatedDescription || settings.default_meta_description || undefined,
    openGraph: (game.seoOgImage || settings.default_og_image)
      ? { images: [{ url: (game.seoOgImage || settings.default_og_image) as string }] }
      : undefined,
    robots: game.noIndex ? { index: false, follow: false } : undefined,
  };
}

export default async function OrderPage({ params }: OrderPageProps) {
  const { slug } = await params;
  // ADR-071 PR1 decision 4: one parallel wave, not the old four serial
  // awaits (getGame+seo, then packages, then channels, then plans) —
  // none of these depend on another's result. All five reads are
  // cached (`catalogCache`); `getGamePackages` here is the anonymous
  // SSR variant (OrderForm re-fetches personalized once a membership
  // token is known). ADR-055 decision 7: plans server-side, not a
  // client round trip — `[]` when the membership kill switch is off.
  const [game, settings, packages, paymentChannels, membershipPlans, gameReviews] = await Promise.all([
    getGame(slug),
    getSeoSettings(),
    getGamePackages(slug),
    listPaymentChannels(),
    listPlans(),
    getGameReviews(slug),
  ]);
  if (!game) notFound();

  // ADR-029 addendum decision 12: Product + Breadcrumb JSON-LD, each toggled per reseller_seo_settings.
  const productJsonLd = settings.schema_product_enabled
    ? {
        "@context": "https://schema.org",
        "@type": "Product",
        name: game.name,
        image: game.seoOgImage || game.imageUrl || undefined,
        description: game.seoDescription || undefined,
        ...(game.schemaBrand ? { brand: { "@type": "Brand", name: game.schemaBrand } } : {}),
        ...(game.schemaCategory ? { category: game.schemaCategory } : {}),
      }
    : null;

  const breadcrumbJsonLd = settings.schema_breadcrumb_enabled
    ? {
        "@context": "https://schema.org",
        "@type": "BreadcrumbList",
        itemListElement: [
          { "@type": "ListItem", position: 1, name: "Home", item: SITE_URL },
          { "@type": "ListItem", position: 2, name: game.category, item: `${SITE_URL}/order/${game.slug}` },
          { "@type": "ListItem", position: 3, name: game.name, item: `${SITE_URL}/order/${game.slug}` },
        ],
      }
    : null;

  return (
    <>
      {productJsonLd && (
        <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(productJsonLd) }} />
      )}
      {breadcrumbJsonLd && (
        <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumbJsonLd) }} />
      )}
      <main className="pb-nav lg:pb-0">
        <div className="mx-auto max-w-[1200px] px-4 pt-5">
          <nav className="mb-4 flex items-center gap-2 font-display text-[11px] font-bold uppercase tracking-wide text-on-surface-variant">
            <Link href="/" className="hover:text-primary">
              Home
            </Link>
            <span className="text-ink/40">›</span>
            <span>{game.category}</span>
            <span className="text-ink/40">›</span>
            <span className="text-primary">{game.name}</span>
          </nav>
        </div>

        <div className="mx-auto max-w-[1200px] px-4 pb-10">
          <ProductHeaderCard game={game} />
          {/* ADR-071 PR2b — the membership-cookie read lives inside this
            * Suspense child (MemberAwareOrderForm), so it never blocks
            * the route's own `loading.tsx`. A guest resolves instantly;
            * a signed-in member sees this skeleton for the ~300–500ms it
            * takes to resolve their personalized pricing server-side. */}
          <Suspense fallback={<OrderFormSkeleton />}>
            <MemberAwareOrderForm
              game={game}
              packages={packages}
              paymentChannels={paymentChannels}
              membershipPlans={membershipPlans}
            />
          </Suspense>
        </div>

        <GameReviewsSection gameName={game.name} data={gameReviews} />
      </main>
      <SiteFooter />
    </>
  );
}
