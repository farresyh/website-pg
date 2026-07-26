import { notFound } from "next/navigation";
import Link from "next/link";
import type { Metadata } from "next";
import AnnouncementBar from "@/components/layout/AnnouncementBar";
import SiteHeader from "@/components/layout/SiteHeader";
import SiteFooter from "@/components/layout/SiteFooter";
import BottomNav from "@/components/layout/BottomNav";
import OrderForm from "@/components/order/OrderForm";
import ProductHeaderCard from "@/components/order/ProductHeaderCard";
import TrustStrip from "@/components/order/TrustStrip";
import { PLACEHOLDER_GAMES, PLACEHOLDER_PACKAGES } from "@/lib/placeholder-data";

interface OrderPageProps {
  params: Promise<{ slug: string }>;
}

export async function generateMetadata({ params }: OrderPageProps): Promise<Metadata> {
  const { slug } = await params;
  const game = PLACEHOLDER_GAMES.find((g) => g.slug === slug);
  return { title: game ? `Top Up ${game.name} — Kedai Runcit Soloz` : "Top Up — Kedai Runcit Soloz" };
}

export default async function OrderPage({ params }: OrderPageProps) {
  const { slug } = await params;
  const game = PLACEHOLDER_GAMES.find((g) => g.slug === slug);
  if (!game) notFound();

  const packages = PLACEHOLDER_PACKAGES[slug] ?? [];

  return (
    <>
      <AnnouncementBar />
      <SiteHeader />
      <main className="pb-16 lg:pb-0">
        <div className="mx-auto max-w-[1200px] px-4 pt-5">
          <nav className="mb-4 flex items-center gap-2 text-[13px] text-text-muted">
            <Link href="/" className="hover:text-text">
              Home
            </Link>
            <span className="text-border">›</span>
            <span>{game.category}</span>
            <span className="text-border">›</span>
            <span className="font-semibold text-brand-light">{game.name}</span>
          </nav>
        </div>

        <div className="mx-auto max-w-[1200px] px-4 pb-10">
          <ProductHeaderCard game={game} />
          <OrderForm game={game} packages={packages} />
        </div>

        <TrustStrip />
      </main>
      <SiteFooter />
      <BottomNav />
    </>
  );
}
