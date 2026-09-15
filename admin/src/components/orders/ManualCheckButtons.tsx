"use client";

import { useEffect, useState } from "react";
import {
  Dialog,
  DialogPortal,
  DialogBackdrop,
  DialogPositioner,
  DialogPopup,
  DialogHeader,
  DialogHeaderActions,
  DialogClose,
  DialogTitle,
  DialogContent,
} from "@/components/ui/dialog";
import { CloseIcon } from "@/icons";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api-client";
import { checkOrderSupplier, checkOrderGateway, type OrderDetail, type ManualCheckResult } from "@/lib/orders";

type Kind = "supplier" | "gateway";

interface ManualCheckButtonsProps {
  order: OrderDetail;
  token: string;
  /** Re-fetches the full order after a check applies an outcome — same pattern as Refund to Wallet/Mark as Delivered. */
  onChecked: () => void;
}

/**
 * ADR-096 — "Check from Supplier" (delivery_status=pending) and "Check
 * from Gateway" (payment_status=pending), each a single synchronous
 * status-check call. A terminal outcome auto-applies immediately
 * (`onChecked()` re-fetches the order to reflect it); the raw response
 * is shown as a receipt in the modal either way. A 422 means the
 * per-order/per-supplier cooldown (decision 8) is still active — its
 * `retry_after_seconds` drives the button's own disabled countdown.
 */
export default function ManualCheckButtons({ order, token, onChecked }: ManualCheckButtonsProps) {
  const [loading, setLoading] = useState<Kind | null>(null);
  const [cooldown, setCooldown] = useState<Record<Kind, number>>({ supplier: 0, gateway: 0 });
  const [error, setError] = useState<{ kind: Kind; message: string } | null>(null);
  const [resultState, setResultState] = useState<{ kind: Kind; result: ManualCheckResult } | null>(null);

  useEffect(() => {
    if (cooldown.supplier <= 0 && cooldown.gateway <= 0) return;
    const interval = setInterval(() => {
      setCooldown((c) => ({
        supplier: Math.max(0, c.supplier - 1),
        gateway: Math.max(0, c.gateway - 1),
      }));
    }, 1000);
    return () => clearInterval(interval);
  }, [cooldown.supplier, cooldown.gateway]);

  async function handleCheck(kind: Kind) {
    setLoading(kind);
    setError(null);
    try {
      const response = kind === "supplier" ? await checkOrderSupplier(token, order.id) : await checkOrderGateway(token, order.id);
      setResultState({ kind, result: response.result });
      onChecked();
    } catch (err) {
      if (err instanceof ApiError && err.status === 422 && typeof err.payload?.retry_after_seconds === "number") {
        setCooldown((c) => ({ ...c, [kind]: err.payload!.retry_after_seconds as number }));
      }
      setError({ kind, message: err instanceof ApiError ? err.message : "Check failed." });
    } finally {
      setLoading(null);
    }
  }

  if (order.delivery_status !== "pending" && order.payment_status !== "pending") {
    return null;
  }

  return (
    <div className="mt-3 flex flex-wrap items-center gap-3">
      {order.delivery_status === "pending" && (
        <Button
          size="small"
          variant="outlined"
          disabled={loading === "supplier" || cooldown.supplier > 0}
          onClick={() => handleCheck("supplier")}
        >
          {loading === "supplier"
            ? "Checking…"
            : cooldown.supplier > 0
              ? `Check from Supplier (${cooldown.supplier}s)`
              : "Check from Supplier…"}
        </Button>
      )}
      {order.payment_status === "pending" && (
        <Button
          size="small"
          variant="outlined"
          disabled={loading === "gateway" || cooldown.gateway > 0}
          onClick={() => handleCheck("gateway")}
        >
          {loading === "gateway"
            ? "Checking…"
            : cooldown.gateway > 0
              ? `Check from Gateway (${cooldown.gateway}s)`
              : "Check from Gateway…"}
        </Button>
      )}
      {error && <span className="text-sm text-error-600 dark:text-error-400">{error.message}</span>}

      <Dialog open={resultState !== null} onOpenChange={(e) => { if (!e.value) setResultState(null); }}>
        <DialogPortal>
          <DialogBackdrop />
          <DialogPositioner>
            <DialogPopup className="w-full max-w-lg">
              <DialogHeader>
                <DialogTitle>{resultState?.kind === "supplier" ? "Supplier" : "Gateway"} Check Result</DialogTitle>
                <DialogHeaderActions>
                  <DialogClose aria-label="Close">
                    <CloseIcon className="h-5 w-5" />
                  </DialogClose>
                </DialogHeaderActions>
              </DialogHeader>
              <DialogContent>{resultState && <ManualCheckResultView result={resultState.result} />}</DialogContent>
            </DialogPopup>
          </DialogPositioner>
        </DialogPortal>
      </Dialog>
    </div>
  );
}

function ManualCheckOutcomeLine({ outcome, applied }: { outcome: string; applied: boolean }) {
  return (
    <p className="mb-2 text-sm font-medium text-gray-800 dark:text-white/90">
      Outcome: {outcome} {applied ? <span className="text-success-600 dark:text-success-400">(applied)</span> : <span className="text-gray-500 dark:text-gray-400">(unchanged)</span>}
    </p>
  );
}

function ManualCheckJson({ data, errorCode, errorMessage }: { data: unknown; errorCode?: string | null; errorMessage?: string | null }) {
  const body = data ?? (errorCode || errorMessage ? { error_code: errorCode, error_message: errorMessage } : {});

  return (
    <pre className="max-h-72 overflow-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-700 dark:bg-gray-900 dark:text-gray-300">
      {JSON.stringify(body, null, 2)}
    </pre>
  );
}

function ManualCheckResultView({ result }: { result: ManualCheckResult }) {
  if (result.type === "combo") {
    if (!result.legs || result.legs.length === 0) {
      return <p className="text-sm text-gray-500 dark:text-gray-400">No Pending leg left to check.</p>;
    }

    return (
      <div className="space-y-4">
        {result.legs.map((leg) => (
          <div key={leg.leg_number}>
            <p className="mb-1 text-xs font-medium uppercase tracking-wide text-gray-400">Leg {leg.leg_number}</p>
            <ManualCheckOutcomeLine outcome={leg.outcome} applied={leg.applied} />
            <ManualCheckJson data={leg.data} errorCode={leg.error_code} errorMessage={leg.error_message} />
          </div>
        ))}
      </div>
    );
  }

  return (
    <div>
      <ManualCheckOutcomeLine outcome={result.outcome ?? "unknown"} applied={result.applied ?? false} />
      <ManualCheckJson data={result.data} errorCode={result.error_code} errorMessage={result.error_message} />
    </div>
  );
}
