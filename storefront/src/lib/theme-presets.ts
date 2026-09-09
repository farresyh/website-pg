export interface ThemePreset {
  id: string;
  name: string;
  tagline: string;
  description: string;
  primaryHex: string;
  secondaryHex: string;
  accentHex: string;
  tokens: Record<string, string>;
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
    },
  },
};

export function getThemePreset(id?: string | null): ThemePreset {
  if (!id || !(id in THEME_PRESETS)) {
    return THEME_PRESETS.default;
  }
  return THEME_PRESETS[id];
}

export function generateThemeCss(preset: ThemePreset): string {
  if (preset.id === "default") return "";
  const vars = Object.entries(preset.tokens)
    .map(([key, val]) => `${key}: ${val};`)
    .join(" ");
  return `:root { ${vars} }`;
}
