import { PortalIcon, type PortalIconName } from "@/components/shell/PortalIcon";
import { LEGAL_LINKS } from "@/components/shell/TrustFooter";

/**
 * Sign-in and set-password frame (ADR-128 mockup "PartnerLogin"): who runs
 * the portal and what partners get on the nude panel, the form on the
 * right. Below `lg` the panel shrinks to a header above the form.
 */
const LINKS = [...LEGAL_LINKS, { label: "API docs", href: "https://docs.pekangame.space" }];
const POINTS: { icon: PortalIconName; lead: string; text: string }[] = [
  { icon: "wallet", lead: "Resellers", text: "order via automated messaging or API integration directly from a prepaid balance." },
  { icon: "store", lead: "Affiliates", text: "run their own branded store and withdraw their margin." },
  { icon: "shield", lead: "", text: "Secure payment processing. Full transaction tracking and transparent audit logs for every movement of your money." },
];

export default function AuthLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="grid min-h-screen grid-rows-[auto_1fr] bg-canvas lg:grid-cols-2 lg:grid-rows-1">
      <section className="flex flex-col justify-between gap-8 bg-nude-100 px-6 py-6 lg:px-16 lg:py-12">
        <div className="flex items-center gap-2.5">
          <span className="grid h-[30px] w-[30px] place-items-center rounded-md bg-ink text-[13px] font-bold tracking-tight text-surface">PG</span>
          <span className="text-[15px] font-bold tracking-tight text-ink">
            Pekan<span className="text-cyan-600">Game</span>
          </span>
          <span className="ml-1 rounded bg-surface px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-[0.06em] text-nude-ink">Partner</span>
        </div>
        <div className="hidden max-w-[460px] lg:block">
          <h1 className="text-[34px] font-[650] leading-10 tracking-[-0.02em] text-ink">Your partner account with PekanGame</h1>
          <p className="mt-3 mb-6 text-[15px] leading-[22px] text-ink-muted">For resellers and storefront affiliates selling game top-ups.</p>
          <ul className="flex flex-col gap-3.5">
            {POINTS.map((point) => (
              <li key={point.text} className="flex gap-2.5 text-sm text-ink-muted">
                <span className="mt-0.5 text-nude-ink">
                  <PortalIcon name={point.icon} className="h-4 w-4" />
                </span>
                <span>
                  {point.lead && <b className="font-semibold text-ink">{point.lead} </b>}
                  {point.text}
                </span>
              </li>
            ))}
          </ul>
        </div>
        <p className="hidden text-xs text-nude-ink lg:block">Operated by LWF Group Sdn. Bhd. (202601038896), Malaysia.</p>
      </section>

      <section className="flex flex-col items-center justify-center gap-5 px-4 py-10 sm:px-8">
        <div className="w-full max-w-[400px] rounded-lg border border-border bg-surface p-6 shadow-xs sm:p-7">{children}</div>
        <nav aria-label="Legal" className="flex flex-wrap justify-center gap-4 text-xs">
          {LINKS.map((link) => (
            <a key={link.href} href={link.href} target="_blank" rel="noreferrer" className="text-ink-muted underline underline-offset-2 hover:text-ink">
              {link.label}
            </a>
          ))}
        </nav>
        <p className="text-center text-xs text-ink-subtle lg:hidden">Operated by LWF Group Sdn. Bhd. (202601038896)</p>
      </section>
    </div>
  );
}
