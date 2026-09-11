/**
 * PekanGame placeholder mark (ADR-063 decision 8) — a bolt in a
 * hard-bordered square, in the storefront's neo-brutalist language.
 * Explicitly a placeholder: drop a real exported asset into
 * `storefront/public/` and swap this out. Never hotlink a design-tool
 * asset URL here (those expire).
 *
 * ADR-060 PR-6: when the `Host`-resolved brand has uploaded its own
 * logo, `src` is that image (served from the backend `/storage` host).
 * A plain `<img>`, not `next/image` — a small brand mark gains nothing
 * from the optimizer and this sidesteps the per-brand `remotePatterns`
 * question entirely. Falls back to the placeholder mark when unset.
 *
 * ADR-089: a real uploaded logo is bounded by `maxHeight`/`maxWidth`,
 * never forced into a square — most real-world logos are wordmarks or
 * wide icon+text combos (grilled against a rectangular-slot uploaded
 * logo shrinking illegibly in a fixed 34px square, live on an affiliate
 * storefront). The placeholder mark stays a fixed square — it's
 * deliberately drawn as one (ADR-063 decision 8), not an uploaded asset
 * with its own aspect ratio.
 */
export default function Logo({
  maxHeight = 48,
  maxWidth = 180,
  src = null,
  alt = "",
}: {
  maxHeight?: number;
  maxWidth?: number;
  src?: string | null;
  alt?: string;
}) {
  if (src) {
    return (
      // eslint-disable-next-line @next/next/no-img-element
      <img
        src={src}
        alt={alt}
        style={{ maxHeight, maxWidth, width: "auto", height: "auto", objectFit: "contain" }}
      />
    );
  }

  const placeholderSize = Math.min(maxHeight, 32);

  return (
    <svg
      width={placeholderSize}
      height={placeholderSize}
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
