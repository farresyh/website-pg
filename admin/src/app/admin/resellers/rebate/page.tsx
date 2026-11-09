"use client";

/**
 * ADR-139 decisions 17, 18 (PR 4b): Admin → Reseller Rebate, laid out like
 * /admin/orders (the benchmark): title, Updated and Refresh top-right, then
 * tabs. Ladder: the version in force, the next one, all versions, and the
 * editor (MFA step-up). Month: each reseller's standing, the rows and the
 * CSV for the accountant. Super admin only, like Resellers.
 */

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Tabs, TabsIndicator, TabsList, TabsPanel, TabsPanels, TabsTab } from "@/components/ui/tabs";
import { useClientSession } from "@/hooks/useClientSession";
import { RebateLadderTab } from "@/components/rebate/RebateLadderTab";
import { RebateMonthTab } from "@/components/rebate/RebateMonthTab";

export default function ResellerRebatePage() {
  const session = useClientSession();
  const [tab, setTab] = useState("ladder");
  const [reloadKey, setReloadKey] = useState(0);
  const [updatedAt, setUpdatedAt] = useState(() => new Date());

  function refresh() {
    setReloadKey((k) => k + 1);
    setUpdatedAt(new Date());
  }

  if (!session) return <p className="text-sm text-ink-muted">Loading…</p>;

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-page-title font-semibold text-ink">Reseller Rebate</h1>
          <p className="mt-1 text-sm text-ink-muted">A monthly battle-pass ladder: fixed rewards per step, paid from a capped share of our own profit.</p>
        </div>
        <div className="flex items-center gap-3">
          <span className="flex items-center gap-1.5 text-theme-xs text-ink-muted">
            <span className="size-1.5 rounded-full bg-success-500" />
            Updated {updatedAt.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}
          </span>
          <Button size="small" variant="outlined" onClick={refresh}>Refresh</Button>
        </div>
      </div>

      <Tabs value={tab} onValueChange={(e) => setTab(e.value as string)}>
        <TabsList>
          <TabsTab value="ladder">Ladder</TabsTab>
          <TabsTab value="month">Month</TabsTab>
          <TabsIndicator />
        </TabsList>
        <TabsPanels>
          <TabsPanel value="ladder">{tab === "ladder" && <RebateLadderTab reloadKey={reloadKey} />}</TabsPanel>
          <TabsPanel value="month">{tab === "month" && <RebateMonthTab reloadKey={reloadKey} />}</TabsPanel>
        </TabsPanels>
      </Tabs>
    </div>
  );
}
