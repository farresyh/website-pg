"use client";

import { ThemeToggleButton } from "@/components/common/ThemeToggleButton";
import { useSidebar } from "@/context/SidebarContext";

/** Role-aware mobile title; desktop controls remain in the top bar. */
export default function PortalHeader({
  roleLabel,
  sessionLabel,
  onSignOut,
  signingOut,
}: {
  roleLabel: string;
  sessionLabel: string;
  onSignOut: () => void;
  signingOut: boolean;
}) {
  const { toggleSidebar } = useSidebar();

  return (
    <header className="sticky top-0 z-20 h-16 border-b border-gray-200 bg-white/95 backdrop-blur-lg lg:h-[76px] dark:border-gray-800 dark:bg-gray-900/95">
      <div className="flex h-full items-center justify-between gap-4 px-4 lg:px-6">
        <div className="min-w-0 lg:hidden">
          <p className="truncate text-base font-semibold leading-tight text-gray-900 dark:text-white">PekanGame</p>
          <p className="mt-0.5 text-xs font-medium text-gray-500 dark:text-gray-400">{roleLabel}</p>
        </div>
        <button
          type="button"
          onClick={toggleSidebar}
          aria-label="Toggle sidebar"
          className="hidden h-11 w-11 items-center justify-center rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 lg:flex dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
        >
          <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round">
            <path d="M4 6h16M4 12h16M4 18h16" />
          </svg>
        </button>
        <div className="hidden min-w-0 items-center gap-3 lg:flex">
          <span className="max-w-xs truncate text-sm text-gray-500 dark:text-gray-400">{sessionLabel}</span>
          <ThemeToggleButton />
          <button
            type="button"
            onClick={onSignOut}
            disabled={signingOut}
            className="min-h-11 rounded-full border border-gray-200 px-4 text-sm font-medium text-gray-600 hover:bg-gray-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
          >
            {signingOut ? "Signing out…" : "Sign out"}
          </button>
        </div>
      </div>
    </header>
  );
}
