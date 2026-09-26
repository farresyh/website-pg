import SiteFooter from "@/components/layout/SiteFooter";
import Button from "@/components/ui/Button";

/**
 * ADR-071 PR1: an explicit, fully static not-found page. Next
 * prerenders this (and the auto-generated `/_not-found`) through the
 * root layout at build time — the layout's branding/SEO reads are
 * cached with `safeRead` fallbacks, so no backend is needed for the
 * build. Reached by `notFound()` (e.g. an unknown `/order/[slug]`).
 */
export default function NotFound() {
  return (
    <>
      <main className="pb-nav lg:pb-0">
        <div className="mx-auto flex min-h-[60vh] max-w-[560px] flex-col items-center justify-center px-4 py-16 text-center">
          <p className="font-mono text-6xl font-bold text-primary-on-surface">404</p>
          <h1 className="mt-4 font-display text-2xl font-bold uppercase tracking-tight">Page not found</h1>
          <p className="mt-2 text-sm leading-relaxed text-on-surface-variant">
            That page doesn&apos;t exist, or the game you were looking for isn&apos;t available right now.
          </p>
          <div className="mt-6 flex flex-wrap justify-center gap-3">
            <Button href="/">Browse games</Button>
            <Button href="/track-order" variant="outline">
              Track an order
            </Button>
          </div>
        </div>
      </main>
      <SiteFooter />
    </>
  );
}
