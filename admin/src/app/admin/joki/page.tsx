"use client";

/**
 * ADR-126 decision 23: Admin → Joki. Orders (JokiBoard's filters, the
 * Dashboard card's numbers) and Penjoki for every admin; Settings (switch,
 * prices, rank model, route packages) for the super admin. Joki orders
 * stay in /admin/orders too, as the full record (decision 25).
 */

import { Suspense, useState } from "react";
import { useSearchParams } from "next/navigation";
import { Tabs, TabsIndicator, TabsList, TabsPanel, TabsPanels, TabsTab } from "@/components/ui/tabs";
import { useClientSession } from "@/hooks/useClientSession";
import { JOKI_FILTER_LABELS, type JokiFilter } from "@/lib/joki";
import { JokiOrdersTab } from "@/components/joki/JokiOrdersTab";
import { JokiPenjokiTab } from "@/components/joki/JokiPenjokiTab";
import { JokiSettingsTab } from "@/components/joki/JokiSettingsTab";

export default function JokiPage() {
  return (
    <Suspense>
      <JokiPageInner />
    </Suspense>
  );
}

function JokiPageInner() {
  const session = useClientSession();
  const searchParams = useSearchParams();
  const fromUrl = searchParams.get("filter");
  const initialFilter: JokiFilter | "all" = fromUrl && fromUrl in JOKI_FILTER_LABELS ? (fromUrl as JokiFilter) : "all";
  const [tab, setTab] = useState("orders");
  const isSuperAdmin = session?.role === "super_admin";

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Joki</h1>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
          New joki orders wait for a penjoki. Assign one here or in Order Detail; the penjoki gets the order and login in their WhatsApp group.
        </p>
      </div>

      <Tabs value={tab} onValueChange={(e) => setTab(e.value as string)}>
        <TabsList>
          <TabsTab value="orders">Orders</TabsTab>
          <TabsTab value="penjoki">Penjoki</TabsTab>
          {isSuperAdmin && <TabsTab value="settings">Settings</TabsTab>}
          <TabsIndicator />
        </TabsList>
        <TabsPanels>
          <TabsPanel value="orders">{session && <JokiOrdersTab initialFilter={initialFilter} />}</TabsPanel>
          <TabsPanel value="penjoki">{session && tab === "penjoki" && <JokiPenjokiTab canRecordPayment={isSuperAdmin} />}</TabsPanel>
          {isSuperAdmin && <TabsPanel value="settings">{tab === "settings" && <JokiSettingsTab />}</TabsPanel>}
        </TabsPanels>
      </Tabs>
    </div>
  );
}
