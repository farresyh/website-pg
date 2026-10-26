import { PortalIcon } from "@/components/shell/PortalIcon";

/**
 * ADR-128 decision 6: who runs the portal, on every page. The company line
 * is fixed text (it practically never changes); the linked pages are edited
 * in the admin panel and served by the primary storefront.
 */
const STOREFRONT = "https://pekangame.com";
export const LEGAL_LINKS = [
  { label: "Terms", href: `${STOREFRONT}/terms` },
  { label: "Refund policy", href: `${STOREFRONT}/refund-policy` },
  { label: "About us", href: `${STOREFRONT}/about-us` },
];

export default function TrustFooter() {
  return (
    <footer className="mt-8 flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-border pt-5 text-xs leading-4 text-ink-subtle">
      <PortalIcon name="shield" className="h-4 w-4" />
      <span>
        PekanGame Partner is operated by <b className="font-semibold text-ink-muted">LWF Group Sdn. Bhd.</b> (202601038896)
      </span>
      <span className="flex-1" />
      {LEGAL_LINKS.map((link) => (
        <a key={link.href} href={link.href} target="_blank" rel="noreferrer" className="text-ink-muted underline underline-offset-2 hover:text-ink">
          {link.label}
        </a>
      ))}
    </footer>
  );
}
