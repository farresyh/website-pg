import AnnouncementBar from "@/components/layout/AnnouncementBar";
import SiteHeader from "@/components/layout/SiteHeader";
import SiteFooter from "@/components/layout/SiteFooter";
import BottomNav from "@/components/layout/BottomNav";
import HeroSection from "@/components/home/HeroSection";
import PopularPicksSection from "@/components/home/PopularPicksSection";
import PromotionsSection from "@/components/home/PromotionsSection";
import NewArrivalsSection from "@/components/home/NewArrivalsSection";
import WhyChooseUsSection from "@/components/home/WhyChooseUsSection";
import PaymentMethodsSection from "@/components/home/PaymentMethodsSection";
import TestimonialsSection from "@/components/home/TestimonialsSection";
import FaqSection from "@/components/home/FaqSection";
import SeoBlurb from "@/components/home/SeoBlurb";

export default function HomePage() {
  return (
    <>
      <AnnouncementBar />
      <SiteHeader />
      <main className="pb-16 lg:pb-0">
        <HeroSection />
        <PopularPicksSection />
        <PromotionsSection />
        <NewArrivalsSection />
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
