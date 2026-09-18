import { CheckCircle } from "@phosphor-icons/react/dist/ssr";
import HeroSlider from "@/components/home/HeroSlider";
import QuickCounterCard from "@/components/home/QuickCounterCard";
import type { Game } from "@/lib/catalog";
import type { HeroSlide } from "@/lib/hero-slides";

const TRUST_ITEMS = ["3-Minute Delivery", "Secure Online Payments", "24/7 WhatsApp Support"];

export default function HeroSection({ games, slides }: { games: Game[]; slides: HeroSlide[] }) {
  return (
    <section className="mx-auto max-w-[1200px] px-4 py-8 lg:py-10">
      <div className="grid gap-5 lg:grid-cols-[2fr_1fr] lg:items-stretch">
        <HeroSlider slides={slides} />
        <QuickCounterCard games={games} />
      </div>

      <div className="mt-6 flex flex-wrap gap-x-6 gap-y-2 text-[13px] font-semibold text-on-surface-variant">
        {TRUST_ITEMS.map((item) => (
          <span key={item} className="flex items-center gap-1.5">
            <CheckCircle size={16} weight="fill" className="text-secondary" />
            {item}
          </span>
        ))}
      </div>
    </section>
  );
}
