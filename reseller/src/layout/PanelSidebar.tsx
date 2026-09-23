"use client";

import React from "react";
import Link from "next/link";
import { useSidebar } from "@/context/SidebarContext";
import { ChevronDownIcon, HorizontaLDots } from "@/icons";

/**
 * Portal-specific adaptation of the admin sidebar. Desktop retains the
 * collapsible rail; ADR-112 gives mobile its own bottom navigation and
 * a secondary "More" drawer.
 *
 * Desktop: pinned, collapses to an icon rail (hover to peek).
 * Mobile: the off-canvas "More" drawer is toggled from the bottom nav,
 * with a Backdrop. Scrolls internally — the nav area is `flex-1 min-h-0`
 * so a long menu never pushes items past the viewport with no way to
 * reach them. `extra` is this app's one real addition over admin's
 * version — a small subtitle block (the signed-in business name) shown
 * under the brand link, never present when the rail is collapsed.
 */

export type PanelNavLink = { kind: "link"; name: string; href: string; icon: React.ReactNode };
type PlaceholderItem = { kind: "placeholder"; name: string; icon: React.ReactNode };
type ButtonItem = {
  kind: "button";
  name: string;
  icon: React.ReactNode;
  onClick: () => void;
  busy?: boolean;
  busyLabel?: string;
};
type GroupItem = {
  kind: "group";
  name: string;
  icon: React.ReactNode;
  children: { name: string; href: string }[];
};

export type PanelNavItem = PanelNavLink | PlaceholderItem | ButtonItem | GroupItem;
export type PanelNavSection = { title: string; items: PanelNavItem[] };

interface PanelSidebarProps {
  homeHref: string;
  brandLabel: string;
  shortLabel: string;
  sections: PanelNavSection[];
  mobileSections: PanelNavSection[];
  mobileFooter: React.ReactNode;
  isActive: (href: string) => boolean;
  /** This app's own addition over admin's version — see file header. */
  extra?: React.ReactNode;
}

export default function PanelSidebar({ homeHref, brandLabel, shortLabel, sections, mobileSections, mobileFooter, isActive, extra }: PanelSidebarProps) {
  const { isExpanded, isMobileOpen, isHovered, setIsHovered, closeMobileSidebar } = useSidebar();
  const closeButtonRef = React.useRef<HTMLButtonElement>(null);

  function closeAndRestoreFocus() {
    closeMobileSidebar();
    document.getElementById("portal-more-trigger")?.focus();
  }

  React.useEffect(() => {
    if (!isMobileOpen) return;
    closeButtonRef.current?.focus();

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === "Escape") {
        closeMobileSidebar();
        document.getElementById("portal-more-trigger")?.focus();
        return;
      }
      if (event.key !== "Tab") return;

      const panel = document.getElementById("portal-more-panel");
      const focusable = panel?.querySelectorAll<HTMLElement>('#portal-more-home, #portal-more-close, [data-mobile-nav] a[href], [data-mobile-nav] button:not([disabled])');
      if (!focusable?.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }

    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [isMobileOpen, closeMobileSidebar]);

  const showLabels = isExpanded || isHovered || isMobileOpen;

  return (
    <aside
      id="portal-more-panel"
      aria-label={isMobileOpen ? "More navigation" : "Portal navigation"}
      className={`fixed left-0 top-0 z-50 mt-16 flex h-[calc(100dvh-4rem)] flex-col border-r border-gray-200 bg-white px-5 text-gray-900 transition-all duration-300 ease-in-out lg:mt-0 lg:h-screen lg:translate-x-0 dark:border-gray-800 dark:bg-gray-900
        ${showLabels ? "w-[290px]" : "w-[90px]"}
        ${isMobileOpen ? "visible translate-x-0" : "invisible -translate-x-full lg:visible"}`}
      onMouseEnter={() => !isExpanded && setIsHovered(true)}
      onMouseLeave={() => setIsHovered(false)}
    >
      <div className={`flex items-start justify-between py-8 ${!showLabels ? "lg:items-center" : ""}`}>
        <div className="min-w-0">
          <Link id="portal-more-home" href={homeHref} onClick={closeMobileSidebar} className="text-lg font-semibold text-gray-900 dark:text-white">
            {showLabels ? brandLabel : shortLabel}
          </Link>
          {showLabels && extra}
        </div>
        <button
          ref={closeButtonRef}
          id="portal-more-close"
          type="button"
          onClick={closeAndRestoreFocus}
          aria-label="Close more navigation"
          className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-gray-600 hover:bg-gray-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 lg:hidden dark:text-gray-300 dark:hover:bg-gray-800"
        >
          <span aria-hidden="true" className="text-2xl leading-none">×</span>
        </button>
      </div>

      <div className="hidden min-h-0 flex-1 flex-col gap-6 overflow-y-auto pb-8 no-scrollbar lg:flex">
        {sections.map((section) => (
          <PanelSection key={section.title} section={section} showLabels={showLabels} isActive={isActive} onNavigate={closeMobileSidebar} />
        ))}
      </div>
      <div data-mobile-nav className="flex min-h-0 flex-1 flex-col lg:hidden">
        <div className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto pb-6 no-scrollbar">
          {mobileSections.map((section) => (
            <PanelSection key={section.title} section={section} showLabels isActive={isActive} onNavigate={closeMobileSidebar} />
          ))}
        </div>
        <div className="border-t border-gray-200 pt-4 pb-[calc(1rem+env(safe-area-inset-bottom))] dark:border-gray-800">{mobileFooter}</div>
      </div>
    </aside>
  );
}

function PanelSection({
  section,
  showLabels,
  isActive,
  onNavigate,
}: {
  section: PanelNavSection;
  showLabels: boolean;
  isActive: (path: string) => boolean;
  onNavigate: () => void;
}) {
  return (
    <nav>
      <h2
        className={`mb-4 flex text-xs uppercase leading-[20px] text-gray-400 ${
          !showLabels ? "lg:justify-center" : "justify-start"
        }`}
      >
        {showLabels ? section.title : <HorizontaLDots />}
      </h2>
      <ul className="flex flex-col gap-1.5">
        {section.items.map((item) => (
          <PanelItem key={item.name} item={item} showLabels={showLabels} isActive={isActive} onNavigate={onNavigate} />
        ))}
      </ul>
    </nav>
  );
}

function PanelItem({
  item,
  showLabels,
  isActive,
  onNavigate,
}: {
  item: PanelNavItem;
  showLabels: boolean;
  isActive: (path: string) => boolean;
  onNavigate: () => void;
}) {
  if (item.kind === "group") {
    return <PanelGroup item={item} showLabels={showLabels} isActive={isActive} onNavigate={onNavigate} />;
  }

  if (item.kind === "placeholder") {
    return (
      <li>
        <span className="menu-item menu-item-inactive cursor-default opacity-40" aria-disabled="true">
          <span className="menu-item-icon-inactive">{item.icon}</span>
          {showLabels && <span className="menu-item-text">{item.name}</span>}
        </span>
      </li>
    );
  }

  if (item.kind === "button") {
    return (
      <li>
        <button
          type="button"
          onClick={item.onClick}
          disabled={item.busy}
          className="menu-item menu-item-inactive group w-full disabled:opacity-50"
        >
          <span className="menu-item-icon-inactive">{item.icon}</span>
          {showLabels && <span className="menu-item-text">{item.busy ? (item.busyLabel ?? item.name) : item.name}</span>}
        </button>
      </li>
    );
  }

  const active = isActive(item.href);
  return (
    <li>
      <Link href={item.href} onClick={onNavigate} aria-label={item.name} aria-current={active ? "page" : undefined} className={`menu-item group focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 ${active ? "menu-item-active" : "menu-item-inactive"}`}>
        <span className={active ? "menu-item-icon-active" : "menu-item-icon-inactive"}>{item.icon}</span>
        {showLabels && <span className="menu-item-text">{item.name}</span>}
      </Link>
    </li>
  );
}

function PanelGroup({
  item,
  showLabels,
  isActive,
  onNavigate,
}: {
  item: GroupItem;
  showLabels: boolean;
  isActive: (path: string) => boolean;
  onNavigate: () => void;
}) {
  const groupActive = item.children.some((c) => isActive(c.href));
  const [open, setOpen] = React.useState(groupActive);

  return (
    <li>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className={`menu-item group w-full ${groupActive ? "menu-item-active" : "menu-item-inactive"}`}
      >
        <span className={groupActive ? "menu-item-icon-active" : "menu-item-icon-inactive"}>{item.icon}</span>
        {showLabels && (
          <>
            <span className="menu-item-text">{item.name}</span>
            <ChevronDownIcon className={`ml-auto h-4 w-4 transition-transform ${open ? "rotate-180" : ""}`} />
          </>
        )}
      </button>
      {showLabels && open && (
        <ul className="mt-2 ml-9 flex flex-col gap-3 border-l border-gray-200 pl-3 dark:border-gray-800">
          {item.children.map((child) => (
            <li key={child.href}>
              <Link
                href={child.href}
                onClick={onNavigate}
                aria-current={isActive(child.href) ? "page" : undefined}
                className={`text-theme-sm ${
                  isActive(child.href)
                    ? "font-medium text-brand-500"
                    : "text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                }`}
              >
                {child.name}
              </Link>
            </li>
          ))}
        </ul>
      )}
    </li>
  );
}
