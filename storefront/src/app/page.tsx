import SiteHeader from "@/components/layout/SiteHeader";
import SiteFooter from "@/components/layout/SiteFooter";
import BottomNav from "@/components/layout/BottomNav";
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

// Catalog/hero-slide data is live/mutable (admin toggles games/
// packages/slides, prices and schedules change) and already cached
// server-side (ADR-014, 60s TTL) — this page must never be baked into
// a static build artifact at `next build` time, only rendered per-request.
export const dynamic = "force-dynamic";

export default async function HomePage() {
  const [games, slides] = await Promise.all([listGames(), listHeroSlides()]);

  return (
    <>
      <SiteHeader />
      <main className="pb-10 lg:pb-0">
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
      <BottomNav />
    </>
  );
}
