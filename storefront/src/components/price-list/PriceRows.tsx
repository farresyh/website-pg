import type { CSSProperties } from "react";
import type { ResellerPriceListPackage, ResellerPriceListTier } from "@/lib/reseller-price-list";

const COLS = "sm:grid-cols-[minmax(0,1fr)_repeat(var(--tiers),minmax(5.5rem,8rem))]";

/**
 * ADR-091 2026-11-01: one game's packages. A header row and aligned columns
 * from `sm` up; on a phone each package is a card with its tier prices side
 * by side, so nothing scrolls sideways. One markup for both, so a big list is
 * never rendered twice.
 */
export default function PriceRows({
  tiers,
  packages,
}: {
  tiers: ResellerPriceListTier[];
  packages: ResellerPriceListPackage[];
}) {
  const vars = { "--tiers": tiers.length } as CSSProperties;

  return (
    <div role="table" style={vars}>
      {/* top-[70px] = the site header's height (measured 2026-11-01), so this row sticks just below it. */}
      <div
        role="row"
        className={`sticky top-[70px] z-10 hidden border-b-2 border-ink bg-surface-container-lowest px-4 py-3 sm:grid sm:gap-2 ${COLS}`}
      >
        <div role="columnheader" className="font-display text-[12.5px] font-bold uppercase tracking-wide text-on-surface-variant">
          Package
        </div>
        {tiers.map((tier) => (
          <div
            key={tier.id}
            role="columnheader"
            className="text-right font-display text-[12.5px] font-bold uppercase tracking-wide text-primary-on-surface"
          >
            {tier.name}
          </div>
        ))}
      </div>
      {packages.map((pkg, i) => (
        <div
          key={`${pkg.name}-${i}`}
          role="row"
          className={`border-t border-ink/10 px-4 py-3 first:border-t-0 even:bg-surface-container-low sm:grid sm:items-center sm:gap-2 sm:first:border-t ${COLS}`}
        >
          <div role="cell" className="text-sm font-medium text-on-surface">
            {pkg.name}
          </div>
          <div className="mt-2 grid grid-cols-[repeat(var(--tiers),minmax(0,1fr))] gap-1.5 sm:contents">
            {tiers.map((tier) => (
              <div key={tier.id} role="cell" className="rounded-md bg-surface-container px-2 py-1.5 sm:bg-transparent sm:p-0 sm:text-right">
                <span className="block font-display text-[10.5px] font-bold uppercase tracking-wide text-primary-on-surface sm:hidden">
                  {tier.name}
                </span>
                <span className="font-mono text-[13.5px] tabular-nums text-on-surface sm:text-sm">
                  RM{(pkg.pricesRmByTierId[tier.id] ?? 0).toFixed(2)}
                </span>
              </div>
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}
