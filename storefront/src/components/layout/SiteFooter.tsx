import Link from "next/link";
import { FacebookIcon, InstagramIcon, TikTokIcon, YouTubeIcon, WhatsAppIcon } from "@/components/icons/SocialIcons";
import Logo from "@/components/ui/Logo";
import { PAYMENT_METHODS } from "@/lib/placeholder-data";
import { getBranding } from "@/lib/branding";
import { resolveWhatsappHref } from "@/lib/whatsapp";

const SOCIAL_ICONS = [
  { key: "facebook", label: "Facebook", Icon: FacebookIcon },
  { key: "instagram", label: "Instagram", Icon: InstagramIcon },
  { key: "tiktok", label: "TikTok", Icon: TikTokIcon },
  { key: "youtube", label: "YouTube", Icon: YouTubeIcon },
  { key: "whatsapp", label: "WhatsApp", Icon: WhatsAppIcon },
] as const;

/**
 * ADR-028 + its 2026-08-22 addendum — real branding/footer-games/legal
 * links replace the old hardcoded FOOTER_COLUMNS/SOCIAL_LINKS/Products
 * list. An async Server Component (not a page-level export) rendering
 * its own data is simpler here than prop-drilling branding through
 * every one of this component's 4 call sites.
 */
export default async function SiteFooter() {
  const branding = await getBranding();
  const currentYear = new Date().getFullYear();
  // WhatsApp falls back to a wa.me link built from `support_phone` when
  // no explicit `social_links.whatsapp` URL is set (ADR-071 PR0).
  const socialLinks = { ...branding.socialLinks, whatsapp: resolveWhatsappHref(branding) ?? undefined };

  return (
    <footer className="border-t-2 border-ink bg-surface-container-highest pt-9 pb-[calc(3.5rem+env(safe-area-inset-bottom))] lg:pt-12 lg:pb-6">
      <div className="mx-auto max-w-[1200px] px-4">
        <div className="mb-8 grid gap-8 lg:grid-cols-[1.4fr_1fr_1fr_1fr]">
          <div>
            <Link href="/" className="flex items-center gap-2">
              <Logo size={32} />
              <span className="font-display text-lg font-bold tracking-tight">{branding.storeName}</span>
            </Link>
            {branding.description && (
              <p className="mt-2.5 max-w-[320px] text-sm leading-relaxed text-on-surface-variant">{branding.description}</p>
            )}
            <div className="mt-4 flex gap-2.5">
              {SOCIAL_ICONS.map(
                ({ key, label, Icon }) =>
                  socialLinks[key] && (
                    <a
                      key={key}
                      href={socialLinks[key]}
                      aria-label={label}
                      className="flex h-11 w-11 items-center justify-center rounded-md border-2 border-ink bg-surface-container-lowest text-on-surface neo-sm hover:bg-surface-container-low"
                    >
                      <Icon size={16} />
                    </a>
                  ),
              )}
            </div>
          </div>

          {branding.footerGames.length > 0 && (
            <div>
              <h5 className="mb-3.5 font-display text-[12.5px] font-bold uppercase tracking-wide text-primary">Top Up Games</h5>
              <ul className="space-y-2.5 text-sm text-on-surface-variant">
                {branding.footerGames.map((game) => (
                  <li key={game.id}>
                    <Link href={`/order/${game.slug}`} className="hover:text-primary">
                      {game.name}
                    </Link>
                  </li>
                ))}
              </ul>
            </div>
          )}

          <div>
            <h5 className="mb-3.5 font-display text-[12.5px] font-bold uppercase tracking-wide text-primary">Company</h5>
            <ul className="space-y-2.5 text-sm text-on-surface-variant">
              <li>
                <Link href="/about-us" className="hover:text-primary">About Us</Link>
              </li>
              <li>
                <Link href="/terms" className="hover:text-primary">Terms &amp; Conditions</Link>
              </li>
              <li>
                <Link href="/privacy" className="hover:text-primary">Privacy Policy</Link>
              </li>
            </ul>
          </div>

          <div>
            <h5 className="mb-3.5 font-display text-[12.5px] font-bold uppercase tracking-wide text-primary">Support</h5>
            <ul className="space-y-2.5 text-sm text-on-surface-variant">
              {branding.supportEmail && <li>{branding.supportEmail}</li>}
              {branding.supportPhone && <li>{branding.supportPhone}</li>}
              <li>
                <Link href="/track-order" className="hover:text-primary">Track Order</Link>
              </li>
            </ul>
          </div>
        </div>

        <div className="mb-5 border-t-2 border-ink pt-5">
          <p className="mb-2.5 font-display text-[11px] font-bold uppercase tracking-wide text-on-surface-variant">Local Payment Methods Available</p>
          <div className="flex flex-wrap gap-2.5">
            {PAYMENT_METHODS.map((method) => (
              <span key={method} className="rounded-md border-2 border-ink bg-surface-container-lowest px-3.5 py-2 text-[12.5px] font-display font-bold">
                {method}
              </span>
            ))}
          </div>
        </div>

        <div className="flex flex-col gap-2 border-t-2 border-ink pt-5 text-xs text-on-surface-variant lg:flex-row lg:items-center lg:justify-between">
          <span>{branding.footerText ?? `© ${currentYear} ${branding.storeName}. All Rights Reserved.`}</span>
          <span>Made for Gamers, By Gamers.</span>
        </div>
      </div>
    </footer>
  );
}
