"use client";

import React from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useSidebar } from "@/context/SidebarContext";
import { ChevronDown as ChevronDownIcon } from "@primeicons/react/chevron-down";
import { EllipsisH as HorizontaLDots } from "@primeicons/react/ellipsis-h";

/**
 * The shared collapsible/drawer sidebar for both the Admin Panel
 * (AppSidebar) and the Middleware Panel (MiddlewareSidebar) — one
 * component so the two panels can never visually drift again (founder
 * feedback, 2026-09-02: middleware had a hand-rolled static nav with
 * no mobile drawer and its own styling).
 *
 * Desktop: pinned, collapses to an icon rail (hover to peek).
 * Mobile: off-canvas drawer toggled from AppHeader's hamburger, with
 * a Backdrop. Scrolls internally — the nav area is `flex-1 min-h-0`
 * so a long menu (or an expanded group) never pushes items past the
 * viewport with no way to reach them.
 */

type LinkItem = { kind: "link"; name: string; href: string; icon: React.ReactNode };
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

export type PanelNavItem = LinkItem | PlaceholderItem | ButtonItem | GroupItem;
export type PanelNavSection = { title: string; items: PanelNavItem[] };

interface PanelSidebarProps {
  homeHref: string;
  brandLabel: string;
  shortLabel: string;
  sections: PanelNavSection[];
}

export default function PanelSidebar({ homeHref, brandLabel, shortLabel, sections }: PanelSidebarProps) {
  const { isExpanded, isMobileOpen, isHovered, setIsHovered } = useSidebar();
  const pathname = usePathname();

  const isActive = (path: string) => path === pathname;
  const showLabels = isExpanded || isHovered || isMobileOpen;

  return (
    <aside
      className={`fixed left-0 top-0 z-50 mt-16 flex h-[calc(100dvh-4rem)] flex-col border-r border-gray-200 bg-white px-5 text-gray-900 transition-all duration-300 ease-in-out lg:mt-0 lg:h-screen lg:translate-x-0 dark:border-gray-800 dark:bg-gray-900
        ${showLabels ? "w-[290px]" : "w-[90px]"}
        ${isMobileOpen ? "translate-x-0" : "-translate-x-full"}`}
      onMouseEnter={() => !isExpanded && setIsHovered(true)}
      onMouseLeave={() => setIsHovered(false)}
    >
      <div className={`flex py-8 ${!showLabels ? "lg:justify-center" : "justify-start"}`}>
        <Link href={homeHref} className="text-lg font-semibold text-gray-900 dark:text-white">
          {showLabels ? brandLabel : shortLabel}
        </Link>
      </div>

      <div className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto pb-8 no-scrollbar">
        {sections.map((section) => (
          <PanelSection key={section.title} section={section} showLabels={showLabels} isActive={isActive} />
        ))}
      </div>
    </aside>
  );
}

function PanelSection({
  section,
  showLabels,
  isActive,
}: {
  section: PanelNavSection;
  showLabels: boolean;
  isActive: (path: string) => boolean;
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
          <PanelItem key={item.name} item={item} showLabels={showLabels} isActive={isActive} />
        ))}
      </ul>
    </nav>
  );
}

function PanelItem({
  item,
  showLabels,
  isActive,
}: {
  item: PanelNavItem;
  showLabels: boolean;
  isActive: (path: string) => boolean;
}) {
  if (item.kind === "group") {
    return <PanelGroup item={item} showLabels={showLabels} isActive={isActive} />;
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
      <Link href={item.href} className={`menu-item group ${active ? "menu-item-active" : "menu-item-inactive"}`}>
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
}: {
  item: GroupItem;
  showLabels: boolean;
  isActive: (path: string) => boolean;
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
