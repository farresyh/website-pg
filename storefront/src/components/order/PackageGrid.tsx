import type { PlaceholderPackage } from "@/lib/placeholder-data";

interface PackageGridProps {
  packages: PlaceholderPackage[];
  selectedId: number | null;
  onSelect: (id: number) => void;
}

export default function PackageGrid({ packages, selectedId, onSelect }: PackageGridProps) {
  return (
    <div className="grid grid-cols-2 gap-2.5 lg:grid-cols-3">
      {packages.map((pkg) => {
        const selected = pkg.id === selectedId;
        return (
          <button
            key={pkg.id}
            type="button"
            onClick={() => onSelect(pkg.id)}
            className={`flex min-h-11 flex-col items-start gap-1 rounded-lg border p-3 text-left transition-colors ${
              selected ? "border-brand bg-brand/10" : "border-border bg-surface hover:border-brand-light"
            }`}
          >
            <span className="text-[13px] font-semibold">{pkg.name}</span>
            <span className="text-[15px] font-extrabold">RM{pkg.priceRm.toFixed(2)}</span>
          </button>
        );
      })}
    </div>
  );
}
