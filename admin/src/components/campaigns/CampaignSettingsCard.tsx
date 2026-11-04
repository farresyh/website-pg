"use client";

/** ADR-118 V19: the scorecard's panel settings, on the Campaigns page itself. */

import { useState } from "react";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { notify } from "@/components/ui/toast";
import { ReportCard } from "@/components/reports/ReportKit";
import { ApiError } from "@/lib/api-client";
import { saveCampaignSettings, toSen, type CampaignSettings } from "@/lib/campaigns";

const rmInput = (sen: number | null) => (sen === null ? "" : (sen / 100).toFixed(2));

export default function CampaignSettingsCard({ settings, onSaved }: { settings: CampaignSettings; onSaved: (s: CampaignSettings) => void }) {
  const [margin, setMargin] = useState(String(settings.campaign_min_margin_percent));
  const [qualified, setQualified] = useState(rmInput(settings.campaign_qualified_min_sen));
  const [target, setTarget] = useState(rmInput(settings.campaign_target_cac_sen));
  const [trial, setTrial] = useState(settings.campaign_trial_months === null ? "" : String(settings.campaign_trial_months));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const saved = await saveCampaignSettings({
        campaign_min_margin_percent: Number(margin),
        campaign_qualified_min_sen: toSen(qualified) ?? 0,
        campaign_target_cac_sen: toSen(target),
        campaign_trial_months: trial.trim() === "" ? null : Number(trial),
      });
      onSaved(saved);
      notify.success("Campaign settings saved");
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save the settings.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <ReportCard title="Settings" subtitle="Apply to every campaign. The verdict appears once a target CAC and a trial length are both set.">
      <form onSubmit={save} className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-[repeat(4,minmax(0,1fr))_auto] xl:items-end">
        <div>
          <Label htmlFor="cs-margin">Minimum margin (%)</Label>
          <Input id="cs-margin" inputMode="decimal" value={margin} onChange={(e) => setMargin(e.target.value)} required />
        </div>
        <div>
          <Label htmlFor="cs-qualified">Qualified first order (RM)</Label>
          <Input id="cs-qualified" inputMode="decimal" value={qualified} onChange={(e) => setQualified(e.target.value)} required />
        </div>
        <div>
          <Label htmlFor="cs-target">Target CAC (RM)</Label>
          <Input id="cs-target" inputMode="decimal" value={target} onChange={(e) => setTarget(e.target.value)} placeholder="Not set" />
        </div>
        <div>
          <Label htmlFor="cs-trial">Trial (months)</Label>
          <Input id="cs-trial" inputMode="numeric" value={trial} onChange={(e) => setTrial(e.target.value)} placeholder="Not set" />
        </div>
        <Button type="submit" disabled={busy}>
          {busy ? "Saving…" : "Save"}
        </Button>
      </form>
      {error && <p className="mt-3 text-sm text-danger-ink">{error}</p>}
    </ReportCard>
  );
}
