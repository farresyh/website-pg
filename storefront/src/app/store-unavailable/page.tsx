import type { Metadata } from "next";

/**
 * ADR-060 PR-5 — shown when a custom domain points at the platform but
 * is not attached to any active storefront (never verified, suspended,
 * or removed). `proxy.ts` rewrites unrecognised hosts here.
 *
 * Deliberately brand-neutral and static — there is no brand to resolve.
 */
export const metadata: Metadata = {
  title: "Store unavailable",
  robots: { index: false, follow: false },
};

export default function StoreUnavailable() {
  return (
    <main className="pb-nav lg:pb-0">
      <div className="mx-auto flex min-h-[70vh] max-w-[560px] flex-col items-center justify-center px-4 py-16 text-center">
        <p className="font-mono text-5xl font-bold text-primary-on-surface">503</p>
        <h1 className="mt-4 font-display text-2xl font-bold uppercase tracking-tight">Store unavailable</h1>
        <p className="mt-3 text-sm leading-relaxed text-on-surface-variant">
          This address isn&apos;t connected to an active store right now. If you&apos;re the store owner,
          check your domain setup in your portal. If you were shopping here, please try again later or
          contact the store you ordered from.
        </p>
      </div>
    </main>
  );
}
