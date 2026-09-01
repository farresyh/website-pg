import type { GamePackage } from "@/lib/catalog";

interface PackageGridProps {
  packages: GamePackage[];
  selectedId: number | null;
  onSelect: (id: number) => void;
}

export default function PackageGrid({ packages, selectedId, onSelect }: PackageGridProps) {
  return (
    <div className="grid grid-cols-2 gap-2.5 lg:grid-cols-3">
      {packages.map((pkg) => {
        const selected = pkg.id === selectedId;
        // ADR-027's 2026-08-29 addendum, decisions 9/21: a display-only
        // savings % — the actual charged price is always computed
        // server-side (this codebase's own money-never-trusted-from-
        // client rule); this number never feeds a checkout request.
        const savingsPercent =
          pkg.memberPriceRm != null && pkg.priceRm > 0 ? Math.round((1 - pkg.memberPriceRm / pkg.priceRm) * 100) : null;

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
                <span className="font-mono text-[11px] text-on-surface-variant line-through">RM{pkg.priceRm.toFixed(2)}</span>
              </span>
            ) : (
              <span className="font-mono text-[15px] font-bold">RM{pkg.priceRm.toFixed(2)}</span>
            )}
            {!isMemberPrice && pkg.memberPriceRm != null && (
              <span className="font-mono text-[11px] font-semibold text-primary">Member: RM{pkg.memberPriceRm.toFixed(2)}</span>
            )}
          </button>
        );
      })}
    </div>
  );
}
