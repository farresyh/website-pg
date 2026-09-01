"use client";

/**
 * ADR-053 — the popup this ADR's decision 3 describes. Deliberately
 * named distinctly from ReviewModal.tsx (the unrelated checkout
 * order-confirmation step, ADR-053's own naming-collision watch) —
 * this is a product/game rating, not an order review before payment.
 */

import { useState } from "react";
import { Star, X } from "@phosphor-icons/react/dist/ssr";
import Button from "@/components/ui/Button";
import { ApiError } from "@/lib/api-client";
import { submitReview } from "@/lib/review";

export default function RateOrderModal({
  orderNumber,
  onClose,
  onSubmitted,
}: {
  orderNumber: string;
  onClose: () => void;
  onSubmitted: () => void;
}) {
  const [rating, setRating] = useState(0);
  const [hoverRating, setHoverRating] = useState(0);
  const [comment, setComment] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit() {
    if (rating < 1) return;
    setSubmitting(true);
    setError(null);
    try {
      await submitReview(orderNumber, rating, comment.trim() || undefined);
      onSubmitted();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Something went wrong — try again in a moment.");
    } finally {
      setSubmitting(false);
    }
  }

  const displayRating = hoverRating || rating;

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-ink/50 lg:items-center" onClick={onClose}>
      <div
        className="flex w-full flex-col overflow-y-auto rounded-t-lg border-2 border-ink bg-surface neo-lg p-6 lg:max-w-[420px] lg:rounded-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="mb-4 flex items-center justify-between border-b-2 border-ink pb-3">
          <h2 className="font-display text-xl font-bold uppercase tracking-tight">Rate Your Order</h2>
          <button onClick={onClose} aria-label="Close" className="flex h-11 w-11 items-center justify-center rounded-md border-2 border-ink hover:bg-surface-container">
            <X size={18} />
          </button>
        </div>

        <p className="mb-4 text-sm text-on-surface-variant">How was your top-up experience?</p>

        <div className="mb-4 flex items-center justify-center gap-1" onMouseLeave={() => setHoverRating(0)}>
          {[1, 2, 3, 4, 5].map((value) => (
            <button
              key={value}
              type="button"
              aria-label={`${value} star${value > 1 ? "s" : ""}`}
              onMouseEnter={() => setHoverRating(value)}
              onClick={() => setRating(value)}
              className="p-1"
            >
              <Star size={32} weight={value <= displayRating ? "fill" : "regular"} className={value <= displayRating ? "text-warning" : "text-on-surface-variant"} />
            </button>
          ))}
        </div>

        <textarea
          value={comment}
          onChange={(e) => setComment(e.target.value)}
          placeholder="Leave a comment (optional)"
          rows={3}
          className="mb-4 w-full rounded-md border-2 border-ink bg-surface-container-lowest p-3 text-sm text-on-surface placeholder:text-outline focus:border-secondary focus:outline-none"
        />

        {error && <p className="mb-4 text-sm text-danger">{error}</p>}

        <Button onClick={handleSubmit} disabled={submitting || rating < 1} className="w-full">
          {submitting ? "Submitting…" : "Submit Review"}
        </Button>
      </div>
    </div>
  );
}
