"use client";

import { useTheme } from "@/context/ThemeContext";
import { PortalIcon } from "@/components/shell/PortalIcon";

/**
 * ADR-128 top bar. Desktop: breadcrumb (sidebar group › page), theme, the
 * signed-in person, Sign out. Phone: the page name over "account · role";
 * Sign out moves into the More drawer.
 */
export default function PortalTopBar({
  crumbs,
  accountName,
  roleLabel,
  personName,
  personEmail,
  onSignOut,
  signingOut,
}: {
  crumbs: { group: string; page: string } | null;
  accountName: string;
  roleLabel: string;
  personName: string;
  personEmail: string;
  onSignOut: () => void;
  signingOut: boolean;
}) {
  return (
    <header className="sticky top-0 z-30 flex h-[var(--height-topbar)] items-center gap-3 border-b border-border bg-surface px-4 lg:px-8">
      <span className="grid h-[30px] w-[30px] shrink-0 place-items-center rounded-md bg-ink text-[13px] font-bold tracking-tight text-surface lg:hidden">PG</span>
      <div className="min-w-0 lg:hidden">
        <p className="truncate text-[15px] font-semibold leading-5 text-ink">{crumbs?.page ?? "PekanGame Partner"}</p>
        <p className="truncate text-xs font-medium leading-4 text-nude-ink">
          {accountName} · {roleLabel}
        </p>
      </div>
      {crumbs && (
        <nav aria-label="Breadcrumb" className="hidden items-center gap-1.5 text-[13px] text-ink-subtle lg:flex">
          <span>{crumbs.group}</span>
          <PortalIcon name="chevronRight" className="h-4 w-4" />
          <span className="font-semibold text-ink">{crumbs.page}</span>
        </nav>
      )}
      <div className="flex-1" />
      <ThemeToggle />
      <div className="hidden items-center gap-2.5 lg:flex">
        <span className="grid h-8 w-8 place-items-center rounded-full bg-nude-100 text-xs font-bold text-nude-ink" aria-hidden="true">
          {personName.trim().charAt(0).toUpperCase() || "?"}
        </span>
        <span className="max-w-[220px] leading-4">
          <span className="block truncate text-[13px] font-semibold text-ink">{personName}</span>
          <span className="block truncate text-xs text-ink-subtle">{personEmail}</span>
        </span>
        <button
          type="button"
          onClick={onSignOut}
          disabled={signingOut}
          className="ml-1 inline-flex h-9 items-center gap-2 rounded-md px-3 text-[13px] font-semibold text-ink-muted hover:bg-overlay hover:text-ink disabled:opacity-45"
        >
          <PortalIcon name="signOut" className="h-4 w-4" />
          {signingOut ? "Signing out…" : "Sign out"}
        </button>
      </div>
    </header>
  );
}

/** The icon follows the `.dark` class, not React state, so server and client render the same markup. */
export function ThemeToggle() {
  const { toggleTheme } = useTheme();
  return (
    <button
      type="button"
      onClick={toggleTheme}
      aria-label="Toggle dark mode"
      className="grid h-11 w-11 place-items-center rounded-md text-ink-muted hover:bg-overlay hover:text-ink lg:h-9 lg:w-9"
    >
      <span className="dark:hidden"><PortalIcon name="moon" /></span>
      <span className="hidden dark:block"><PortalIcon name="sun" /></span>
    </button>
  );
}
