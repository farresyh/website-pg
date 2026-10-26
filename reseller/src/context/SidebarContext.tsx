"use client";

import React, { createContext, useCallback, useContext, useEffect, useState } from "react";

/** Open state of the phone "More" drawer (ADR-112). The desktop sidebar is always shown. */
type SidebarContextType = {
  isMobileOpen: boolean;
  toggleMobileSidebar: () => void;
  closeMobileSidebar: () => void;
};

const SidebarContext = createContext<SidebarContextType | undefined>(undefined);

export const useSidebar = () => {
  const context = useContext(SidebarContext);
  if (!context) {
    throw new Error("useSidebar must be used within a SidebarProvider");
  }
  return context;
};

export const SidebarProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [isMobileOpen, setIsMobileOpen] = useState(false);

  useEffect(() => {
    // Leaving phone width closes the drawer, so it never reopens on its own.
    const query = window.matchMedia("(min-width: 1024px)");
    const close = () => query.matches && setIsMobileOpen(false);
    query.addEventListener("change", close);
    return () => query.removeEventListener("change", close);
  }, []);

  const toggleMobileSidebar = useCallback(() => setIsMobileOpen((prev) => !prev), []);
  const closeMobileSidebar = useCallback(() => setIsMobileOpen(false), []);

  return (
    <SidebarContext.Provider value={{ isMobileOpen, toggleMobileSidebar, closeMobileSidebar }}>
      {children}
    </SidebarContext.Provider>
  );
};
