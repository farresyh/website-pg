import Link from "next/link";
import { getBranding } from "@/lib/branding";

/**
 * On-page SEO keyword copy (ADR-071 PR0 fixed its dead "Read More" link).
 * An async Server Component fetching its own branding — same pattern as
 * SiteFooter/SiteHeader — so the copy substitutes the host-resolved
 * `store_name` (ADR-060) instead of a hardcoded "PekanGame", which
 * previously rendered unchanged on every affiliate whitelabel storefront.
 */
export default async function SeoBlurb() {
  const branding = await getBranding();

  return (
    <section className="mx-auto max-w-[1200px] px-4 py-9 lg:py-12">
      <div className="rounded-lg border-2 border-ink bg-surface-container-low p-6 neo">
        <h2 className="mb-3 font-display text-headline-md tracking-tight">
          Top Up Games in Malaysia at {branding.storeName}
        </h2>
        <p className="max-w-[900px] text-[13px] leading-relaxed text-on-surface-variant">
          {branding.storeName} offers the fastest top-up platform for gaming fans across Malaysia. Get the best
          prices for all your favorite games.{" "}
          <Link href="/about-us" className="font-bold text-primary underline underline-offset-2">
            Read More ›
          </Link>
        </p>
      </div>
    </section>
  );
}
