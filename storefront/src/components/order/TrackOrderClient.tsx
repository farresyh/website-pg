"use client";

import { useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { MagnifyingGlass } from "@phosphor-icons/react/dist/ssr";
import { ApiError } from "@/lib/api-client";
import { trackOrder } from "@/lib/track-order";
import Button from "@/components/ui/Button";

/**
 * A pure lookup form — on a successful match it hands off to
 * /order/status/[orderNumber] (the same rich tracker OrderForm
 * redirects to right after a real payment), rather than rendering its
 * own separate result view. One tracker UI, two entry points, per the
 * founder's own steer.
 */
export default function TrackOrderClient() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [orderNumber, setOrderNumber] = useState(searchParams.get("order_number") ?? "");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function lookup(value: string) {
    const trimmed = value.trim();
    if (!trimmed) return;
    setLoading(true);
    setError(null);
    try {
      await trackOrder(trimmed);
      router.push(`/order/status/${encodeURIComponent(trimmed)}`);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Something went wrong — try again in a moment.");
      setLoading(false);
    }
  }

  // Auto-lookup when arriving with ?order_number=... (e.g. a WhatsApp support link).
  useEffect(() => {
    const prefill = searchParams.get("order_number");
    if (prefill) lookup(prefill);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <div className="mx-auto max-w-[560px] px-4 py-10 lg:py-16">
      <h1 className="font-display mb-2 text-2xl tracking-wide">Track Order</h1>
      <p className="mb-6 text-sm text-text-muted">Enter your order number to check its payment and delivery status.</p>

      <form
        onSubmit={(e) => {
          e.preventDefault();
          lookup(orderNumber);
        }}
        className="mb-6 flex flex-col gap-2.5 sm:flex-row"
      >
        <div className="flex min-h-11 flex-1 items-center gap-2 rounded-lg border border-border bg-surface px-3.5">
          <MagnifyingGlass size={16} className="shrink-0 text-text-muted" />
          <input
            type="text"
            value={orderNumber}
            onChange={(e) => setOrderNumber(e.target.value)}
            placeholder="e.g. KRS-01J..."
            className="w-full bg-transparent text-sm text-text placeholder:text-text-muted focus:outline-none"
          />
        </div>
        <Button type="submit" disabled={loading || !orderNumber.trim()} className="justify-center">
          {loading ? "Searching…" : "Track Order"}
        </Button>
      </form>

      {error && <p className="rounded-lg border border-border bg-surface p-4 text-sm text-text-muted">{error}</p>}
    </div>
  );
}
