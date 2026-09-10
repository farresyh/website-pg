<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ReviewCatalogController;
use App\Models\Review;
use App\Services\Review\ReviewStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REV-1..5 (ADR-053) — admin moderation over guest-submitted reviews.
 * No public display exists anywhere (decision 6) — this is the only
 * place a review is ever read back. `average_rating` (REV-1) is scoped
 * to status=approved only (decision 7), so a rejected/spam review
 * never skews it.
 */
class ReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Stats are scoped to the rating/game/with-comment-only filters
        // (so an admin filtering "5-star, MLBB" sees stats for that
        // slice) but deliberately NOT the status filter below — the
        // stat cards must always show all four counts regardless of
        // which status the table itself is currently filtered to.
        $statsQuery = Review::query();

        if ($request->filled('rating')) {
            $statsQuery->where('rating', (int) $request->query('rating'));
        }

        if ($request->filled('game_id')) {
            $statsQuery->whereHas('order', fn ($q) => $q->where('game_id', $request->query('game_id')));
        }

        if ($request->boolean('with_comment_only')) {
            $statsQuery->whereNotNull('comment');
        }

        $listQuery = (clone $statsQuery)->with([
            'order:id,order_number,customer_email,game_id,package_id',
            'order.game:id,name',
            'order.package:id,name',
        ]);

        if ($request->filled('status')) {
            $listQuery->where('status', $request->query('status'));
        }

        $perPage = (int) $request->query('per_page', 25);

        return response()->json([
            'stats' => [
                'pending' => (clone $statsQuery)->where('status', ReviewStatus::Pending->value)->count(),
                'approved' => (clone $statsQuery)->where('status', ReviewStatus::Approved->value)->count(),
                'rejected' => (clone $statsQuery)->where('status', ReviewStatus::Rejected->value)->count(),
                'total' => (clone $statsQuery)->count(),
                'average_rating' => round((clone $statsQuery)->where('status', ReviewStatus::Approved->value)->avg('rating') ?? 0, 2),
            ],
            'reviews' => $listQuery->orderBy('created_at', 'desc')->paginate($perPage)->withQueryString(),
        ]);
    }

    public function approve(Review $review): JsonResponse
    {
        $review->update(['status' => ReviewStatus::Approved->value]);
        $review->loadMissing('order:id,affiliate_id,game_id');
        ReviewCatalogController::forgetCache($review->order?->affiliate_id, $review->order?->game_id);

        return response()->json($review->load(['order:id,order_number,customer_email,game_id,package_id', 'order.game:id,name', 'order.package:id,name']));
    }

    public function reject(Review $review): JsonResponse
    {
        $review->update(['status' => ReviewStatus::Rejected->value]);
        $review->loadMissing('order:id,affiliate_id,game_id');
        ReviewCatalogController::forgetCache($review->order?->affiliate_id, $review->order?->game_id);

        return response()->json($review->load(['order:id,order_number,customer_email,game_id,package_id', 'order.game:id,name', 'order.package:id,name']));
    }

    /**
     * REV-4 — bulk-approves every currently pending review. No
     * confirmation gate at this layer (the admin UI's own confirm
     * dialog is the friction point) — approving is non-destructive and
     * reversible via a subsequent individual reject.
     */
    public function bulkApprove(): JsonResponse
    {
        // Capture which brands the about-to-be-approved reviews belong to
        // BEFORE the update, so every affected storefront's homepage cache
        // is busted — not just the primary's. `null` (a primary-brand
        // order) is normalised to the primary id inside `forgetCache()`.
        $affiliateIds = Review::query()
            ->where('reviews.status', ReviewStatus::Pending->value)
            ->join('orders', 'reviews.order_id', '=', 'orders.id')
            ->distinct()
            ->pluck('orders.affiliate_id');

        $count = Review::query()->where('status', ReviewStatus::Pending->value)->update(['status' => ReviewStatus::Approved->value]);

        foreach ($affiliateIds->push(null)->unique() as $affiliateId) {
            ReviewCatalogController::forgetCache($affiliateId);
        }

        return response()->json(['approved_count' => $count]);
    }
}
