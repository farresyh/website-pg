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

// ADR-071 PR1: `force-dynamic` removed — catalog/hero reads are cached
// in Next's Data Cache (`catalogCache`), so this page is served from
// the edge and revalidated (60s interim TTL, tag-purged on an admin
// catalog save by the PR2 webhook). Prices displayed here can lag by
// that window; the payable total is always recomputed at checkout
// (ORD-9), so a stale display is never a mischarge.

export default async function HomePage() {
  const [games, slides] = await Promise.all([listGames(), listHeroSlides()]);

  return (
    <>
      <main className="pb-nav lg:pb-0">
        <HeroSection games={games} slides={slides} />
        <PopularPicksSection games={games} />
        <NewArrivalsSection games={games} />
        <WhyChooseUsSection />
        <PaymentMethodsSection />
        <TestimonialsSection />
        <FaqSection />
        <SeoBlurb />
      </main>
      <SiteFooter />
    </>
  );
}
