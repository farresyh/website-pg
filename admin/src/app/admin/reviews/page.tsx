"use client";

/**
 * REV-1..5 (ADR-053) — admin moderation over guest-submitted reviews.
 * No public display exists anywhere (decision 6, submission itself is
 * built into storefront/src/components/order/RateOrderModal.tsx).
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import {
  DataTable,
  DataTableTableContainer,
  DataTableTable,
  DataTableTHead,
  DataTableTHeadRow,
  DataTableTHeadCell,
  DataTableTBody,
  DataTableRow,
  DataTableCell,
} from "@/components/ui/datatable";
import { Modal } from "@/components/ui/modal";
import { Tag } from "@/components/ui/tag";
import { Button } from "@/components/ui/button";
import { useClientSession } from "@/hooks/useClientSession";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import {
  type Review,
  type ReviewIndexResponse,
  type ReviewStatus,
  listReviews,
  approveReview,
  rejectReview,
  bulkApproveReviews,
} from "@/lib/reviews";
import { type Game, listGames } from "@/lib/games";

const inputClasses =
  "h-9 rounded-lg border border-gray-300 px-3 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90";

const STATUS_SEVERITY: Record<ReviewStatus, "warn" | "success" | "danger"> = {
  pending: "warn",
  approved: "success",
  rejected: "danger",
};

export default function ReviewsPage() {
  const router = useRouter();
  const session = useClientSession();

  const [games, setGames] = useState<Game[]>([]);
  const [data, setData] = useState<ReviewIndexResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [actingId, setActingId] = useState<number | null>(null);
  const [bulkApproving, setBulkApproving] = useState(false);
  const [confirmingBulkApprove, setConfirmingBulkApprove] = useState(false);

  const [status, setStatus] = useState("");
  const [rating, setRating] = useState("");
  const [gameId, setGameId] = useState("");
  const [withCommentOnly, setWithCommentOnly] = useState(false);
  const [page, setPage] = useState(1);

  function refresh(token: string) {
    return listReviews(token, {
      status: (status || undefined) as ReviewStatus | undefined,
      rating: rating ? Number(rating) : undefined,
      game_id: gameId ? Number(gameId) : undefined,
      with_comment_only: withCommentOnly || undefined,
      page,
    })
      .then(setData)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load reviews.");
      });
  }

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    listGames(s.token).then(setGames).catch(() => undefined);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!session) return;
    refresh(session.token);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, status, rating, gameId, withCommentOnly, page]);

  async function handleApprove(review: Review) {
    if (!session) return;
    setError(null);
    setActingId(review.id);
    try {
      await approveReview(session.token, review.id);
      await refresh(session.token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not approve this review.");
    } finally {
      setActingId(null);
    }
  }

  async function handleReject(review: Review) {
    if (!session) return;
    setError(null);
    setActingId(review.id);
    try {
      await rejectReview(session.token, review.id);
      await refresh(session.token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not reject this review.");
    } finally {
      setActingId(null);
    }
  }

  async function handleBulkApprove() {
    if (!session) return;
    setConfirmingBulkApprove(false);
    setError(null);
    setBulkApproving(true);
    try {
      await bulkApproveReviews(session.token);
      await refresh(session.token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not bulk-approve reviews.");
    } finally {
      setBulkApproving(false);
    }
  }

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Reviews</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Guest-submitted, order-linked reviews (ADR-053) — moderation only, no public display exists.
          </p>
        </div>
        <Button disabled={bulkApproving || !data?.stats.pending} onClick={() => setConfirmingBulkApprove(true)}>
          {bulkApproving ? "Approving…" : "Approve All Pending"}
        </Button>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-5">
        <StatCard label="Pending" value={data?.stats.pending ?? 0} />
        <StatCard label="Approved" value={data?.stats.approved ?? 0} />
        <StatCard label="Rejected" value={data?.stats.rejected ?? 0} />
        <StatCard label="Total" value={data?.stats.total ?? 0} />
        <StatCard label="Average Rating" value={data?.stats.average_rating ?? 0} />
      </div>

      <div className="mb-4 flex flex-wrap items-center gap-3">
        <select
          value={status}
          onChange={(e) => {
            setStatus(e.target.value);
            setPage(1);
          }}
          className={inputClasses}
        >
          <option value="">All statuses</option>
          <option value="pending">Pending</option>
          <option value="approved">Approved</option>
          <option value="rejected">Rejected</option>
        </select>
        <select
          value={rating}
          onChange={(e) => {
            setRating(e.target.value);
            setPage(1);
          }}
          className={inputClasses}
        >
          <option value="">All ratings</option>
          {[5, 4, 3, 2, 1].map((r) => (
            <option key={r} value={r}>
              {r} star{r > 1 ? "s" : ""}
            </option>
          ))}
        </select>
        <select
          value={gameId}
          onChange={(e) => {
            setGameId(e.target.value);
            setPage(1);
          }}
          className={inputClasses}
        >
          <option value="">All games</option>
          {games.map((g) => (
            <option key={g.id} value={g.id}>
              {g.name}
            </option>
          ))}
        </select>
        <label className="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
          <input
            type="checkbox"
            checked={withCommentOnly}
            onChange={(e) => {
              setWithCommentOnly(e.target.checked);
              setPage(1);
            }}
          />
          With comment only
        </label>
      </div>

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={data?.reviews.data ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Rating</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Comment</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Customer</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Game</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Package</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Date</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item }) => {
                    const review = item as unknown as Review;

                    return (
                      <DataTableRow key={review.id}>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                          {"★".repeat(review.rating)}
                          {"☆".repeat(5 - review.rating)}
                        </DataTableCell>
                        <DataTableCell className="max-w-[16rem] truncate px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {review.comment ?? "—"}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{review.order.customer_email}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{review.order.game?.name ?? "—"}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{review.order.package?.name ?? "—"}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <Tag severity={STATUS_SEVERITY[review.status]}>
                            {review.status}
                          </Tag>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {new Date(review.created_at).toLocaleDateString()}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          {review.status === "pending" ? (
                            <div className="flex gap-2">
                              <Button size="small" disabled={actingId === review.id} onClick={() => handleApprove(review)}>
                                Approve
                              </Button>
                              <Button size="small" severity="danger" disabled={actingId === review.id} onClick={() => handleReject(review)}>
                                Reject
                              </Button>
                            </div>
                          ) : (
                            <span className="text-gray-400">—</span>
                          )}
                        </DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>

          {data?.reviews.data.length === 0 && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No reviews yet.</p>
          )}
          {data === null && !error && <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>}
        </div>
      </div>

      {data && data.reviews.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
          <span>
            Page {data.reviews.current_page} of {data.reviews.last_page} ({data.reviews.total} total)
          </span>
          <div className="flex gap-2">
            <Button size="small" variant="outlined" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
              Previous
            </Button>
            <Button size="small" variant="outlined" disabled={page >= data.reviews.last_page} onClick={() => setPage((p) => p + 1)}>
              Next
            </Button>
          </div>
        </div>
      )}

      <Modal isOpen={confirmingBulkApprove} onClose={() => setConfirmingBulkApprove(false)} className="max-w-md">
        <div className="p-6">
          <h3 className="mb-2 text-lg font-semibold text-gray-800 dark:text-white/90">Approve All Pending</h3>
          <p className="mb-6 text-sm text-gray-500 dark:text-gray-400">
            Approve all {data?.stats.pending ?? 0} pending review{data?.stats.pending === 1 ? "" : "s"}?
          </p>
          <div className="flex justify-end gap-3">
            <Button variant="outlined" onClick={() => setConfirmingBulkApprove(false)}>
              Cancel
            </Button>
            <Button onClick={handleBulkApprove}>Approve All</Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}

function StatCard({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
      <p className="text-theme-xs text-gray-500 dark:text-gray-400">{label}</p>
      <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{value}</p>
    </div>
  );
}
