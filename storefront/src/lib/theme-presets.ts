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
      "--color-on-secondary-fixed-dim": "#19192f",
      "--color-tertiary": "#b10e6b",
      "--color-on-tertiary": "#ffffff",
      "--color-tertiary-container": "#d23284",
      "--color-warning": "#ffd700",
      "--color-primary-on-surface": "#6b38d4",
    },
    // No `tokensDark` — Digital Architect is PekanGame's own primary-brand
    // identity, never an affiliate's option (founder decision, 2026-09-24;
    // see storefront/DESIGN.md "Dark Mode Rules"), and the primary brand
    // stays on light permanently (ADR-090 decision 5). A hand-authored
    // dark palette was built and live-tested this same session but is
    // deliberately not kept here — it would be unreachable dead code with
    // no affiliate ever able to select this preset, let alone its dark
    // mode. If Digital Architect ever becomes affiliate-selectable again,
    // re-derive its dark palette from the other 4 presets' pattern in this
    // file rather than resurrecting old values.
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
      // Was a washed navy-slate/lavender-grey (#3A3A50 / #8E8EA8) — read
      // as faded, not "ink black" (founder audit finding, 2026-09-24).
      // Solid ink block + a yellow icon/text pop instead of grey-on-grey.
      "--color-secondary-container": "#19192f",
      "--color-on-secondary-container": "#ffffff",
      "--color-secondary-fixed-dim": "#19192f",
      "--color-on-secondary-fixed-dim": "#FFC700",
      "--color-tertiary": "#FF9500",
      "--color-on-tertiary": "#19192f",
      "--color-tertiary-container": "#FFAE33",
      "--color-warning": "#FFC700",
      // #FFC700 is ~1.6:1 on white — unusable as text (audit finding,
      // 2026-09-24). Deep amber reads as the same brand hue at ~5.5:1.
      "--color-primary-on-surface": "#8a6500",
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
    // DESIGN.md "Dark Mode Rules" applied: warm amber-black surface (not
    // neutral), muted warm-grey ink (not blazing cream), "white
    // highlights" (already in the preset's own tagline) becomes the
    // dark-mode secondary instead of trying to invert ink-black into a
    // fake bright color, and the big-fill Membership card stays a deep
    // warm brown so it reads as *this* brand gone dark, not a clashing
    // insert (2026-09-24).
    tokensDark: {
      "--color-primary": "#FFC700",
      "--color-on-primary": "#19192f",
      "--color-primary-container": "#B88900",
      "--color-on-primary-container": "#fff8e1",
      "--color-primary-fixed": "#4a3600",
      "--color-on-primary-fixed": "#FFE49A",
      "--color-primary-on-surface": "#FFC700",
      "--color-secondary": "#FFF3D6",
      "--color-on-secondary": "#19192f",
      "--color-secondary-container": "#332500",
      "--color-on-secondary-container": "#FFE49A",
      "--color-secondary-fixed-dim": "#332500",
      "--color-on-secondary-fixed-dim": "#FFC700",
      "--color-tertiary": "#FFA733",
      "--color-on-tertiary": "#19192f",
      "--color-tertiary-container": "#B86A00",
      "--color-warning": "#FFC700",
      "--color-ink": "#8f8360",
      "--color-on-surface": "#f5f0e0",
      "--color-on-surface-variant": "#c9bfa0",
      "--color-surface": "#1a1508",
      "--color-surface-container-lowest": "#100c05",
      "--color-surface-container-low": "#211a0d",
      "--color-surface-container": "#2b2313",
      "--color-surface-container-high": "#362c18",
      "--color-surface-container-highest": "#40341d",
      "--color-surface-variant": "#2b2313",
      "--color-outline": "#8f8360",
      "--color-outline-variant": "#40341d",
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
      "--color-on-secondary-fixed-dim": "#19192f",
      "--color-tertiary": "#FFA000",
      "--color-on-tertiary": "#19192f",
      "--color-tertiary-container": "#FFB833",
      "--color-warning": "#FFD200",
      "--color-primary-on-surface": "#E51B24",
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
    // Same rules as Cyber Bumblebee's dark build: warm maroon-black
    // surface (not neutral), muted warm-grey ink, gold reserved for
    // small accents/text (not a huge saturated fill), deep muted
    // gold-brown for the big-fill Membership card so it stays in the
    // red/gold family instead of reading as an inserted block.
    tokensDark: {
      "--color-primary": "#FF4550",
      "--color-on-primary": "#ffffff",
      "--color-primary-container": "#E51B24",
      "--color-on-primary-container": "#ffffff",
      "--color-primary-fixed": "#4D0005",
      "--color-on-primary-fixed": "#FFD6D8",
      "--color-primary-on-surface": "#FF4550",
      "--color-secondary": "#FFD200",
      "--color-on-secondary": "#19192f",
      "--color-secondary-container": "#4d3800",
      "--color-on-secondary-container": "#FFE666",
      "--color-secondary-fixed-dim": "#4d3800",
      "--color-on-secondary-fixed-dim": "#FFD200",
      "--color-tertiary": "#FFB833",
      "--color-on-tertiary": "#19192f",
      "--color-tertiary-container": "#4d3300",
      "--color-warning": "#FFD200",
      "--color-ink": "#9c7070",
      "--color-on-surface": "#f5e8e8",
      "--color-on-surface-variant": "#c9a0a0",
      "--color-surface": "#1a0a0a",
      "--color-surface-container-lowest": "#100505",
      "--color-surface-container-low": "#221010",
      "--color-surface-container": "#2b1414",
      "--color-surface-container-high": "#351818",
      "--color-surface-container-highest": "#401c1c",
      "--color-surface-variant": "#2b1414",
      "--color-outline": "#9c7070",
      "--color-outline-variant": "#401c1c",
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
      "--color-on-secondary-fixed-dim": "#19192f",
      "--color-tertiary": "#36B37E",
      "--color-on-tertiary": "#ffffff",
      "--color-tertiary-container": "#57D9A3",
      "--color-warning": "#FFD23F",
      "--color-primary-on-surface": "#00875a",
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
    // Same rules as Bumblebee/Red Giants: deep green-black surface,
    // muted green-grey ink, coral reserved for small accents/text —
    // the big-fill Membership card gets a deep muted rust instead of
    // raw neon coral so it stays in-family against a green base.
    tokensDark: {
      "--color-primary": "#2ED495",
      "--color-on-primary": "#ffffff",
      "--color-primary-container": "#00875a",
      "--color-on-primary-container": "#ffffff",
      "--color-primary-fixed": "#003822",
      "--color-on-primary-fixed": "#B8F5D8",
      "--color-primary-on-surface": "#2ED495",
      "--color-secondary": "#FF5630",
      "--color-on-secondary": "#3f0f05",
      "--color-secondary-container": "#4d1f10",
      "--color-on-secondary-container": "#FFCDBF",
      "--color-secondary-fixed-dim": "#4d1f10",
      "--color-on-secondary-fixed-dim": "#FF5630",
      "--color-tertiary": "#57D9A3",
      "--color-on-tertiary": "#003822",
      "--color-tertiary-container": "#1f3d2e",
      "--color-warning": "#FFD23F",
      "--color-ink": "#6a8577",
      "--color-on-surface": "#e8f5ee",
      "--color-on-surface-variant": "#a0c2ae",
      "--color-surface": "#0d1a13",
      "--color-surface-container-lowest": "#070f0a",
      "--color-surface-container-low": "#142219",
      "--color-surface-container": "#1a2b1f",
      "--color-surface-container-high": "#203525",
      "--color-surface-container-highest": "#263f2b",
      "--color-surface-variant": "#1a2b1f",
      "--color-outline": "#6a8577",
      "--color-outline-variant": "#263f2b",
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
      "--color-on-secondary-fixed-dim": "#19192f",
      "--color-tertiary": "#00B8D9",
      "--color-on-tertiary": "#ffffff",
      "--color-tertiary-container": "#79E2F2",
      "--color-warning": "#FFC700",
      "--color-primary-on-surface": "#0052cc",
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
    // Same rules as the other 3 dark builds: deep blue-black surface,
    // muted blue-grey ink, orange reserved for small accents/text — the
    // big-fill Membership card gets a deep muted orange-brown instead of
    // raw saturated orange so it stays in-family against a blue base.
    tokensDark: {
      "--color-primary": "#4C8DFF",
      "--color-on-primary": "#ffffff",
      "--color-primary-container": "#0052cc",
      "--color-on-primary-container": "#ffffff",
      "--color-primary-fixed": "#001D66",
      "--color-on-primary-fixed": "#D0E2FF",
      "--color-primary-on-surface": "#4C8DFF",
      "--color-secondary": "#FF7A00",
      "--color-on-secondary": "#19192f",
      "--color-secondary-container": "#522200",
      "--color-on-secondary-container": "#FFD9B3",
      "--color-secondary-fixed-dim": "#522200",
      "--color-on-secondary-fixed-dim": "#FF7A00",
      "--color-tertiary": "#00B8D9",
      "--color-on-tertiary": "#001D66",
      "--color-tertiary-container": "#0d3a45",
      "--color-warning": "#FFC700",
      "--color-ink": "#6b7d99",
      "--color-on-surface": "#e8f0fc",
      "--color-on-surface-variant": "#a0b2c9",
      "--color-surface": "#0d1626",
      "--color-surface-container-lowest": "#070c16",
      "--color-surface-container-low": "#141f33",
      "--color-surface-container": "#1a2740",
      "--color-surface-container-high": "#20304d",
      "--color-surface-container-highest": "#26395a",
      "--color-surface-variant": "#1a2740",
      "--color-outline": "#6b7d99",
      "--color-outline-variant": "#26395a",
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
