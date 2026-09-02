import type { Metadata } from "next";
import SiteHeader from "@/components/layout/SiteHeader";
import SiteFooter from "@/components/layout/SiteFooter";
import BottomNav from "@/components/layout/BottomNav";
import OrderStatusTracker from "@/components/order/OrderStatusTracker";
import FaqSection from "@/components/home/FaqSection";

interface OrderStatusPageProps {
  params: Promise<{ orderNumber: string }>;
}

export async function generateMetadata({ params }: OrderStatusPageProps): Promise<Metadata> {
  const { orderNumber } = await params;
  return { title: `Order ${orderNumber} — PekanGame` };
}

/**
 * Reused by both the post-checkout redirect (OrderForm, right after a
 * real payment) and by /track-order's search flow — one tracker
 * component, two entry points, per the founder's own steer.
 */
export default async function OrderStatusPage({ params }: OrderStatusPageProps) {
  const { orderNumber } = await params;

  return (
    <>
      <SiteHeader />
      <main className="pb-10 lg:pb-0">
        <div className="mx-auto max-w-[1200px] px-4 py-8">
          <h1 className="font-display mb-6 text-3xl font-bold uppercase lg:text-headline-lg tracking-tight">Order Status</h1>
          <OrderStatusTracker orderNumber={orderNumber} />
        </div>
        <div className="mx-auto max-w-[1200px] px-4">
          <FaqSection />
        </div>
      </main>
      <SiteFooter />
      <BottomNav />
    </>
  );
}
