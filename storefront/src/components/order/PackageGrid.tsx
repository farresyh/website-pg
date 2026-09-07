import { memo, useState } from "react";
import { CaretDown } from "@phosphor-icons/react/dist/ssr";
import type { GamePackage } from "@/lib/catalog";

interface PackageGridProps {
  packages: GamePackage[];
  selectedId: number | null;
  onSelect: (id: number) => void;
}

// A popular game can have 60+ packages. Show a first screen, then a
// "Show all" toggle — no scroll marathon to reach the payment step or
// the sticky Review & Pay bar (ADR-071 PR3). The selected package is
// always kept visible even if it sits past the cut-off.
const COLLAPSED_COUNT = 12;

/**
 * `memo` (ADR-071 PR2): up to ~60 package buttons. OrderForm re-renders
 * on every keystroke in Player ID / the Review Modal's contact fields;
 * its props here (`packages`, `selectedId`, the stable `setState`
 * `onSelect`) don't change with any of that, so the grid should sit
 * still. Targets INP < 200ms on a package tap.
 */
type TabType = "all" | "direct" | "pass";

function PackageGrid({ packages, selectedId, onSelect }: PackageGridProps) {
  const [activeTab, setActiveTab] = useState<TabType>("all");
  const [expanded, setExpanded] = useState(false);

  const hasPassPackages = packages.some((p) => p.hasCatalogCode || /pass|bundle|membership/i.test(p.name));
  const hasDirectPackages = packages.some((p) => p.hasDenomination || (!p.hasCatalogCode && !/pass|bundle|membership/i.test(p.name)));

  const filteredPackages = packages.filter((pkg) => {
    const isPass = pkg.hasCatalogCode || /pass|bundle|membership/i.test(pkg.name);
    if (activeTab === "pass") return isPass;
    if (activeTab === "direct") return !isPass;
    return true;
  });

  const collapsible = filteredPackages.length > COLLAPSED_COUNT + 3;
  let shown = filteredPackages;
  if (collapsible && !expanded) {
    shown = filteredPackages.slice(0, COLLAPSED_COUNT);
    const selected = filteredPackages.find((p) => p.id === selectedId);
    if (selected && !shown.includes(selected)) shown = [...shown, selected];
  }
  const hiddenCount = filteredPackages.length - shown.length;

  const tabs: { id: TabType; label: string; count: number }[] = [
    { id: "all", label: "All", count: packages.length },
    ...(hasDirectPackages
      ? [{ id: "direct" as const, label: "Direct Top Up", count: packages.filter((p) => !(p.hasCatalogCode || /pass|bundle|membership/i.test(p.name))).length }]
      : []),
    ...(hasPassPackages
      ? [{ id: "pass" as const, label: "Pass", count: packages.filter((p) => p.hasCatalogCode || /pass|bundle|membership/i.test(p.name)).length }]
      : []),
  ];

  return (
    <div>
      {tabs.length > 2 && (
        <div className="mb-4 flex flex-wrap gap-2">
          {tabs.map((tab) => {
            const active = activeTab === tab.id;
            return (
              <button
                key={tab.id}
                type="button"
                onClick={() => {
                  setActiveTab(tab.id);
                  setExpanded(false);
                }}
                className={`min-h-9 rounded-md border-2 px-3 py-1 font-display text-[12px] font-bold uppercase tracking-wide transition-all ${
                  active
                    ? "border-primary bg-primary text-on-primary neo-sm"
                    : "border-ink bg-surface-container-lowest text-on-surface hover:bg-surface-container-low"
                }`}
              >
                {tab.label} <span className="opacity-75">({tab.count})</span>
              </button>
            );
          })}
        </div>
      )}

      <div className="grid grid-cols-2 gap-2.5 lg:grid-cols-3">
        {shown.map((pkg) => {
          const selected = pkg.id === selectedId;
          // ADR-027's 2026-08-29 addendum, decisions 9/21: a display-only
          // savings % — the actual charged price is always computed
          // server-side (this codebase's own money-never-trusted-from-
          // client rule); this number never feeds a checkout request.
          const savingsPercent =
            pkg.memberPriceRm != null && pkg.priceRm > 0
              ? Math.round((1 - pkg.memberPriceRm / pkg.priceRm) * 100)
              : null;

          // Bug fix, 2026-08-30: `memberPriceRm` used to be shown as the
          // card's main price even for a non-member/anchor request — the
          // exact mismatch the founder caught live (card showed RM1.00,
          // Order Summary correctly charged RM1.05). Only a real,
          // authenticated member (`memberPricePersonalized`) actually
          // pays `memberPriceRm`; everyone else must see the standard
          // price as the primary number, with the member price shown
          // only as a comparison/upsell line — never swapped in.
          const isMemberPrice = pkg.memberPricePersonalized === true;

          return (
            <button
              key={pkg.id}
              type="button"
              onClick={() => onSelect(pkg.id)}
              aria-pressed={selected}
              className={`relative flex min-h-11 flex-col items-start gap-1 rounded-md border-2 p-3 text-left transition-all ${
                selected
                  ? "border-primary bg-primary-fixed neo"
                  : "border-ink bg-surface-container-lowest neo-hover hover:bg-surface-container-low"
              }`}
            >
              {savingsPercent != null && savingsPercent > 0 && (
                <span className="absolute -top-2.5 right-2 rounded-full border border-ink bg-tertiary px-1.5 py-0.5 font-display text-[10px] font-bold uppercase text-on-tertiary">
                  {isMemberPrice ? `Save ${savingsPercent}%` : `Member −${savingsPercent}%`}
                </span>
              )}
              <span className="font-display text-[13px] font-bold">{pkg.name}</span>
              {isMemberPrice && pkg.memberPriceRm != null ? (
                <span className="flex items-baseline gap-1.5">
                  <span className="font-mono text-[15px] font-bold text-primary">RM{pkg.memberPriceRm.toFixed(2)}</span>
                  <span className="font-mono text-[11px] text-on-surface-variant line-through">
                    RM{pkg.priceRm.toFixed(2)}
                  </span>
                </span>
              ) : (
                <span className="font-mono text-[15px] font-bold">RM{pkg.priceRm.toFixed(2)}</span>
              )}
              {!isMemberPrice && pkg.memberPriceRm != null && (
                <span className="font-mono text-[11px] font-semibold text-primary">
                  Member: RM{pkg.memberPriceRm.toFixed(2)}
                </span>
              )}
            </button>
          );
        })}
      </div>

      {collapsible && (
        <button
          type="button"
          onClick={() => setExpanded((v) => !v)}
          className="mt-3 flex w-full items-center justify-center gap-1.5 rounded-md border-2 border-ink bg-surface-container-lowest py-2.5 font-display text-[12px] font-bold uppercase tracking-wide neo-sm hover:bg-surface-container-low"
        >
          {expanded ? "Show fewer" : `Show all ${filteredPackages.length} packages`}
          <CaretDown size={13} weight="bold" className={expanded ? "rotate-180 transition-transform" : "transition-transform"} />
        </button>
      )}
      {collapsible && !expanded && hiddenCount > 0 && (
        <p className="sr-only">{hiddenCount} more packages hidden</p>
      )}
    </div>
  );
}

export default memo(PackageGrid);
