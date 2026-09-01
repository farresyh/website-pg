"use client";

import { useState } from "react";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api-client";
import { applyBulkMarkup, updatePlatformSettings, type PlatformSettings } from "@/lib/settings";

function Switch({ checked, onChange }: { checked: boolean; onChange: (v: boolean) => void }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      onClick={() => onChange(!checked)}
      className={`relative h-5.5 w-10 flex-shrink-0 rounded-full transition-colors ${checked ? "bg-brand-500" : "bg-gray-300 dark:bg-gray-700"}`}
    >
      <span className={`absolute top-0.5 h-4.5 w-4.5 rounded-full bg-white transition-transform ${checked ? "translate-x-[19px]" : "translate-x-0.5"}`} />
    </button>
  );
}

export default function PlatformSettingsSection({
  token,
  platform,
  onSaved,
}: {
  token: string;
  platform: PlatformSettings;
  onSaved: () => void;
}) {
  const [vipThresholdRm, setVipThresholdRm] = useState(String(platform.vip_spend_threshold_sen / 100));
  const [maintenanceMode, setMaintenanceMode] = useState(platform.maintenance_mode);
  const [maintenanceMessage, setMaintenanceMessage] = useState(platform.maintenance_message ?? "");
  const [telegramEnabled, setTelegramEnabled] = useState(platform.telegram_notifications_enabled);
  const [telegramBotToken, setTelegramBotToken] = useState(platform.telegram_bot_token ?? "");
  const [telegramChatId, setTelegramChatId] = useState(platform.telegram_chat_id ?? "");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [markupPercent, setMarkupPercent] = useState("");
  const [applyingMarkup, setApplyingMarkup] = useState(false);
  const [markupResult, setMarkupResult] = useState<string | null>(null);

  async function handleSave() {
    const vipThresholdSen = Math.round(parseFloat(vipThresholdRm) * 100);
    if (!Number.isFinite(vipThresholdSen) || vipThresholdSen < 0) {
      setError("Enter a valid VIP spend threshold.");
      return;
    }

    setSaving(true);
    setError(null);
    try {
      await updatePlatformSettings(token, {
        maintenance_mode: maintenanceMode,
        maintenance_message: maintenanceMessage || null,
        vip_spend_threshold_sen: vipThresholdSen,
        telegram_notifications_enabled: telegramEnabled,
        telegram_bot_token: telegramBotToken || null,
        telegram_chat_id: telegramChatId || null,
      });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save platform settings.");
    } finally {
      setSaving(false);
    }
  }

  async function handleApplyMarkup() {
    const percent = parseFloat(markupPercent);
    if (!Number.isFinite(percent) || percent < 0) {
      setError("Enter a valid markup percentage.");
      return;
    }
    if (!confirm(`Apply ${percent}% markup to every active package? This recomputes standard_selling_price for all of them and cannot be undone in bulk.`)) {
      return;
    }

    setApplyingMarkup(true);
    setError(null);
    setMarkupResult(null);
    try {
      const result = await applyBulkMarkup(token, percent);
      setMarkupResult(`${result.packages_updated} package${result.packages_updated === 1 ? "" : "s"} updated.`);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not apply bulk markup.");
    } finally {
      setApplyingMarkup(false);
    }
  }

  return (
    <div className="space-y-5">
      {error && (
        <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label htmlFor="currency">Currency</Label>
            <Input id="currency" value={platform.currency} disabled />
            <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">Multi-currency is Phase 2 — locked to MYR for now.</p>
          </div>
          <div>
            <Label htmlFor="payment_channels">Payment channels &amp; fees</Label>
            <Input id="payment_channels" value="Managed in Payment Methods" disabled />
            <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">Stays at /middleware/payment-methods — not moved here.</p>
          </div>
          <div>
            <Label htmlFor="vip_spend_threshold">VIP spend threshold (RM)</Label>
            <Input id="vip_spend_threshold" value={vipThresholdRm} onChange={(e) => setVipThresholdRm(e.target.value)} placeholder="5000" />
            <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">Lifetime spend a customer needs to be tagged VIP in Customer Analytics (ADR-049).</p>
          </div>
        </div>
      </div>

      <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <h3 className="mb-1 text-sm font-semibold text-gray-800 dark:text-white/90">Bulk markup update</h3>
        <p className="mb-3 text-theme-xs text-gray-500 dark:text-gray-400">
          Applies a markup % to every currently-active package&apos;s <code>markup_percent</code>, recomputing{" "}
          <code>standard_selling_price</code> for each — logged the same as a manual per-package markup edit.
        </p>
        <div className="flex items-end gap-3">
          <div className="w-32">
            <Label htmlFor="markup_percent">Markup %</Label>
            <Input id="markup_percent" value={markupPercent} onChange={(e) => setMarkupPercent(e.target.value)} placeholder="15" />
          </div>
          <Button type="button" variant="outlined" size="small" onClick={handleApplyMarkup} disabled={applyingMarkup || !markupPercent}>
            {applyingMarkup ? "Applying…" : "Apply to All Packages"}
          </Button>
        </div>
        {markupResult && <p className="mt-2 text-theme-xs text-success-600">{markupResult}</p>}
      </div>

      <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="flex items-center justify-between">
          <div>
            <h3 className="text-sm font-semibold text-gray-800 dark:text-white/90">Maintenance mode</h3>
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">Blocks new checkouts only — browsing and order tracking stay live.</p>
          </div>
          <Switch checked={maintenanceMode} onChange={setMaintenanceMode} />
        </div>
        {maintenanceMode && (
          <div className="mt-4">
            <Label htmlFor="maintenance_message">Message shown to customers</Label>
            <textarea
              id="maintenance_message"
              value={maintenanceMessage}
              onChange={(e) => setMaintenanceMessage(e.target.value)}
              rows={2}
              className="w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
              placeholder="The store is temporarily unavailable for maintenance. Please try again shortly."
            />
          </div>
        )}
      </div>

      <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="flex items-center justify-between">
          <div>
            <h3 className="text-sm font-semibold text-gray-800 dark:text-white/90">Telegram ops notifications</h3>
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">Alerts for new orders and withdrawal requests.</p>
          </div>
          <Switch checked={telegramEnabled} onChange={setTelegramEnabled} />
        </div>
        {telegramEnabled && (
          <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <Label htmlFor="telegram_bot_token">Bot token</Label>
              <Input id="telegram_bot_token" value={telegramBotToken} onChange={(e) => setTelegramBotToken(e.target.value)} placeholder="123456:ABC-DEF…" />
            </div>
            <div>
              <Label htmlFor="telegram_chat_id">Chat ID</Label>
              <Input id="telegram_chat_id" value={telegramChatId} onChange={(e) => setTelegramChatId(e.target.value)} placeholder="-100XXXXXXXXXX" />
            </div>
          </div>
        )}
      </div>

      <div className="flex justify-end">
        <Button type="button" onClick={handleSave} disabled={saving}>
          {saving ? "Saving…" : "Save Platform Settings"}
        </Button>
      </div>
    </div>
  );
}
