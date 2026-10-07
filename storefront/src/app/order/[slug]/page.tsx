import { Suspense } from "react";
import { notFound } from "next/navigation";
import Link from "next/link";
import type { Metadata, ResolvingMetadata } from "next";
import SiteFooter from "@/components/layout/SiteFooter";
import MemberAwareOrderForm from "@/components/order/MemberAwareOrderForm";
import OrderFormSkeleton from "@/components/skeletons/OrderFormSkeleton";
import ProductHeaderCard from "@/components/order/ProductHeaderCard";
import GameFactLine from "@/components/order/GameFactLine";
import GameReviewsSection from "@/components/order/GameReviewsSection";
import { getGame, getGamePackages } from "@/lib/catalog";
import { listPaymentChannels } from "@/lib/payment-methods";
import { listPlans } from "@/lib/membership";
import { getGameReviews } from "@/lib/review";
import { getBranding } from "@/lib/branding";
import { canonicalMetadata, getSeoSettings, jsonLdHtml } from "@/lib/seo";
import { resolveGameMeta } from "@/lib/seo-meta";

interface OrderPageProps {
  params: Promise<{ slug: string }>;
}

/**
 * ADR-029 decision 1/2/3/18 + ADR-120 decision 4: per-game override
 * (Game.seo_*) wins, then the template, then the storefront default,
 * then a generic line — every one token-rendered against this brand
 * (`resolveGameMeta`). `no_index` (decision 18) sets robots: noindex.
 */
export async function generateMetadata({ params }: OrderPageProps, parent: ResolvingMetadata): Promise<Metadata> {
  const { slug } = await params;
  const [game, settings, branding] = await Promise.all([getGame(slug), getSeoSettings(), getBranding()]);
  if (!game) return { title: `Top Up - ${branding.storeName}` };

  const { title, description } = resolveGameMeta(game, settings, branding.storeName);

  return {
    ...(await canonicalMetadata(`/order/${game.slug}`, parent, game.seoOgImage)),
    title,
    description,
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
  const [game, settings, branding, packages, paymentChannels, membershipPlans, gameReviews] = await Promise.all([
    getGame(slug),
    getSeoSettings(),
    getBranding(),
    getGamePackages(slug),
    listPaymentChannels(),
    listPlans(),
    getGameReviews(slug),
  ]);
  if (!game) notFound();

  const origin = branding.canonicalOrigin;
  const pageUrl = `${origin}/order/${game.slug}`;
  const { description } = resolveGameMeta(game, settings, branding.storeName);

  // ADR-120 decision 9: `offers` from the anonymous `packages` above —
  // the guest Standard price per brand, the same number every card shows
  // and checkout charges a guest (never a member/personalised price).
  const prices = packages.map((pkg) => pkg.priceRm);
  const offers =
    prices.length > 0
      ? {
          "@type": "AggregateOffer",
          priceCurrency: "MYR",
          lowPrice: Math.min(...prices).toFixed(2),
          highPrice: Math.max(...prices).toFixed(2),
          offerCount: prices.length,
          availability: "https://schema.org/InStock",
          url: pageUrl,
        }
      : null;
  // ADR-120 decision 10: only with real, approved, brand-scoped reviews —
  // the same data GameReviewsSection shows on this page.
  const aggregateRating =
    gameReviews.review_count > 0
      ? {
          "@type": "AggregateRating",
          ratingValue: Number(gameReviews.average_rating.toFixed(1)),
          reviewCount: gameReviews.review_count,
          bestRating: 5,
          worstRating: 1,
        }
      : null;

  // ADR-029 addendum decision 12: Product + Breadcrumb JSON-LD, each toggled per reseller_seo_settings.
  const productJsonLd = settings.schema_product_enabled
    ? {
        "@context": "https://schema.org",
        "@type": "Product",
        name: game.name,
        image: game.seoOgImage || game.imageUrl || undefined,
        description,
        ...(game.schemaBrand ? { brand: { "@type": "Brand", name: game.schemaBrand } } : {}),
        ...(game.schemaCategory ? { category: game.schemaCategory } : {}),
        ...(offers ? { offers } : {}),
        ...(aggregateRating ? { aggregateRating } : {}),
      }
    : null;

  const breadcrumbJsonLd = settings.schema_breadcrumb_enabled
    ? {
        "@context": "https://schema.org",
        "@type": "BreadcrumbList",
        itemListElement: [
          { "@type": "ListItem", position: 1, name: "Home", item: origin },
          { "@type": "ListItem", position: 2, name: game.category, item: pageUrl },
          { "@type": "ListItem", position: 3, name: game.name, item: pageUrl },
        ],
      }
    : null;

  return (
    <>
      {productJsonLd && (
        <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLdHtml(productJsonLd) }} />
      )}
      {breadcrumbJsonLd && (
        <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLdHtml(breadcrumbJsonLd) }} />
      )}
      <main className="pb-nav lg:pb-0">
        <div className="mx-auto max-w-[1200px] px-4 pt-5">
          <nav className="mb-4 flex items-center gap-2 font-display text-[11px] font-bold uppercase tracking-wide text-on-surface-variant">
            <Link href="/" className="hover:text-primary-on-surface">
              Home
            </Link>
            <span className="text-on-surface-variant/60">›</span>
            <span>{game.category}</span>
            <span className="text-on-surface-variant/60">›</span>
            <span className="text-primary-on-surface">{game.name}</span>
          </nav>
        </div>

        <div className="mx-auto max-w-[1200px] px-4 pb-10">
          <ProductHeaderCard game={game} />
          <GameFactLine game={game} packages={packages} paymentChannels={paymentChannels} />
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
