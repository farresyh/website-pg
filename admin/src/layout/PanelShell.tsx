"use client";

import React from "react";
import { useSidebar } from "@/context/SidebarContext";
import AppHeader from "@/layout/AppHeader";
import Backdrop from "@/layout/Backdrop";

/**
 * Shared chrome for the Admin and Middleware panels: a collapsible/
 * drawer sidebar, the mobile backdrop, a sticky header with the
 * sidebar toggle, and the centered content column. Both panels render
 * this so their responsive behavior can never diverge.
 */
export default function PanelShell({
  sidebar,
  headerTitle,
  children,
}: {
  sidebar: React.ReactNode;
  headerTitle?: string;
  children: React.ReactNode;
}) {
  const { isExpanded, isHovered, isMobileOpen } = useSidebar();

  const mainContentMargin = isMobileOpen
    ? "ml-0"
    : isExpanded || isHovered
      ? "lg:ml-[290px]"
      : "lg:ml-[90px]";

  return (
    <div className="min-h-screen xl:flex">
      {sidebar}
      <Backdrop />
      <div className={`flex-1 transition-all duration-300 ease-in-out ${mainContentMargin}`}>
        <AppHeader title={headerTitle} />
        <div className="mx-auto max-w-(--breakpoint-2xl) p-4 md:p-6">{children}</div>
      </div>
    </div>
  );
}
