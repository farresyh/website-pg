import { useSidebar } from "@/context/SidebarContext";
import React from "react";

const Backdrop: React.FC = () => {
  const { isMobileOpen, closeMobileSidebar } = useSidebar();

  function closeAndRestoreFocus() {
    closeMobileSidebar();
    document.getElementById("portal-more-trigger")?.focus();
  }

  if (!isMobileOpen) return null;

  return (
    <button
      type="button"
      aria-label="Close more navigation"
      className="fixed inset-0 z-40 cursor-default bg-gray-900/50 lg:hidden"
      onClick={closeAndRestoreFocus}
    />
  );
};

export default Backdrop;
