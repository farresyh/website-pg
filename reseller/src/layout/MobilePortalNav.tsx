"use client";

import Link from "next/link";
import { useSidebar } from "@/context/SidebarContext";
import { PortalIcon } from "@/components/shell/PortalIcon";
import { isActivePath, type PortalNavItem } from "@/lib/portal-nav";

/** ADR-112's four-slot phone bar: Dashboard, Orders, the role's money page, More. */
export default function MobilePortalNav({
  primaryItems,
  moreItems,
  pathname,
}: {
  primaryItems: PortalNavItem[];
  moreItems: PortalNavItem[];
  pathname: string;
}) {
  const { isMobileOpen, toggleMobileSidebar } = useSidebar();
  const moreActive = isMobileOpen || moreItems.some((item) => isActivePath(pathname, item.href));
  const tab = (active: boolean) =>
    `flex min-h-16 w-full flex-col items-center justify-center gap-0.5 text-[11px] ${
      active ? "font-semibold text-cyan-ink shadow-[inset_0_2px_0_var(--color-cyan-600)]" : "font-medium text-ink-subtle hover:text-ink"
    }`;

  return (
    <nav
      aria-label="Primary mobile navigation"
      className="fixed inset-x-0 bottom-0 z-30 border-t border-border bg-surface pb-[env(safe-area-inset-bottom)] lg:hidden"
    >
      <ul className="mx-auto grid max-w-lg grid-cols-4">
        {primaryItems.map((item) => {
          const active = isActivePath(pathname, item.href);
          return (
            <li key={item.href}>
              <Link href={item.href} aria-current={active ? "page" : undefined} className={tab(active)}>
                <PortalIcon name={item.icon} className="h-5 w-5" />
                {item.name}
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
            className={tab(moreActive)}
          >
            <PortalIcon name="more" className="h-5 w-5" />
            More
          </button>
        </li>
      </ul>
    </nav>
  );
}
