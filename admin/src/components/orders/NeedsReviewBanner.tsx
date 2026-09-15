import type { OrderDetail } from "@/lib/orders";

/**
 * ADR-026 decision 5, made supplier-aware by the 2026-09-16 addendum
 * (found shipping ADR-098) — the original copy hardcoded Gamevion's
 * own confirmed dashboard capabilities (date filter + Player ID/
 * Service columns, no reference-number search — a real fact from a
 * founder-reviewed screenshot, ADR-026's Context). ADR-098 made
 * Digiflazz a second real trigger, and this project has no equivalent
 * verified fact about Digiflazz's dashboard — so Digiflazz gets
 * generic "check the supplier's own records" copy instead of inventing
 * capability claims, plus the actual stored error_code/error_message
 * (the real trigger reason) so the admin has concrete context either
 * way.
 */
export default function NeedsReviewBanner({ order, sandbox }: { order: OrderDetail; sandbox?: boolean }) {
  const supplierName = order.supplier?.name ?? "the supplier";
  const errorCode = order.supplier_response?.error_code as string | undefined;
  const errorMessage = order.supplier_response?.error_message as string | undefined;
  const isGamevion = order.supplier?.name === "Gamevion";

  return (
    <div className="mt-3 rounded-lg bg-warning-50 p-4 text-sm dark:bg-warning-500/15">
      <p className="font-medium text-warning-600 dark:text-orange-400">
        {sandbox
          ? "Simulated needs_review outcome (ADR-026) — no real supplier order exists."
          : `Delivery outcome is ambiguous — ${supplierName} never confirmed success or failure for this order.`}
      </p>
      {(errorCode || errorMessage) && (
        <p className="mt-1 text-gray-600 dark:text-gray-400">
          Recorded reason: <span className="font-medium text-gray-700 dark:text-gray-300">{errorMessage ?? errorCode}</span>
          {errorCode && errorMessage && <span className="text-gray-400"> ({errorCode})</span>}
        </p>
      )}
      <p className="mt-1 text-gray-600 dark:text-gray-400">
        {sandbox ? (
          "In a real order these same fields would be used to cross-reference the supplier's own dashboard:"
        ) : isGamevion ? (
          <>Check Gamevion&apos;s dashboard (Date filter + Player ID/Service columns — there is no reference-number search there) before choosing an action below:</>
        ) : (
          <>Check {supplierName}&apos;s own transaction records for this reference before choosing an action below:</>
        )}
      </p>
      <dl className="mt-2 grid grid-cols-1 gap-1 sm:grid-cols-3">
        <div><dt className="text-gray-400">Created</dt><dd className="font-medium text-gray-700 dark:text-gray-300">{new Date(order.created_at).toLocaleString()}</dd></div>
        <div><dt className="text-gray-400">Player ID</dt><dd className="font-medium text-gray-700 dark:text-gray-300">{order.player_id}</dd></div>
        <div><dt className="text-gray-400">Game</dt><dd className="font-medium text-gray-700 dark:text-gray-300">{order.game?.name ?? "—"}</dd></div>
      </dl>
    </div>
  );
}
