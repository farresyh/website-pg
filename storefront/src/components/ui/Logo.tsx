/**
 * PekanGame placeholder mark (ADR-063 decision 8) — a bolt in a
 * hard-bordered square, in the storefront's neo-brutalist language.
 * Explicitly a placeholder: drop a real exported asset into
 * `storefront/public/` and swap this out. Never hotlink a design-tool
 * asset URL here (those expire).
 */
export default function Logo({ size = 32 }: { size?: number }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 32 32"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      aria-hidden="true"
    >
      <rect x="2" y="2" width="28" height="28" rx="4" fill="#6b38d4" stroke="#19192f" strokeWidth="2.5" />
      <path
        d="M17.6 5 L9 18.2 H15 L13.2 27 L23 13.4 H16.6 Z"
        fill="#ffffff"
        stroke="#19192f"
        strokeWidth="1.5"
        strokeLinejoin="round"
      />
    </svg>
  );
}
