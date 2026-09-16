"use client";

import React from "react";
import PanelSidebar, { type PanelNavSection } from "@/layout/PanelSidebar";
import {
  GridIcon,
  UserCircleIcon,
  DollarLineIcon,
  BoxLineIcon,
  PencilIcon,
  ListIcon,
  ImageIcon,
  BlockIcon,
  SettingsIcon,
  ChartLineIcon,
  TrendUpIcon,
  TagIcon,
} from "@/icons";

/**
 * Admin Panel nav. Items reflect only screens that actually exist —
 * see docs/prd.md §14 for what's built vs. still placeholder. Add an
 * entry here when its page is wired, not before. The rendering lives
 * in the shared PanelSidebar (also used by the Middleware Panel).
 */
const sections: PanelNavSection[] = [
  {
    title: "Menu",
    items: [
      { kind: "link", name: "Dashboard", href: "/admin", icon: <GridIcon /> },
      { kind: "link", name: "Admin Users", href: "/admin/users", icon: <UserCircleIcon /> },
      { kind: "link", name: "Games & Packages", href: "/admin/games", icon: <PencilIcon /> },
      { kind: "link", name: "Hero Banner", href: "/admin/hero-slides", icon: <ImageIcon /> },
      { kind: "link", name: "Image Gallery", href: "/admin/gallery", icon: <ImageIcon /> },
      { kind: "link", name: "Reports", href: "/admin/reports", icon: <ChartLineIcon /> },
      { kind: "link", name: "Customer Analytics", href: "/admin/customer-analytics", icon: <TrendUpIcon /> },
      { kind: "link", name: "Orders", href: "/admin/orders", icon: <ListIcon /> },
      { kind: "link", name: "Withdrawals", href: "/admin/withdrawals", icon: <DollarLineIcon /> },
      { kind: "link", name: "Vouchers", href: "/admin/vouchers", icon: <BoxLineIcon /> },
      { kind: "link", name: "Reviews", href: "/admin/reviews", icon: <TagIcon /> },
      { kind: "link", name: "Blacklist", href: "/admin/blacklist", icon: <BlockIcon /> },
      { kind: "link", name: "Membership", href: "/admin/membership", icon: <BoxLineIcon /> },
      { kind: "link", name: "Affiliates", href: "/admin/affiliates", icon: <UserCircleIcon /> },
      { kind: "link", name: "Resellers", href: "/admin/resellers", icon: <DollarLineIcon /> },
      {
        kind: "group",
        name: "Accounting",
        icon: <DollarLineIcon />,
        children: [
          { name: "Supplier Funding", href: "/admin/accounting/suppliers" },
          { name: "Transaction Register", href: "/admin/accounting/transactions" },
        ],
      },
      {
        // ADR-029 decision 10 — the one collapsible nav group.
        kind: "group",
        name: "SEO",
        icon: <GridIcon />,
        children: [
          { name: "Overview", href: "/admin/seo" },
          { name: "Global Settings", href: "/admin/seo/settings" },
          { name: "Meta Templates", href: "/admin/seo/templates" },
          { name: "Game SEO", href: "/admin/seo/games" },
          { name: "Redirects", href: "/admin/seo/redirects" },
          { name: "Crawler", href: "/admin/seo/crawler" },
        ],
      },
      { kind: "link", name: "Settings", href: "/admin/settings", icon: <SettingsIcon /> },
    ],
  },
];

export default function AppSidebar() {
  return <PanelSidebar homeHref="/admin" brandLabel="PekanGame Admin" shortLabel="PG" sections={sections} />;
}
