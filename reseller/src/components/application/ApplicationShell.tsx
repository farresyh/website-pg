import { LEGAL_LINKS } from "@/components/shell/TrustFooter";

/**
 * ADR-133 PR 3b: the frame of the public reseller application on `onboarding.pekangame.space`.
 * The same brand row and operator line as the sign-in page (`AuthLayout`), one wide column for
 * the form instead of the two-panel split.
 */
export default function ApplicationShell({ children }: { children: React.ReactNode }) {
  return (
    <div className="min-h-screen bg-canvas">
      <header className="border-b border-border bg-nude-100">
        <div className="mx-auto flex max-w-3xl items-center gap-2.5 px-4 py-4 sm:px-6">
          <span className="grid h-[30px] w-[30px] place-items-center rounded-md bg-ink text-[13px] font-bold tracking-tight text-surface">PG</span>
          <span className="text-[15px] font-bold tracking-tight text-ink">
            Pekan<span className="text-cyan-600">Game</span>
          </span>
          <span className="ml-1 rounded bg-surface px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-[0.06em] text-nude-ink">Reseller application</span>
        </div>
      </header>
      <main className="mx-auto max-w-3xl px-4 py-8 sm:px-6 sm:py-10">{children}</main>
      <footer className="mx-auto flex max-w-3xl flex-col items-center gap-3 px-4 pb-10 text-xs sm:px-6">
        <nav aria-label="Legal" className="flex flex-wrap justify-center gap-4">
          {LEGAL_LINKS.map((link) => (
            <a key={link.href} href={link.href} target="_blank" rel="noreferrer" className="text-ink-muted underline underline-offset-2 hover:text-ink">
              {link.label}
            </a>
          ))}
        </nav>
        <p className="text-center text-ink-subtle">Operated by LWF Group Sdn. Bhd. (202601038896), Malaysia.</p>
      </footer>
    </div>
  );
}
