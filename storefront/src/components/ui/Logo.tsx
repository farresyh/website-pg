/**
 * CSS/SVG approximation of the real diamond emblem (gradient
 * highlight #6CD15D → shadow #03392C, dark outline, "S" cutout) —
 * swap for the real exported SVG/PNG in /public once available.
 * Never hotlink a design-tool asset URL here (that broke the Figma
 * export draft — those URLs expire in ~7 days).
 */
export default function Logo({ size = 32 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <defs>
        <linearGradient id="soloz-logo-gradient" x1="4" y1="4" x2="28" y2="28" gradientUnits="userSpaceOnUse">
          <stop offset="0" stopColor="#6cd15d" />
          <stop offset="1" stopColor="#03392c" />
        </linearGradient>
      </defs>
      <path
        d="M16 1.5 L30.5 16 L16 30.5 L1.5 16 Z"
        fill="url(#soloz-logo-gradient)"
        stroke="#020b07"
        strokeWidth="2"
      />
      <path
        d="M13 10 L13 14.2 L19 14.2 L19 17.4 L13 17.4 L13 22 L10.2 22 L10.2 10 Z M13.8 15 L13.8 21.2 L18.2 21.2 L18.2 18.2 L12.2 18.2 L12.2 15 Z"
        fill="#f5f7f5"
      />
    </svg>
  );
}
