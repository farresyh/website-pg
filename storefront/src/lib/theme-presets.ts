// Theme presets shared between the storefront and the reseller portal's
// preset picker (ADR-081/090) — MUST render identically in both, or an
// affiliate's preview in reseller/ won't match what customers see live.
// Canonical source: THIS file (storefront/src/lib/theme-presets.ts).
// `reseller/src/lib/theme-presets.ts` is a byte-for-byte generated copy —
// edit only here, then run `node scripts/sync-theme-presets.mjs`. CI's
// `theme-preset-drift` job fails the build if the two ever diverge.
// The backend's own copy of the preset-ID list
// (`backend/app/Http/Requests/Affiliate/Storefront/UpdateBrandingRequest.php`)
// must still be hand-kept in sync — PHP can't import this TS file.
export interface ThemePreset {
  id: string;
  name: string;
  tagline: string;
  description: string;
  primaryHex: string;
  secondaryHex: string;
  accentHex: string;
  tokens: Record<string, string>;
  /**
   * ADR-090: dark-mode token set, hand-authored (same "no automated
   * invert/contrast tool" principle as `tokens` — ADR-081). Only
   * `default` has one today — the ThemeTab's "Dark" mode option is
   * hidden for any preset without a `tokensDark`, rather than silently
   * falling back to its light palette on a dark-flagged brand.
   */
  tokensDark?: Record<string, string>;
}

export const THEME_PRESETS: Record<string, ThemePreset> = {
  default: {
    id: "default",
    name: "Digital Architect",
    tagline: "PekanGame Original Neo-Brutalism",
    description: "Electric purple paired with cyan, magenta accents, and ink-navy strokes.",
    primaryHex: "#6b38d4",
    secondaryHex: "#00687a",
    accentHex: "#ffd700",
    tokens: {
      "--color-primary": "#6b38d4",
      "--color-on-primary": "#ffffff",
      "--color-primary-container": "#8455ef",
      "--color-on-primary-container": "#fffbff",
      "--color-primary-fixed": "#e9ddff",
      "--color-on-primary-fixed": "#23005c",
      "--color-secondary": "#00687a",
      "--color-on-secondary": "#ffffff",
      "--color-secondary-container": "#57dffe",
      "--color-on-secondary-container": "#00363f",
      "--color-secondary-fixed-dim": "#4cd7f6",
      "--color-tertiary": "#b10e6b",
      "--color-on-tertiary": "#ffffff",
      "--color-tertiary-container": "#d23284",
      "--color-warning": "#ffd700",
    },
    // ADR-090: the dark counterpart of the base `globals.css` :root
    // palette (which `tokens` above deliberately leaves untouched — see
    // generateThemeCss's default/light short-circuit). `--color-ink`
    // flips to a light stroke here: a dark-navy 2px border is invisible
    // against a near-black surface, and the neo-brutalist hard border
    // is load-bearing to the whole visual language (ADR-063).
    tokensDark: {
      "--color-primary": "#8455ef",
      "--color-on-primary": "#ffffff",
      "--color-primary-container": "#6b38d4",
      "--color-on-primary-container": "#fffbff",
      "--color-primary-fixed": "#4a2694",
      "--color-on-primary-fixed": "#e9ddff",
      "--color-secondary": "#4cd7f6",
      "--color-on-secondary": "#00363f",
      "--color-secondary-container": "#00687a",
      "--color-on-secondary-container": "#57dffe",
      "--color-secondary-fixed-dim": "#00687a",
      "--color-tertiary": "#e6489a",
      "--color-on-tertiary": "#3f0026",
      "--color-tertiary-container": "#b10e6b",
      "--color-warning": "#ffd700",
      "--color-ink": "#e9e5f5",
      "--color-on-surface": "#f2f0eb",
      "--color-on-surface-variant": "#c9c5d6",
      "--color-surface": "#16151f",
      "--color-surface-container-lowest": "#0f0e17",
      "--color-surface-container-low": "#1c1b28",
      "--color-surface-container": "#232231",
      "--color-surface-container-high": "#2b2a3b",
      "--color-surface-container-highest": "#333246",
      "--color-surface-variant": "#2b2a3b",
      "--color-outline": "#8b86a0",
      "--color-outline-variant": "#3d3b52",
    },
  },
  bumblebee: {
    id: "bumblebee",
    name: "Cyber Bumblebee",
    tagline: "Ohaha Style — Bold Yellow & Ink Black",
    description: "Pop-art golden yellow with heavy solid black structural strokes and white highlights.",
    primaryHex: "#FFC700",
    secondaryHex: "#19192f",
    accentHex: "#FF9500",
    tokens: {
      "--color-primary": "#FFC700",
      "--color-on-primary": "#19192f",
      "--color-primary-container": "#E0AF00",
      "--color-on-primary-container": "#19192f",
      "--color-primary-fixed": "#FFF1B8",
      "--color-on-primary-fixed": "#332500",
      "--color-secondary": "#19192f",
      "--color-on-secondary": "#ffffff",
      "--color-secondary-container": "#3A3A50",
      "--color-on-secondary-container": "#ffffff",
      "--color-secondary-fixed-dim": "#8E8EA8",
      "--color-tertiary": "#FF9500",
      "--color-on-tertiary": "#19192f",
      "--color-tertiary-container": "#FFAE33",
      "--color-warning": "#FFC700",
      // ADR-090: preset background/surface tokens — the fix for the
      // "preset barely changes the page" complaint (grilled 2026-09-11).
      // Previously every preset left these untouched, so only buttons/
      // badges/borders ever recolored and the page background stayed
      // the base neutral cream regardless of preset.
      "--color-surface": "#fff8e1",
      "--color-on-surface": "#19192f",
      "--color-on-surface-variant": "#4b4630",
      "--color-surface-container-lowest": "#ffffff",
      "--color-surface-container-low": "#fff3d6",
      "--color-surface-container": "#ffecb8",
      "--color-surface-container-high": "#ffe49a",
      "--color-surface-container-highest": "#ffdb7a",
      "--color-surface-variant": "#ffe9ad",
      "--color-outline": "#7b7050",
      "--color-outline-variant": "#e0c98a",
    },
  },
  redgiants: {
    id: "redgiants",
    name: "Red Giants Edition",
    tagline: "Selangor Glory — Crimson Red & Golden Yellow",
    description: "Champion esports crimson red with vibrant golden yellow contrast and ink borders.",
    primaryHex: "#E51B24",
    secondaryHex: "#FFD200",
    accentHex: "#FFA000",
    tokens: {
      "--color-primary": "#E51B24",
      "--color-on-primary": "#ffffff",
      "--color-primary-container": "#BA1119",
      "--color-on-primary-container": "#ffffff",
      "--color-primary-fixed": "#FFD6D8",
      "--color-on-primary-fixed": "#4D0005",
      "--color-secondary": "#FFD200",
      "--color-on-secondary": "#19192f",
      "--color-secondary-container": "#FFE666",
      "--color-on-secondary-container": "#423400",
      "--color-secondary-fixed-dim": "#FFDE4D",
      "--color-tertiary": "#FFA000",
      "--color-on-tertiary": "#19192f",
      "--color-tertiary-container": "#FFB833",
      "--color-warning": "#FFD200",
      "--color-surface": "#fff5f5",
      "--color-on-surface": "#19192f",
      "--color-on-surface-variant": "#5a4040",
      "--color-surface-container-lowest": "#ffffff",
      "--color-surface-container-low": "#ffecec",
      "--color-surface-container": "#ffdede",
      "--color-surface-container-high": "#ffcfcf",
      "--color-surface-container-highest": "#ffbdbd",
      "--color-surface-variant": "#ffd9d9",
      "--color-outline": "#8a6060",
      "--color-outline-variant": "#e0b8b8",
    },
  },
  emerald: {
    id: "emerald",
    name: "Cyber Emerald",
    tagline: "Soloz Classic — Gamer Green & Neon Coral",
    description: "High-contrast arcade gamer green with neon coral punch and clean dark outlines.",
    primaryHex: "#00875a",
    secondaryHex: "#FF5630",
    accentHex: "#36B37E",
    tokens: {
      "--color-primary": "#00875a",
      "--color-on-primary": "#ffffff",
      "--color-primary-container": "#006644",
      "--color-on-primary-container": "#ffffff",
      "--color-primary-fixed": "#B8F5D8",
      "--color-on-primary-fixed": "#003822",
      "--color-secondary": "#FF5630",
      "--color-on-secondary": "#ffffff",
      "--color-secondary-container": "#FF8F73",
      "--color-on-secondary-container": "#4D1000",
      "--color-secondary-fixed-dim": "#FFA38B",
      "--color-tertiary": "#36B37E",
      "--color-on-tertiary": "#ffffff",
      "--color-tertiary-container": "#57D9A3",
      "--color-warning": "#FFD23F",
      "--color-surface": "#f2fbf6",
      "--color-on-surface": "#19192f",
      "--color-on-surface-variant": "#3d5548",
      "--color-surface-container-lowest": "#ffffff",
      "--color-surface-container-low": "#e6f7ee",
      "--color-surface-container": "#d3f0e0",
      "--color-surface-container-high": "#bfe8d1",
      "--color-surface-container-highest": "#a9dfc1",
      "--color-surface-variant": "#cdeedb",
      "--color-outline": "#5f8070",
      "--color-outline-variant": "#b7d9c5",
    },
  },
  cobalt: {
    id: "cobalt",
    name: "Hyper Cobalt",
    tagline: "Esports Cyber — Electric Blue & Arcade Orange",
    description: "High-voltage electric blue with saturated arcade orange accents and crisp typography.",
    primaryHex: "#0052cc",
    secondaryHex: "#FF7A00",
    accentHex: "#00B8D9",
    tokens: {
      "--color-primary": "#0052cc",
      "--color-on-primary": "#ffffff",
      "--color-primary-container": "#0747a6",
      "--color-on-primary-container": "#ffffff",
      "--color-primary-fixed": "#D0E2FF",
      "--color-on-primary-fixed": "#001D66",
      "--color-secondary": "#FF7A00",
      "--color-on-secondary": "#ffffff",
      "--color-secondary-container": "#FFA347",
      "--color-on-secondary-container": "#522200",
      "--color-secondary-fixed-dim": "#FFB870",
      "--color-tertiary": "#00B8D9",
      "--color-on-tertiary": "#ffffff",
      "--color-tertiary-container": "#79E2F2",
      "--color-warning": "#FFC700",
      "--color-surface": "#f2f7fd",
      "--color-on-surface": "#19192f",
      "--color-on-surface-variant": "#3d4a5c",
      "--color-surface-container-lowest": "#ffffff",
      "--color-surface-container-low": "#e6effb",
      "--color-surface-container": "#d3e3f7",
      "--color-surface-container-high": "#bfd6f2",
      "--color-surface-container-highest": "#a9c8ed",
      "--color-surface-variant": "#cddff5",
      "--color-outline": "#5f7185",
      "--color-outline-variant": "#b7cbe0",
    },
  },
};

export function getThemePreset(id?: string | null): ThemePreset {
  if (!id || !(id in THEME_PRESETS)) {
    return THEME_PRESETS.default;
  }
  return THEME_PRESETS[id];
}

/** ADR-090: `mode` defaults to "light" — every existing caller (pre-dark-mode) keeps its current behaviour unchanged. */
export function generateThemeCss(preset: ThemePreset, mode: "light" | "dark" = "light"): string {
  const useDark = mode === "dark" && preset.tokensDark;
  if (!useDark && preset.id === "default") return "";

  const tokens = useDark ? preset.tokensDark! : preset.tokens;
  const vars = Object.entries(tokens)
    .map(([key, val]) => `${key}: ${val};`)
    .join(" ");
  return `:root { ${vars} }`;
}
