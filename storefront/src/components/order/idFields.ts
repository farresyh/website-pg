/**
 * Player-ID field helpers shared by the order page (Step 1) and the
 * Game ID Checker (ADR-123).
 */

/**
 * ADR-097 2026-10-05 addendum, decision 34 — the backend's
 * CheckoutInputValidator rule, mirrored for an inline hint before
 * submit. The backend stays the authority.
 */
export function inputError(value: string, label: string, kind: "digits" | "text" | "any"): string | null {
  const trimmed = value.trim();
  if (trimmed === "") return null;
  if (/\s/.test(trimmed)) return `${label} must not contain spaces.`;
  if (trimmed.length > 64) return `${label} must not be longer than 64 characters.`;
  if (kind === "digits" && !/^\d+$/.test(trimmed)) return `${label} must contain digits only.`;
  if (kind === "text" && !/^[A-Za-z0-9#._-]+$/.test(trimmed)) return `${label} may only contain letters, digits and # . _ -`;
  return null;
}

/**
 * Typing a non-digit into a digits-only field is blocked. A paste is
 * not filtered — "12345678 (2001)" stripped would become a plausible
 * wrong ID — it is left as-is and `inputError` explains it. React's
 * onBeforeInput also fires for a paste (Chrome's textInput), right
 * after the paste event, so the paste marks itself first.
 */
export const digitsOnlyHandlers = {
  onPaste: (e: React.ClipboardEvent<HTMLInputElement>) => {
    e.currentTarget.dataset.pasting = "1";
  },
  onBeforeInput: (e: React.FormEvent<HTMLInputElement>) => {
    if (e.currentTarget.dataset.pasting) {
      delete e.currentTarget.dataset.pasting;
      return;
    }
    const data = (e.nativeEvent as InputEvent).data;
    if (data && /\D/.test(data)) e.preventDefault();
  },
};

export const idInputClass =
  "min-h-11 rounded-md border-2 border-ink bg-surface-container-lowest px-3.5 text-sm text-on-surface placeholder:text-outline focus:border-secondary focus:outline-none";
export const idLabelClass = "mb-1.5 block font-display text-[13px] font-bold";
