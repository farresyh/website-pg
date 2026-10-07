import type { Metadata, ResolvingMetadata } from "next";
import SiteFooter from "@/components/layout/SiteFooter";
import HeroSection from "@/components/home/HeroSection";
import PopularPicksSection from "@/components/home/PopularPicksSection";
import NewArrivalsSection from "@/components/home/NewArrivalsSection";
import WhyChooseUsSection from "@/components/home/WhyChooseUsSection";
import PaymentMethodsSection from "@/components/home/PaymentMethodsSection";
import TestimonialsSection from "@/components/home/TestimonialsSection";
import FaqSection from "@/components/home/FaqSection";
import SeoBlurb from "@/components/home/SeoBlurb";
import { listGames } from "@/lib/catalog";
import { listHeroSlides } from "@/lib/hero-slides";
import { listPaymentChannels } from "@/lib/payment-methods";
import { listApprovedReviews } from "@/lib/review";
import { canonicalMetadata, jsonLdHtml } from "@/lib/seo";
import { listFaqs } from "@/lib/faq";

// ADR-071 PR1: `force-dynamic` removed — catalog/hero reads are cached
// in Next's Data Cache (`catalogCache`), so this page is served from
// the edge and revalidated (60s interim TTL, tag-purged on an admin
// catalog save by the PR2 webhook). Prices displayed here can lag by
// that window; the payable total is always recomputed at checkout
// (ORD-9), so a stale display is never a mischarge.

/** ADR-120 decision 3: title/description inherit the layout's site meta. */
export async function generateMetadata(_props: unknown, parent: ResolvingMetadata): Promise<Metadata> {
  return canonicalMetadata("/", parent);
}

export default async function HomePage() {
  const [games, slides, paymentChannels, reviews, faqs] = await Promise.all([
    listGames(),
    listHeroSlides(),
    listPaymentChannels(),
    listApprovedReviews(),
    listFaqs(),
  ]);

  // ADR-120 decision 13: the same items FaqSection renders, so the
  // structured data always matches visible text.
  const faqJsonLd =
    faqs.length > 0
      ? {
          "@context": "https://schema.org",
          "@type": "FAQPage",
          mainEntity: faqs.map((faq) => ({
            "@type": "Question",
            name: faq.question,
            acceptedAnswer: { "@type": "Answer", text: faq.answer },
          })),
        }
      : null;

  return (
    <>
      {faqJsonLd && <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLdHtml(faqJsonLd) }} />}
      <main className="pb-nav lg:pb-0">
        <HeroSection games={games} slides={slides} />
        <PopularPicksSection games={games} />
        <NewArrivalsSection games={games} />
        <WhyChooseUsSection />
        <PaymentMethodsSection channels={paymentChannels} />
        <TestimonialsSection reviews={reviews} />
        <FaqSection items={faqs} />
        <SeoBlurb />
      </main>
      <SiteFooter />
    </>
  );
}
