"use client";

/**
 * ADR-028 + its 2026-08-22 addendum. Split into three tab components
 * (StoreBrandingSection/FooterSettingsSection/PlatformSettingsSection)
 * rather than inlined here, same reasoning PendingPriceChangeSection
 * was already extracted for — keeps this file well under the
 * project's ~500-line guideline.
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { getSettings, type SettingsIndexResponse } from "@/lib/settings";
import StoreBrandingSection from "@/components/settings/StoreBrandingSection";
import FooterSettingsSection from "@/components/settings/FooterSettingsSection";
import PlatformSettingsSection from "@/components/settings/PlatformSettingsSection";

type Tab = "branding" | "footer" | "platform";

const TABS: { key: Tab; label: string }[] = [
  { key: "branding", label: "Store Branding" },
  { key: "footer", label: "Footer Settings" },
  { key: "platform", label: "Platform Settings" },
];

export default function SettingsPage() {
  const router = useRouter();
  const session = useClientSession();
  const [data, setData] = useState<SettingsIndexResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [tab, setTab] = useState<Tab>("branding");

  function refresh(token: string) {
    return getSettings(token)
      .then(setData)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load settings.");
      });
  }

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    refresh(s.token);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  if (error) {
    return <p className="rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>;
  }

  if (!session || !data) {
    return <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>;
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Settings</h1>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
          Store branding, footer content, and platform-wide configuration — edited here instead of hardcoded.
        </p>
      </div>

      <div className="mb-6 flex gap-6 border-b border-gray-200 dark:border-gray-800">
        {TABS.map((t) => (
          <button
            key={t.key}
            type="button"
            onClick={() => setTab(t.key)}
            className={`-mb-px border-b-2 px-1 pb-3 text-sm font-medium ${
              tab === t.key
                ? "border-brand-500 text-brand-600 dark:text-brand-400"
                : "border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tab === "branding" && (
        <StoreBrandingSection token={session.token} branding={data.branding} onSaved={() => refresh(session.token)} />
      )}
      {tab === "footer" && (
        <FooterSettingsSection
          token={session.token}
          footer={data.footer}
          storeName={data.branding.store_name}
          onSaved={() => refresh(session.token)}
        />
      )}
      {tab === "platform" && (
        <PlatformSettingsSection token={session.token} platform={data.platform} onSaved={() => refresh(session.token)} />
      )}
    </div>
  );
}
