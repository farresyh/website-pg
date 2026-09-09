"use client";

/**
 * ADR-060 PR-6 — the affiliate portal's "Storefront" screen. Four tabs
 * configure the per-`Host` branded storefront ADR-060 PR-1…4d renders:
 * Branding (identity + logo + pixels), Hero (slides), Catalog (per-game
 * visibility), Pricing (retail markup + live preview). Each tab loads
 * its own data on select; the active tab is carried in `?tab=` so a
 * reload / shared link lands on the same one.
 */

import { useState } from "react";
import { PageHeader } from "@/components/ui";
import BrandingTab from "@/components/storefront/BrandingTab";
import ThemeTab from "@/components/storefront/ThemeTab";
import HeroTab from "@/components/storefront/HeroTab";
import CatalogTab from "@/components/storefront/CatalogTab";
import PricingTab from "@/components/storefront/PricingTab";

const TABS = [
  { key: "branding", label: "Branding" },
  { key: "theme", label: "Theme & Colors" },
  { key: "hero", label: "Hero" },
  { key: "catalog", label: "Catalog" },
  { key: "pricing", label: "Pricing" },
] as const;

type TabKey = (typeof TABS)[number]["key"];

function isTabKey(value: string | null): value is TabKey {
  return TABS.some((t) => t.key === value);
}

export default function StorefrontPage() {
  // Lazily seed from `?tab=` on the client (SSR always falls back to
  // "branding"; this screen is auth-gated so it never prerenders with a
  // real tab). Avoids the `useSearchParams` Suspense boundary.
  const [tab, setTab] = useState<TabKey>(() => {
    if (typeof window === "undefined") return "branding";
    const fromUrl = new URLSearchParams(window.location.search).get("tab");
    return isTabKey(fromUrl) ? fromUrl : "branding";
  });

  function select(key: TabKey) {
    setTab(key);
    const url = new URL(window.location.href);
    url.searchParams.set("tab", key);
    window.history.replaceState(null, "", url);
  }

  return (
    <div>
      <PageHeader
        title="Storefront"
        subtitle="Control how your branded storefront looks and what it sells."
      />

      <div className="mb-6 flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800">
        {TABS.map((t) => (
          <button
            key={t.key}
            type="button"
            onClick={() => select(t.key)}
            className={`shrink-0 border-b-2 px-4 py-2.5 text-theme-sm font-medium transition-colors ${
              tab === t.key
                ? "border-brand-500 text-brand-600 dark:text-brand-400"
                : "border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tab === "branding" && <BrandingTab />}
      {tab === "theme" && <ThemeTab />}
      {tab === "hero" && <HeroTab />}
      {tab === "catalog" && <CatalogTab />}
      {tab === "pricing" && <PricingTab />}
    </div>
  );
}
