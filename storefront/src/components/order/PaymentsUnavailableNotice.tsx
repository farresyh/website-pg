/**
 * ADR-131 decision 20: a branded domain that has not been approved for payments still shows the
 * whole store, but cannot take one. Said once, calmly, above the pay button, so nobody fills a
 * form for nothing. The server refuses the payment too (403 `payments_not_enabled`); this is the
 * courtesy, not the control.
 */
export const PAYMENTS_UNAVAILABLE_MESSAGE = "Payments are not available on this store yet. Please check back soon.";

export default function PaymentsUnavailableNotice() {
  return (
    <p role="status" className="rounded-md border-2 border-ink bg-surface-container p-4 text-sm text-on-surface-variant">
      {PAYMENTS_UNAVAILABLE_MESSAGE}
    </p>
  );
}
