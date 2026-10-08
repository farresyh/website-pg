export function toRm(sen: number): number {
  return sen / 100;
}

export function formatRm(sen: number): string {
  return `RM ${(sen / 100).toLocaleString("en-MY", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export function formatDate(iso: string): string {
  return new Date(`${iso}T00:00:00`).toLocaleDateString("en-MY", { day: "numeric", month: "short", year: "numeric" });
}

/** Chart value in RM (already divided by 100), always two decimals (ADR-104 R20). */
export function formatRmValue(rm: number): string {
  return `RM ${rm.toLocaleString("en-MY", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

/** Compact y-axis tick for an RM value. */
export function formatRmTick(rm: number): string {
  if (Math.abs(rm) >= 1000) return `${(rm / 1000).toFixed(1)}k`;
  if (rm === 0) return "0";
  return Math.abs(rm) < 10 ? rm.toFixed(2) : rm.toFixed(0);
}

/** "12 Apr – 10 Jul" (years only when the range crosses one) — a Compare period label short enough for a KPI card. */
export function formatShortRange(from: string, to: string): string {
  const opts: Intl.DateTimeFormatOptions = { day: "numeric", month: "short", timeZone: "UTC" };
  const sameYear = from.slice(0, 4) === to.slice(0, 4);
  const fmt = (d: string) =>
    new Date(`${d}T00:00:00Z`).toLocaleDateString("en-MY", sameYear ? opts : { ...opts, year: "numeric" });
  return `${fmt(from)} – ${fmt(to)}`;
}
