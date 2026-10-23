"use client";

/**
 * ADR-126 decision 23: Admin → Joki, laid out like /admin/orders (the
 * benchmark): title and Refresh top-right, then tabs. Orders (JokiBoard's
 * filters, the Dashboard card's numbers) and Penjoki for every admin;
 * Settings (switch, prices, rank model, route packages) for the super
 * admin. Joki orders stay in /admin/orders too, as the full record
 * (decision 25).
 */

import { Suspense, useState } from "react";
import { useSearchParams } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Tabs, TabsIndicator, TabsList, TabsPanel, TabsPanels, TabsTab } from "@/components/ui/tabs";
import { useClientSession } from "@/hooks/useClientSession";
import { JOKI_FILTER_LABELS, type JokiFilter } from "@/lib/joki";
import { JokiOrdersTab } from "@/components/joki/JokiOrdersTab";
import { JokiPenjokiTab } from "@/components/joki/JokiPenjokiTab";
import { JokiSettingsTab } from "@/components/joki/JokiSettingsTab";

export default function JokiPage() {
  return (
    <Suspense fallback={<p className="text-sm text-ink-muted">Loading…</p>}>
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
  const [reloadKey, setReloadKey] = useState(0);
  const [updatedAt, setUpdatedAt] = useState(() => new Date());
  const isSuperAdmin = session?.role === "super_admin";

  function refresh() {
    setReloadKey((k) => k + 1);
    setUpdatedAt(new Date());
  }

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-page-title font-semibold text-ink">Joki</h1>
          <p className="mt-1 text-sm text-ink-muted">
            Paid joki orders wait for a penjoki. Assign one and they get the order and the customer&apos;s login in their WhatsApp group.
          </p>
        </div>
        {tab !== "settings" && (
          <div className="flex items-center gap-3">
            <span className="flex items-center gap-1.5 text-theme-xs text-ink-muted">
              <span className="size-1.5 rounded-full bg-success-500" />
              Updated {updatedAt.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}
            </span>
            <Button size="small" variant="outlined" onClick={refresh}>Refresh</Button>
          </div>
        )}
      </div>

      <Tabs value={tab} onValueChange={(e) => setTab(e.value as string)}>
        <TabsList>
          <TabsTab value="orders">Orders</TabsTab>
          <TabsTab value="penjoki">Penjoki</TabsTab>
          {isSuperAdmin && <TabsTab value="settings">Settings</TabsTab>}
          <TabsIndicator />
        </TabsList>
        <TabsPanels>
          <TabsPanel value="orders">{session && <JokiOrdersTab initialFilter={initialFilter} reloadKey={reloadKey} />}</TabsPanel>
          <TabsPanel value="penjoki">{session && tab === "penjoki" && <JokiPenjokiTab canRecordPayment={isSuperAdmin} reloadKey={reloadKey} />}</TabsPanel>
          {isSuperAdmin && <TabsPanel value="settings">{tab === "settings" && <JokiSettingsTab />}</TabsPanel>}
        </TabsPanels>
      </Tabs>
    </div>
  );
}
