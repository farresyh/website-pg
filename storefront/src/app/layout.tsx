import type { Metadata } from "next";
import { Space_Grotesk, Inter, JetBrains_Mono } from "next/font/google";
import Script from "next/script";
import { GoogleAnalytics } from "@next/third-parties/google";
import "./globals.css";
import { SearchProvider } from "@/context/SearchContext";
import { getBranding } from "@/lib/branding";
import { getSeoSettings, getSeoScripts } from "@/lib/seo";
import { SITE_URL } from "@/lib/site";

// Branding/SEO settings are live, admin-editable data (same reasoning
// already applied to page.tsx/sitemap.ts/robots.ts/etc.) — never bake
// into a static next build artifact. Without this, Next.js's own
// auto-generated /_not-found route tries to statically prerender
// through this layout at build time, requiring a reachable backend
// that doesn't exist in CI (or during a Docker image build, before a
// real production backend is even up).
export const dynamic = "force-dynamic";

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
  const [branding, settings, scripts] = await Promise.all([
    getBranding(),
    getSeoSettings(),
    getSeoScripts(),
  ]);

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
        {/* SEO-6/decision 14's FB Pixel + TikTok Pixel — next/script, not @next/third-parties (no official package exists for either). */}
        {settings.fb_pixel_id && (
          <Script id="fb-pixel" strategy="afterInteractive">
            {`
              !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
              fbq('init', '${settings.fb_pixel_id}');
              fbq('track', 'PageView');
            `}
          </Script>
        )}
        {settings.tiktok_pixel_id && (
          <Script id="tiktok-pixel" strategy="afterInteractive">
            {`
              !function (w, d, t) {
                w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie","holdConsent","revokeConsent","grantConsent"],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var i="https://analytics.tiktok.com/i18n/pixel/events.js",o=n&&n.partner;ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=i,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};var a=document.createElement("script");a.type="text/javascript",a.async=!0,a.src=i+"?sdkid="+e+"&lib="+t;var s=document.getElementsByTagName("script")[0];s.parentNode.insertBefore(a,s)};
                ttq.load('${settings.tiktok_pixel_id}');
                ttq.page();
              }(window, document, 'ttq');
            `}
          </Script>
        )}
      </head>
      <body>
        <SearchProvider>{children}</SearchProvider>
        {/* ADR-029 addendum decision 13: admin-authored end-of-body scripts, injected verbatim in priority order. */}
        {bodyEndScripts.map((s, i) => (
          <Script key={`body-end-script-${i}`} id={`seo-body-end-script-${i}`} strategy="afterInteractive" dangerouslySetInnerHTML={{ __html: s.code }} />
        ))}
        {settings.ga_measurement_id && <GoogleAnalytics gaId={settings.ga_measurement_id} />}
      </body>
    </html>
  );
}
