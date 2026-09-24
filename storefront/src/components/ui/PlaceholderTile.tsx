/**
 * ADR-064 primitive. The branded fallback for a missing image — a
 * bordered tile with the initial set in the display face over a
 * hatch-tinted surface. Never a broken <img>, never a lone floating
 * icon. Fills its positioned parent.
 */
export default function PlaceholderTile({ label }: { label: string }) {
  return (
    <div
      className="flex h-full w-full items-center justify-center border-b-2 border-ink bg-surface-variant"
      style={{
        backgroundImage:
          "repeating-linear-gradient(45deg, transparent, transparent 9px, rgb(25 25 47 / 0.05) 9px, rgb(25 25 47 / 0.05) 10px)",
      }}
      aria-hidden="true"
    >
      <span className="font-display text-4xl font-bold text-on-surface/70">{label.charAt(0).toUpperCase()}</span>
    </div>
  );
}
