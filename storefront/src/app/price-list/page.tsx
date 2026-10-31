import type { Metadata, ResolvingMetadata } from "next";
import { canonicalMetadata } from "@/lib/seo";
import { notFound } from "next/navigation";
import SiteFooter from "@/components/layout/SiteFooter";
import ResellerPriceListBrowser from "@/components/price-list/ResellerPriceListBrowser";
import { getBranding } from "@/lib/branding";
import { getResellerPriceList } from "@/lib/reseller-price-list";

export async function generateMetadata(_props: unknown, parent: ResolvingMetadata): Promise<Metadata> {
  const branding = await getBranding();
  return {
    ...(await canonicalMetadata("/price-list", parent)),
    title: `Reseller Price List - ${branding.storeName}`,
    description: `Real-time wholesale top-up pricing for ${branding.storeName} reseller tiers. See the rates before you sign up.`,
  };
}

/**
 * ADR-091: public Reseller Price List — a sales/acquisition page for
 * prospective resellers, shown only on the primary storefront and any
 * `is_owned` affiliate brand. `getResellerPriceList()` returns
 * `tiers: []` both when this brand isn't `is_owned` and when no tier is
 * admin-marked `show_on_price_list` — either way, no page (same
 * convention `/membership` follows for its own kill switch).
 */
export default async function ResellerPriceListPage() {
  const list = await getResellerPriceList();

  if (list.tiers.length === 0) {
    notFound();
  }

  return (
    <>
      <main className="pb-nav lg:pb-0">
        <div className="mx-auto max-w-[900px] px-4 py-12 lg:py-16">
          <h1 className="font-display text-3xl font-bold uppercase tracking-tight text-on-surface lg:text-headline-lg">
            Reseller Price List
          </h1>
          <p className="mt-3 max-w-[62ch] text-sm leading-relaxed text-on-surface-variant">
            Real-time wholesale pricing for our top-up reseller tiers. Top up your wallet once, order at these
            rates across every eligible game, synced automatically with our live catalog.
          </p>

          <div className="mt-8">
            <ResellerPriceListBrowser list={list} />
          </div>

          <div className="mt-8 flex flex-col items-start justify-between gap-4 rounded-lg border-2 border-ink bg-surface-container-highest p-6 neo sm:flex-row sm:items-center">
            <div>
              <h2 className="font-display text-lg font-bold text-on-surface">Want in on these rates?</h2>
              <p className="mt-1 text-sm text-on-surface-variant">
                Reseller accounts are approved by our team. Apply online and we&apos;ll reply by email with the next step.
              </p>
            </div>
            {list.applyUrl && (
              <a
                href={list.applyUrl}
                className="shrink-0 rounded-lg border-2 border-ink bg-primary px-5 py-2.5 font-display text-sm font-bold text-on-primary neo-hover transition-all"
              >
                Apply to become a reseller
              </a>
            )}
          </div>
        </div>
      </main>
      <SiteFooter />
    </>
  );
}
