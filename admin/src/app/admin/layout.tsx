"use client";

import React from "react";
import AppSidebar from "@/layout/AppSidebar";
import PanelShell from "@/layout/PanelShell";

/**
 * Admin Panel shell (§6.2-6.18 of docs/prd.md). Accessible to both Admin
 * and Super Admin — per-page/action restrictions (e.g. AUTH-4 itself)
 * are enforced by the Laravel API, not here.
 */
export default function AdminLayout({ children }: { children: React.ReactNode }) {
  return <PanelShell sidebar={<AppSidebar />}>{children}</PanelShell>;
}
