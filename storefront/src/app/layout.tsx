import type { Metadata } from "next";
import { Space_Grotesk, Inter, JetBrains_Mono } from "next/font/google";
import Script from "next/script";
import { GoogleAnalytics } from "@next/third-parties/google";
import { SpeedInsights } from "@vercel/speed-insights/next";
import "./globals.css";
import WebVitals from "@/components/WebVitals";
import { SearchProvider } from "@/context/SearchContext";
import { SiteConfigProvider } from "@/context/SiteConfigContext";
import SiteHeader from "@/components/layout/SiteHeader";
import BottomNav from "@/components/layout/BottomNav";
import { getBranding } from "@/lib/branding";
import { listPlans } from "@/lib/membership";
import { getSeoSettings, getSeoScripts } from "@/lib/seo";
import { SITE_URL } from "@/lib/site";

// ADR-071 PR1: `force-dynamic` removed. Branding/SEO reads now go
// through Next's Data Cache (`lib/cache.ts` `catalogCache` — 60s
// interim TTL + `catalog` tag) and each has a `safeRead` fallback, so
// the build-time `/_not-found` prerender through this layout no longer
// needs a reachable backend. Removing it is also what lets a route's
// `loading.tsx` fallback and RSC prefetch work at all — an uncached
// `await` in this layout blocks both.

// ADR-063: Space Grotesk (display/headings/CTA) + Inter (body) +
// JetBrains Mono (prices/order numbers/player IDs).
const spaceGrotesk = Space_Grotesk({
  subsets: ["latin"],
  weight: ["400", "500", "600", "700"],
  variable: "--font-space-grotesk",
});

const inter = Inter({
  subsets: ["latin"],
  weight: ["400", "500", "600", "700"],
  variable: "--font-inter",
});

const jetbrainsMono = JetBrains_Mono({
  subsets: ["latin"],
  weight: ["500", "700"],
  variable: "--font-jetbrains-mono",
});

/**
 * ADR-029 decision 2/5: storefront-wide default meta, falling back to
 * the previous hardcoded copy when no reseller_seo_settings row (or
 * empty field) exists yet.
 */
export async function generateMetadata(): Promise<Metadata> {
  const settings = await getSeoSettings();

  return {
    title: settings.default_meta_title || "PekanGame — Top Up Games in Malaysia",
    description: settings.default_meta_description || "Fast, secure game top-ups. Delivered in 3 minutes.",
    openGraph: settings.default_og_image ? { images: [{ url: settings.default_og_image }] } : undefined,
  };
}

export default async function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  const [branding, settings, scripts, plans] = await Promise.all([
    getBranding(),
    getSeoSettings(),
    getSeoScripts(),
    listPlans(),
  ]);
  const membershipEnabled = plans.length > 0;

  const headScripts = scripts.filter((s) => s.location === "head").sort((a, b) => a.priority - b.priority);
  const bodyEndScripts = scripts.filter((s) => s.location === "body_end").sort((a, b) => a.priority - b.priority);

  // ADR-029 addendum decision 12: Organization JSON-LD, toggled per
  // reseller_seo_settings.schema_organization_enabled.
  const organizationJsonLd = settings.schema_organization_enabled
    ? {
        "@context": "https://schema.org",
        "@type": "Organization",
        name: branding.storeName,
        url: SITE_URL,
        ...(branding.supportEmail ? { email: branding.supportEmail } : {}),
      }
    : null;

  return (
    <html lang="en" className={`${spaceGrotesk.variable} ${inter.variable} ${jetbrainsMono.variable}`}>
      <head>
        {organizationJsonLd && (
          <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(organizationJsonLd) }} />
        )}
        {/* ADR-029 addendum decision 13: admin-authored head scripts, injected verbatim in priority order. */}
        {headScripts.map((s, i) => (
          <Script key={`head-script-${i}`} id={`seo-head-script-${i}`} strategy="afterInteractive" dangerouslySetInnerHTML={{ __html: s.code }} />
        ))}
        {/* SEO-6/decision 14's FB Pixel + TikTok Pixel — next/script, not @next/third-parties (no official package exists for either).
          * ADR-060 PR-6: the pixel id is now an affiliate-editable field
          * (charset-gated server-side to `[A-Za-z0-9._-]`), and it lands
          * inside this inline script — JSON.stringify it rather than raw
          * `'${id}'` interpolation so a stray character can never break
          * out of the string literal. */}
        {settings.fb_pixel_id && (
          <Script id="fb-pixel" strategy="afterInteractive">
            {`
              !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
              fbq('init', ${JSON.stringify(settings.fb_pixel_id)});
              fbq('track', 'PageView');
            `}
          </Script>
        )}
        {settings.tiktok_pixel_id && (
          <Script id="tiktok-pixel" strategy="afterInteractive">
            {`
              !function (w, d, t) {
                w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie","holdConsent","revokeConsent","grantConsent"],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var i="https://analytics.tiktok.com/i18n/pixel/events.js",o=n&&n.partner;ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=i,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};var a=document.createElement("script");a.type="text/javascript",a.async=!0,a.src=i+"?sdkid="+e+"&lib="+t;var s=document.getElementsByTagName("script")[0];s.parentNode.insertBefore(a,s)};
                ttq.load(${JSON.stringify(settings.tiktok_pixel_id)});
                ttq.page();
              }(window, document, 'ttq');
            `}
          </Script>
        )}
      </head>
      <body>
        {/* ADR-071 PR1a: the persistent chrome lives here, not per-page,
          * so a route's `loading.tsx` fallback swaps only the page
          * content — the header and bottom nav never unmount, and there
          * is no double-mounted skeleton. SiteHeader/BottomNav are
          * client components with no server fetch, so they add no
          * Suspense point to the layout. SiteFooter (async) stays
          * per-page — it is below the fold during any navigation. */}
        <SiteConfigProvider value={{ membershipEnabled, branding }}>
          <SearchProvider>
            <SiteHeader />
            {children}
            <BottomNav />
          </SearchProvider>
        </SiteConfigProvider>
        {/* ADR-029 addendum decision 13: admin-authored end-of-body scripts, injected verbatim in priority order. */}
        {bodyEndScripts.map((s, i) => (
          <Script key={`body-end-script-${i}`} id={`seo-body-end-script-${i}`} strategy="afterInteractive" dangerouslySetInnerHTML={{ __html: s.code }} />
        ))}
        {settings.ga_measurement_id && <GoogleAnalytics gaId={settings.ga_measurement_id} />}
        {/* ADR-071 PR4 decision 12 — Core Web Vitals. SpeedInsights is
          * the dashboard; WebVitals mirrors every measurement to
          * `/api/vitals` so a budget regression is visible in the logs
          * even when Speed Insights sampled a data point out. */}
        <SpeedInsights />
        <WebVitals />
      </body>
    </html>
  );
}
