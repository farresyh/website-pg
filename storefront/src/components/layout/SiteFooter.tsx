import Link from "next/link";
import { FacebookIcon, InstagramIcon, TikTokIcon, YouTubeIcon, WhatsAppIcon } from "@/components/icons/SocialIcons";
import Logo from "@/components/ui/Logo";
import { PAYMENT_METHODS } from "@/lib/placeholder-data";
import { getBranding } from "@/lib/branding";

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

  return (
    <footer className="border-t border-border bg-bg-deep pt-10 pb-6">
      <div className="mx-auto max-w-[1200px] px-4">
        <div className="mb-7 grid gap-7 lg:grid-cols-[1.4fr_1fr_1fr_1fr]">
          <div>
            <Link href="/" className="flex items-center gap-2">
              <Logo size={32} />
              <span className="font-display text-lg tracking-wide">{branding.storeName}</span>
            </Link>
            {branding.description && (
              <p className="mt-2.5 max-w-[320px] text-sm leading-relaxed text-text-muted">{branding.description}</p>
            )}
            <div className="mt-4 flex gap-2.5">
              {SOCIAL_ICONS.map(
                ({ key, label, Icon }) =>
                  branding.socialLinks[key] && (
                    <a
                      key={key}
                      href={branding.socialLinks[key]}
                      aria-label={label}
                      className="flex h-11 w-11 items-center justify-center rounded-full border border-border bg-surface text-text-muted hover:text-brand-light"
                    >
                      <Icon size={16} />
                    </a>
                  ),
              )}
            </div>
          </div>

          {branding.footerGames.length > 0 && (
            <div>
              <h5 className="mb-3.5 text-[12.5px] font-bold tracking-wide text-brand-light uppercase">Top Up Games</h5>
              <ul className="space-y-2.5 text-sm text-text-muted">
                {branding.footerGames.map((game) => (
                  <li key={game.id}>
                    <Link href={`/order/${game.slug}`} className="hover:text-brand-light">
                      {game.name}
                    </Link>
                  </li>
                ))}
              </ul>
            </div>
          )}

          <div>
            <h5 className="mb-3.5 text-[12.5px] font-bold tracking-wide text-brand-light uppercase">Company</h5>
            <ul className="space-y-2.5 text-sm text-text-muted">
              <li>
                <Link href="/about-us" className="hover:text-brand-light">About Us</Link>
              </li>
              <li>
                <Link href="/terms" className="hover:text-brand-light">Terms &amp; Conditions</Link>
              </li>
              <li>
                <Link href="/privacy" className="hover:text-brand-light">Privacy Policy</Link>
              </li>
            </ul>
          </div>

          <div>
            <h5 className="mb-3.5 text-[12.5px] font-bold tracking-wide text-brand-light uppercase">Support</h5>
            <ul className="space-y-2.5 text-sm text-text-muted">
              {branding.supportEmail && <li>{branding.supportEmail}</li>}
              {branding.supportPhone && <li>{branding.supportPhone}</li>}
              <li>
                <Link href="/track-order" className="hover:text-brand-light">Track Order</Link>
              </li>
            </ul>
          </div>
        </div>

        <div className="mb-5 border-t border-border pt-5">
          <p className="mb-2.5 text-[11px] tracking-wide text-text-muted uppercase">Local Payment Methods Available</p>
          <div className="flex flex-wrap gap-2.5">
            {PAYMENT_METHODS.map((method) => (
              <span key={method} className="rounded-md border border-border px-3.5 py-2 text-[12.5px] font-semibold text-text-muted">
                {method}
              </span>
            ))}
          </div>
        </div>

        <div className="flex flex-col gap-2 border-t border-border pt-4.5 text-xs text-text-muted lg:flex-row lg:items-center lg:justify-between">
          <span>{branding.footerText ?? `© ${currentYear} ${branding.storeName}. All Rights Reserved.`}</span>
          <span>Made for Gamers, By Gamers.</span>
        </div>
      </div>
    </footer>
  );
}
