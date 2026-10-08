/**
 * ADR-104 R3/R17 — client-side trend bucketing from the backend's daily
 * KL buckets ('YYYY-MM-DD'). Only additive fields are summed; a ratio
 * (margin, average order value) is recomputed from the bucket's sums by
 * the caller, never averaged across days.
 */
export type TrendBucket = "day" | "week" | "month";

/** R17 — the bucket "All time" opens on: ≤ 62 days daily, ≤ 1 year weekly, otherwise monthly. */
export function autoBucket(dayCount: number): TrendBucket {
  if (dayCount <= 62) return "day";
  if (dayCount <= 366) return "week";
  return "month";
}

/** Start date of the bucket a KL calendar date falls in. Weeks start Monday; months are KL calendar months. */
export function bucketStart(date: string, bucket: TrendBucket): string {
  if (bucket === "day") return date;
  if (bucket === "month") return `${date.slice(0, 7)}-01`;
  // Parsed as UTC so the string's own calendar date is the one used.
  const d = new Date(`${date}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() - ((d.getUTCDay() + 6) % 7));
  return d.toISOString().slice(0, 10);
}

/**
 * Sums `keys` per bucket, preserving input order of first appearance.
 * Returns `date` = the bucket's start date.
 */
export function bucketRows<T extends { date: string }, K extends keyof T & string>(
  rows: T[],
  bucket: TrendBucket,
  keys: K[],
): ({ date: string } & Record<K, number>)[] {
  const buckets = new Map<string, { date: string } & Record<K, number>>();
  for (const row of rows) {
    const start = bucketStart(row.date, bucket);
    let acc = buckets.get(start);
    if (!acc) {
      acc = { date: start, ...Object.fromEntries(keys.map((k) => [k, 0])) } as { date: string } & Record<K, number>;
      buckets.set(start, acc);
    }
    for (const key of keys) {
      (acc[key] as number) += Number(row[key]);
    }
  }
  return [...buckets.values()];
}

/** Σ profit ÷ Σ sales as a percentage; null when nothing sold (no margin, not 0%). */
export function marginPct(profit: number, sales: number): number | null {
  return sales > 0 ? (profit * 100) / sales : null;
}
