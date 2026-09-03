import type { ReactNode } from "react";

/**
 * Wraps a route skeleton in the same `<main>` the real page uses
 * (ADR-071 PR1a). `SiteHeader` / `BottomNav` are rendered once by the
 * root layout and persist across navigation, so a `loading.tsx`
 * fallback only ever swaps this `<main>` — no double-mounted chrome.
 */
export default function SkeletonShell({ children }: { children: ReactNode }) {
  return <main className="pb-nav lg:pb-0">{children}</main>;
}
