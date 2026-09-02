"use client";

import React, { useState } from "react";
import { getClientSession } from "@/lib/session";
import { mintOpsLink, type OpsTarget } from "@/lib/ops";
import PanelSidebar, { type PanelNavSection } from "@/layout/PanelSidebar";
import {
  GridIcon,
  BoxLineIcon,
  ListIcon,
  DollarLineIcon,
  TrendUpIcon,
  BlockIcon,
  PencilIcon,
  SettingsIcon,
  UserCircleIcon,
  ChartLineIcon,
} from "@/icons";

/**
 * Middleware Panel nav — same shell as the Admin Panel (PanelSidebar),
 * previously a hand-rolled static list with no mobile drawer. Only
 * screens that actually exist are real links; the rest are still TODO
 * (§6.20). The "Ops" items are NOT Next.js pages — each opens a new
 * tab against a freshly-minted signed URL (see @/lib/ops.ts).
 */
const OPS_ITEMS: { label: string; target: OpsTarget; icon: React.ReactNode }[] = [
  { label: "Horizon (Queues)", target: "horizon", icon: <ChartLineIcon /> },
  { label: "Pulse (App Health)", target: "pulse", icon: <TrendUpIcon /> },
];

export default function MiddlewareSidebar() {
  const [openingTarget, setOpeningTarget] = useState<OpsTarget | null>(null);

  // Opens a blank tab synchronously (inside the click handler, before
  // the `await`) so the popup blocker still treats it as a real user
  // gesture — setting `.location` once the mint resolves avoids the
  // async-gap block that `window.open(url)` after an `await` would hit.
  const openOps = async (target: OpsTarget) => {
    const session = getClientSession();
    if (!session) return;

    const tab = window.open("", "_blank");
    setOpeningTarget(target);
    try {
      const { url } = await mintOpsLink(session.token, target);
      if (tab) tab.location.href = url;
    } catch {
      tab?.close();
    } finally {
      setOpeningTarget(null);
    }
  };

  const sections: PanelNavSection[] = [
    {
      title: "Menu",
      items: [
        { kind: "link", name: "Dashboard", href: "/middleware", icon: <GridIcon /> },
        { kind: "link", name: "Suppliers", href: "/middleware/suppliers", icon: <BoxLineIcon /> },
        { kind: "link", name: "Product Manager", href: "/middleware/product-manager", icon: <ListIcon /> },
        { kind: "link", name: "Payment Methods", href: "/middleware/payment-methods", icon: <DollarLineIcon /> },
        { kind: "link", name: "Price Sync", href: "/middleware/price-sync", icon: <TrendUpIcon /> },
        { kind: "link", name: "Validators", href: "/middleware/validators", icon: <BlockIcon /> },
        { kind: "placeholder", name: "Validate Player", icon: <UserCircleIcon /> },
        { kind: "link", name: "Sandbox", href: "/middleware/sandbox", icon: <PencilIcon /> },
        { kind: "link", name: "Backups", href: "/middleware/backups", icon: <BoxLineIcon /> },
        { kind: "link", name: "Request Logs", href: "/middleware/request-logs", icon: <ListIcon /> },
        { kind: "link", name: "Developer / API Tester", href: "/middleware/developer-tools", icon: <SettingsIcon /> },
      ],
    },
    {
      title: "Ops",
      items: OPS_ITEMS.map((item) => ({
        kind: "button" as const,
        name: item.label,
        icon: item.icon,
        onClick: () => openOps(item.target),
        busy: openingTarget === item.target,
        busyLabel: "Opening…",
      })),
    },
  ];

  return (
    <PanelSidebar homeHref="/middleware" brandLabel="Middleware Panel" shortLabel="MW" sections={sections} />
  );
}
