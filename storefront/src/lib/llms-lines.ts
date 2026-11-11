/**
 * ADR-043 + ADR-140 decision 10: one game's line in /llms.txt. A published
 * summary rides on the end of it. The summary is collapsed to one line, so
 * admin text can never start a new line, heading or link in the file.
 * Dependency-free.
 */
export interface GameLineInput {
  origin: string;
  name: string;
  slug: string;
  category: string;
  priceFromRm: number | null;
  summary: string | null;
}

export function gameLine({ origin, name, slug, category, priceFromRm, summary }: GameLineInput): string {
  const price = priceFromRm !== null ? ` - from RM${priceFromRm.toFixed(2)}` : "";
  const categoryPart = category ? ` (${category})` : "";
  const oneLine = summary?.replace(/\s+/g, " ").trim();
  return `- [${name}](${origin}/order/${slug})${categoryPart}${price}${oneLine ? `: ${oneLine}` : ""}`;
}
