export function toRm(sen: number): number {
  return sen / 100;
}

export function formatRm(sen: number): string {
  return `RM ${(sen / 100).toLocaleString("en-MY", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export function formatDate(iso: string): string {
  return new Date(`${iso}T00:00:00`).toLocaleDateString("en-MY", { day: "numeric", month: "short", year: "numeric" });
}
