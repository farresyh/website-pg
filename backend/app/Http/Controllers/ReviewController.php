<?php

namespace App\Http\Controllers;

use App\Http\Requests\Review\StoreReviewRequest;
use App\Models\Order;
use App\Models\Review;
use App\Services\Order\DeliveryStatus;
use App\Services\Review\ReviewStatus;
use App\Support\StorefrontBrand;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Public, guest-callable review submission (ADR-053, same no-auth
 * reasoning as CheckoutController/TrackOrderController — ADR-011).
 * `order_number` alone is the proof of ownership, identical trust
 * model to TrackOrderController. Gated on delivery_status=Delivered
 * (decision 1) and at most one review per order (decision 2,
 * reviews.order_id's own unique index is the real guarantee — this
 * controller's own check is the friendly-error layer in front of it).
 */
class ReviewController extends Controller
{
    public function store(StoreReviewRequest $request, string $orderNumber, StorefrontBrand $brand): JsonResponse
    {
        $order = Order::query()
            ->forStorefrontBrand($brand->get())
            ->where('order_number', $orderNumber)
            ->first();

        // Same 404 whether unknown or another brand's — a review can only
        // be left on the storefront the order was placed on (ADR-060).
        if ($order === null) {
            return response()->json(['message' => 'No order found with that order number.'], 404);
        }

        if ($order->delivery_status !== DeliveryStatus::Delivered) {
            throw ValidationException::withMessages([
                'order' => ['This order has not been delivered yet.'],
            ]);
        }

        if ($order->review !== null) {
            throw ValidationException::withMessages([
                'order' => ['This order already has a review.'],
            ]);
        }

        $data = $request->validated();

        try {
            $review = Review::query()->create([
                'order_id' => $order->id,
                'rating' => $data['rating'],
                'comment' => $data['comment'] ?? null,
                'status' => ReviewStatus::Pending->value,
            ]);
        } catch (UniqueConstraintViolationException) {
            // reviews.order_id's unique index is the real guarantee against
            // a genuine race — the check above is only the friendly-error
            // fast path (same shape as VoucherController::store()'s
            // idempotency_key handling).
            throw ValidationException::withMessages([
                'order' => ['This order already has a review.'],
            ]);
        }

        return response()->json(['id' => $review->id, 'status' => $review->status->value], 201);
    }
}
