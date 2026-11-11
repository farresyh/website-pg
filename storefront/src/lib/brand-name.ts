/**
 * ADR-140 decision 4: the spaced form of a CamelCase store name ("PekanGame"
 * becomes "Pekan Game"), or null when the name has no such boundary. Used for
 * the primary brand's Organization `alternateName`. Dependency-free.
 */
export function spacedBrandName(storeName: string): string | null {
  const spaced = storeName.replace(/([a-z0-9])([A-Z])/g, "$1 $2");
  return spaced === storeName ? null : spaced;
}
