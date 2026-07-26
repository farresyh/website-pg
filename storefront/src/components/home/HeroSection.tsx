import { Check } from "@phosphor-icons/react/dist/ssr";
import HeroSlider from "@/components/home/HeroSlider";
import QuickCounterCard from "@/components/home/QuickCounterCard";
import type { Game } from "@/lib/catalog";

const TRUST_ITEMS = ["3-Minute Delivery", "Xendit-Secured Payments", "24/7 WhatsApp Support"];

export default function HeroSection({ games }: { games: Game[] }) {
  return (
    <section className="mx-auto max-w-[1200px] px-4 py-6 lg:py-8">
      <div className="grid gap-4 lg:grid-cols-[2.2fr_1fr] lg:items-stretch lg:gap-5">
        <HeroSlider />
        <QuickCounterCard games={games} />
      </div>

      <div className="mt-4 flex flex-wrap gap-4 text-[13px] text-text-muted">
        {TRUST_ITEMS.map((item) => (
          <span key={item} className="flex items-center gap-1.5">
            <Check size={14} weight="bold" className="text-brand-light" />
            {item}
          </span>
        ))}
      </div>
    </section>
  );
}
