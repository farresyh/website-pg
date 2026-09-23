"use client";

import Link from "next/link";
import { useSidebar } from "@/context/SidebarContext";
import { HorizontaLDots } from "@/icons";
import type { PanelNavLink } from "@/layout/PanelSidebar";

export default function MobilePortalNav({
  primaryItems,
  moreActive,
  isActive,
}: {
  primaryItems: PanelNavLink[];
  moreActive: boolean;
  isActive: (href: string) => boolean;
}) {
  const { isMobileOpen, toggleMobileSidebar } = useSidebar();

  return (
    <nav
      aria-label="Primary mobile navigation"
      className="fixed inset-x-0 bottom-0 z-30 border-t border-gray-200 bg-white/95 pb-[env(safe-area-inset-bottom)] shadow-[0_-8px_24px_rgba(16,24,40,0.06)] backdrop-blur-lg lg:hidden dark:border-gray-800 dark:bg-gray-900/95"
    >
      <ul className="mx-auto grid max-w-lg grid-cols-4 px-2">
        {primaryItems.map((item) => {
          const active = isActive(item.href);
          return (
            <li key={item.href}>
              <Link
                href={item.href}
                aria-current={active ? "page" : undefined}
                className={`flex min-h-16 flex-col items-center justify-center gap-1 rounded-lg px-1 text-xs font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-[-3px] focus-visible:outline-brand-500 ${active ? "text-brand-600 dark:text-brand-400" : "text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"}`}
              >
                <span aria-hidden="true" className="h-5 w-5 [&>svg]:h-5 [&>svg]:w-5">{item.icon}</span>
                <span>{item.name}</span>
              </Link>
            </li>
          );
        })}
        <li>
          <button
            id="portal-more-trigger"
            type="button"
            aria-controls="portal-more-panel"
            aria-expanded={isMobileOpen}
            onClick={toggleMobileSidebar}
            className={`flex min-h-16 w-full flex-col items-center justify-center gap-1 rounded-lg px-1 text-xs font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-[-3px] focus-visible:outline-brand-500 ${isMobileOpen || moreActive ? "text-brand-600 dark:text-brand-400" : "text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"}`}
          >
            <span aria-hidden="true" className="h-5 w-5"><HorizontaLDots className="h-5 w-5" /></span>
            <span>More</span>
          </button>
        </li>
      </ul>
    </nav>
  );
}
