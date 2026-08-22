"use client";

import React from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useSidebar } from "@/context/SidebarContext";
import { GridIcon, UserCircleIcon, DollarLineIcon, BoxLineIcon, PencilIcon, ListIcon, ImageIcon, BlockIcon, SettingsIcon, HorizontaLDots } from "@/icons";

/**
 * Nav items reflect only screens that actually exist — see
 * docs/prd.md §14 for what's built vs. still placeholder. Add an entry
 * here when its page is wired, not before.
 */
type NavItem = {
  name: string;
  icon: React.ReactNode;
  path: string;
};

const navItems: NavItem[] = [
  { icon: <GridIcon />, name: "Dashboard", path: "/admin" },
  { icon: <UserCircleIcon />, name: "Admin Users", path: "/admin/users" },
  { icon: <PencilIcon />, name: "Games & Packages", path: "/admin/games" },
  { icon: <ImageIcon />, name: "Hero Banner", path: "/admin/hero-slides" },
  { icon: <ImageIcon />, name: "Image Gallery", path: "/admin/gallery" },
  { icon: <ListIcon />, name: "Orders", path: "/admin/orders" },
  { icon: <DollarLineIcon />, name: "Withdrawals", path: "/admin/withdrawals" },
  { icon: <BoxLineIcon />, name: "Vouchers", path: "/admin/vouchers" },
  { icon: <BlockIcon />, name: "Blacklist", path: "/admin/blacklist" },
  { icon: <SettingsIcon />, name: "Settings", path: "/admin/settings" },
];

const AppSidebar: React.FC = () => {
  const { isExpanded, isMobileOpen, isHovered, setIsHovered } = useSidebar();
  const pathname = usePathname();

  const isActive = (path: string) => path === pathname;
  const showLabels = isExpanded || isHovered || isMobileOpen;

  return (
    <aside
      className={`fixed mt-16 flex flex-col lg:mt-0 top-0 px-5 left-0 bg-white dark:bg-gray-900 dark:border-gray-800 text-gray-900 h-screen transition-all duration-300 ease-in-out z-50 border-r border-gray-200
        ${isExpanded || isMobileOpen ? "w-[290px]" : isHovered ? "w-[290px]" : "w-[90px]"}
        ${isMobileOpen ? "translate-x-0" : "-translate-x-full"}
        lg:translate-x-0`}
      onMouseEnter={() => !isExpanded && setIsHovered(true)}
      onMouseLeave={() => setIsHovered(false)}
    >
      <div className={`py-8 flex ${!showLabels ? "lg:justify-center" : "justify-start"}`}>
        <Link href="/admin" className="text-lg font-semibold text-gray-900 dark:text-white">
          {showLabels ? "KedaiRuncitSoloz Admin" : "KRS"}
        </Link>
      </div>
      <div className="flex flex-col overflow-y-auto duration-300 ease-linear no-scrollbar">
        <nav className="mb-6">
          <h2
            className={`mb-4 text-xs uppercase flex leading-[20px] text-gray-400 ${
              !showLabels ? "lg:justify-center" : "justify-start"
            }`}
          >
            {showLabels ? "Menu" : <HorizontaLDots />}
          </h2>
          <ul className="flex flex-col gap-4">
            {navItems.map((nav) => (
              <li key={nav.name}>
                <Link
                  href={nav.path}
                  className={`menu-item group ${isActive(nav.path) ? "menu-item-active" : "menu-item-inactive"}`}
                >
                  <span className={isActive(nav.path) ? "menu-item-icon-active" : "menu-item-icon-inactive"}>
                    {nav.icon}
                  </span>
                  {showLabels && <span className="menu-item-text">{nav.name}</span>}
                </Link>
              </li>
            ))}
          </ul>
        </nav>
      </div>
    </aside>
  );
};

export default AppSidebar;
