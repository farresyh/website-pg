import type { Metadata } from "next";
import SiteFooter from "@/components/layout/SiteFooter";
import OrderStatusTracker from "@/components/order/OrderStatusTracker";
import FaqSection from "@/components/home/FaqSection";
import { getBranding } from "@/lib/branding";

interface OrderStatusPageProps {
  params: Promise<{ orderNumber: string }>;
}

export async function generateMetadata({ params }: OrderStatusPageProps): Promise<Metadata> {
  const [{ orderNumber }, branding] = await Promise.all([params, getBranding()]);
  return { title: `Order ${orderNumber} — ${branding.storeName}` };
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
      <main className="pb-nav lg:pb-0">
        <div className="mx-auto max-w-[1200px] px-4 py-8">
          <h1 className="font-display mb-6 text-3xl font-bold uppercase lg:text-headline-lg tracking-tight">Order Status</h1>
          <OrderStatusTracker orderNumber={orderNumber} />
        </div>
        <div className="mx-auto max-w-[1200px] px-4">
          <FaqSection />
        </div>
      </main>
      <SiteFooter />
    </>
  );
}
