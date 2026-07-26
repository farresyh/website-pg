import Link from "next/link";
import { FacebookLogo, InstagramLogo, XLogo, YoutubeLogo } from "@phosphor-icons/react/dist/ssr";
import Logo from "@/components/ui/Logo";
import { PAYMENT_METHODS } from "@/lib/placeholder-data";

const FOOTER_COLUMNS = [
  {
    heading: "Products",
    links: ["Mobile Legends", "PUBG Mobile", "Genshin Impact", "Valorant Points", "Steam Wallet"],
  },
  {
    heading: "Company",
    links: ["About Us", "Terms & Conditions", "Privacy Policy", "Partners"],
  },
  {
    heading: "Support",
    links: ["Contact Us", "FAQ", "WhatsApp Support", "How to Buy"],
  },
];

const SOCIAL_LINKS = [
  { label: "Facebook", icon: FacebookLogo, href: "#" },
  { label: "Instagram", icon: InstagramLogo, href: "#" },
  { label: "X", icon: XLogo, href: "#" },
  { label: "YouTube", icon: YoutubeLogo, href: "#" },
];

export default function SiteFooter() {
  return (
    <footer className="border-t border-border bg-bg-deep pt-10 pb-6">
      <div className="mx-auto max-w-[1200px] px-4">
        <div className="mb-7 grid gap-7 lg:grid-cols-[1.4fr_1fr_1fr_1fr]">
          <div>
            <Link href="/" className="flex items-center gap-2">
              <Logo size={32} />
              <span className="font-display text-lg tracking-wide">Kedai Runcit Soloz</span>
            </Link>
            <p className="mt-2.5 max-w-[320px] text-sm leading-relaxed text-text-muted">
              Kedai Runcit Soloz is the go-to digital top-up store for Malaysia&apos;s gaming community. Fast, secure
              top-ups with guaranteed automatic delivery.
            </p>
            <div className="mt-4 flex gap-2.5">
              {SOCIAL_LINKS.map(({ label, icon: Icon, href }) => (
                <a
                  key={label}
                  href={href}
                  aria-label={label}
                  className="flex h-11 w-11 items-center justify-center rounded-full border border-border bg-surface text-text-muted hover:text-brand-light"
                >
                  <Icon size={16} />
                </a>
              ))}
            </div>
          </div>

          {FOOTER_COLUMNS.map((col) => (
            <div key={col.heading}>
              <h5 className="mb-3.5 text-[12.5px] font-bold tracking-wide text-brand-light uppercase">{col.heading}</h5>
              <ul className="space-y-2.5 text-sm text-text-muted">
                {col.links.map((link) => (
                  <li key={link}>{link}</li>
                ))}
              </ul>
            </div>
          ))}
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
          <span>© 2026 Kedai Runcit Soloz Sdn Bhd. All Rights Reserved.</span>
          <span>Made for Gamers, By Gamers.</span>
        </div>
      </div>
    </footer>
  );
}
