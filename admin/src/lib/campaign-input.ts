/**
 * ADR-118 V26: a KOL share typed as a percentage ("2", "2%", "1.5") to basis
 * points, the server's unit. Empty means no share (0). Anything else
 * ("1,5", "two") becomes -1, which the server rejects with the allowed
 * range. Before this, `Number("2%")` was NaN, which JSON sends as null, and
 * the row was saved at 0% with no error. A fee row can't be edited after it
 * is saved, so that month could not be corrected.
 */
export const toBasisPoints = (value: string): number => {
  const cleaned = value.replace(/%/g, "").trim();
  if (value.trim() === "") return 0;
  return /^\d+(\.\d{1,2})?$/.test(cleaned) ? Math.round(Number(cleaned) * 100) : -1;
};
