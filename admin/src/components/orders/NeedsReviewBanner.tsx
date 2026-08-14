import type { OrderDetail } from "@/lib/orders";

/**
 * ADR-026 decision 5 — surfaces exactly the fields an admin needs to
 * manually cross-reference Gamevion's own dashboard (no reference-
 * number search exists there, confirmed live) before using "Mark as
 * Delivered" or "Resend Delivery" below.
 */
export default function NeedsReviewBanner({ order }: { order: OrderDetail }) {
  return (
    <div className="mt-3 rounded-lg bg-warning-50 p-4 text-sm dark:bg-warning-500/15">
      <p className="font-medium text-warning-600 dark:text-orange-400">
        Delivery outcome is ambiguous — Gamevion never confirmed success or failure for this order.
      </p>
      <p className="mt-1 text-gray-600 dark:text-gray-400">
        Check Gamevion&apos;s dashboard (Date filter + Player ID/Service columns — there is no reference-number
        search there) before choosing an action below:
      </p>
      <dl className="mt-2 grid grid-cols-1 gap-1 sm:grid-cols-3">
        <div><dt className="text-gray-400">Created</dt><dd className="font-medium text-gray-700 dark:text-gray-300">{new Date(order.created_at).toLocaleString()}</dd></div>
        <div><dt className="text-gray-400">Player ID</dt><dd className="font-medium text-gray-700 dark:text-gray-300">{order.player_id}</dd></div>
        <div><dt className="text-gray-400">Game</dt><dd className="font-medium text-gray-700 dark:text-gray-300">{order.game?.name ?? "—"}</dd></div>
      </dl>
    </div>
  );
}
