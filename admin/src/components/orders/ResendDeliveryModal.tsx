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
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { SimpleSelect } from "@/components/ui/select";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api-client";
import { listGamePackages, type GamePackage } from "@/lib/games";
import { resendOrderDelivery, validatePlayerForResend, type OrderDetail } from "@/lib/orders";
import { resendSandboxOrderDelivery } from "@/lib/sandboxOrders";

interface ResendDeliveryModalProps {
  isOpen: boolean;
  onClose: () => void;
  onResent: () => void;
  order: OrderDetail;
  token: string;
  /**
   * ADR-018 decision #5/#8: this modal is shared between /admin/orders
   * and /middleware/sandbox — sandbox mode swaps the target endpoint
   * and adds the outcome picker (simulate success, or failure with a
   * canned error) that FakeSupplierAdapter needs. Everything else
   * (package picker, live cost reconciliation, player-ID re-validation
   * gate) behaves identically in both contexts.
   */
  sandbox?: boolean;
}

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

/**
 * ADR-017, merged with ORD-7's original plain retry (founder feedback,
 * 2026-07-27 — two separate buttons for "fix a failed delivery" was
 * more confusing than useful). The package picker **defaults to the
 * order's own package** — submitting with no change reproduces the
 * old "Retry Delivery" behavior exactly, just routed through the
 * richer resend flow (same Game only, decision #1; live cost-price
 * reconciliation, decision #3; optional Player ID re-validation,
 * decision #6). Renders as a child of <Modal>, which unmounts while
 * closed — fresh state every open, same convention as CreateValidatorModal.
 */
function ResendDeliveryFields({ onClose, onResent, order, token, sandbox }: Omit<ResendDeliveryModalProps, "isOpen">) {
  const [packages, setPackages] = useState<GamePackage[] | null>(null);
  const [packagesError, setPackagesError] = useState<string | null>(null);
  const [packageId, setPackageId] = useState<number | null>(null);
  const [note, setNote] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // ADR-018 decision #5: only meaningful in sandbox mode — the admin
  // picks what FakeSupplierAdapter should return this attempt.
  const [simulateSuccess, setSimulateSuccess] = useState(true);
  const [errorCode, setErrorCode] = useState("");
  const [errorMessage, setErrorMessage] = useState("");

  const [verifying, setVerifying] = useState(false);
  const [verifyResult, setVerifyResult] = useState<"valid" | "invalid" | null>(null);

  const gameId = order.game?.id ?? null;
  const originalPackageId = order.package?.id ?? null;
  const requiresPlayerValidation = Boolean(order.game?.player_validator_enabled && order.game?.player_validator_profile_id);

  useEffect(() => {
    if (!gameId) return;
    listGamePackages(token, gameId)
      .then((all) => {
        const active = all.filter((p) => p.is_active);
        setPackages(active);
        // Default to the order's own package — a same-package submit
        // is the "just retry" case; the order's own package is
        // deliberately not present in `active` when it was
        // deactivated since the order failed (decision #1's own
        // active-package guard), in which case this leaves packageId
        // unset and the "original package no longer active" notice
        // below prompts the admin to pick a replacement instead.
        if (originalPackageId !== null && active.some((p) => p.id === originalPackageId)) {
          setPackageId(originalPackageId);
        }
      })
      .catch((err) => setPackagesError(err instanceof ApiError ? err.message : "Could not load packages for this game."));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [gameId]);

  const selectedPackage = packages?.find((p) => p.id === packageId) ?? null;
  const priceDiff = selectedPackage ? selectedPackage.cost_price - order.cost_price : null;
  const isSamePackage = packageId !== null && packageId === originalPackageId;
  const originalPackageNoLongerActive = packages !== null && originalPackageId !== null && !packages.some((p) => p.id === originalPackageId);

  async function handleVerify() {
    if (!gameId) return;
    setVerifying(true);
    setVerifyResult(null);
    setError(null);
    try {
      const result = await validatePlayerForResend(gameId, order.player_id, order.server_id);
      setVerifyResult(result.status === "valid" ? "valid" : "invalid");
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not verify this Player ID.");
    } finally {
      setVerifying(false);
    }
  }

  const blockedOnValidation = requiresPlayerValidation && verifyResult !== "valid";
  const canSubmit = packageId !== null && !blockedOnValidation && !submitting;

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!packageId) return;
    setSubmitting(true);
    setError(null);
    try {
      if (sandbox) {
        await resendSandboxOrderDelivery(token, order.id, {
          package_id: packageId,
          note: note.trim() || undefined,
          simulate_success: simulateSuccess,
          error_code: !simulateSuccess ? errorCode.trim() || undefined : undefined,
          error_message: !simulateSuccess ? errorMessage.trim() || undefined : undefined,
        });
      } else {
        await resendOrderDelivery(token, order.id, { package_id: packageId, note: note.trim() || undefined });
      }
      onResent();
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not queue the resend.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      <p className="mb-5 text-sm text-gray-500 dark:text-gray-400">
        Defaults to resending the same package this order already has — change the selection below only if you want to
        deliver a different package from the same game (<span className="font-medium">{order.game?.name ?? "—"}</span>).
        Any live cost difference is absorbed by the platform and recorded, never re-charged to the customer.
      </p>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}
      {packagesError && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {packagesError}
        </p>
      )}
      {originalPackageNoLongerActive && (
        <p className="mb-4 rounded-lg bg-warning-50 px-3 py-2 text-sm text-warning-600 dark:bg-warning-500/15 dark:text-orange-400">
          This order&apos;s original package is no longer active — choose a replacement package below.
        </p>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label htmlFor="resend_package">Package</Label>
          {packages === null ? (
            <p className="text-sm text-gray-500 dark:text-gray-400">Loading packages…</p>
          ) : (
            <SimpleSelect
              id="resend_package"
              value={packageId !== null ? String(packageId) : ""}
              onChange={(value) => setPackageId(value ? Number(value) : null)}
              options={[
                { value: "", label: "Select a package…" },
                ...packages.map((p) => ({ value: String(p.id), label: `${p.name} — ${formatRm(p.cost_price)}` })),
              ]}
            />
          )}
        </div>

        {selectedPackage && priceDiff !== null && (
          <div className="rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/5">
            <div className="flex justify-between text-gray-500 dark:text-gray-400">
              <span>Original cost (snapshot)</span>
              <span>{formatRm(order.cost_price)}</span>
            </div>
            <div className="flex justify-between text-gray-500 dark:text-gray-400">
              <span>Live cost (this package now)</span>
              <span>{formatRm(selectedPackage.cost_price)}</span>
            </div>
            <div
              className={`mt-1 flex justify-between font-medium ${priceDiff > 0 ? "text-error-600 dark:text-error-400" : priceDiff < 0 ? "text-success-600 dark:text-success-400" : "text-gray-800 dark:text-white/90"}`}
            >
              <span>Platform absorbs</span>
              <span>
                {priceDiff > 0 ? "+" : ""}
                {formatRm(priceDiff)}
              </span>
            </div>
          </div>
        )}

        {requiresPlayerValidation && (
          <div className="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
            <p className="mb-2 text-sm text-gray-600 dark:text-gray-400">
              This game requires Player ID validation before resending — Player ID{" "}
              <span className="font-medium">{order.player_id}</span>
              {order.server_id ? ` / Server ${order.server_id}` : ""}.
            </p>
            <div className="flex items-center gap-3">
              <Button type="button" size="small" variant="outlined" onClick={handleVerify} disabled={verifying}>
                {verifying ? "Verifying…" : "Verify Player ID"}
              </Button>
              {verifyResult === "valid" && <span className="text-sm text-success-600 dark:text-success-400">Verified</span>}
              {verifyResult === "invalid" && <span className="text-sm text-error-600 dark:text-error-400">Not valid — cannot resend</span>}
            </div>
          </div>
        )}

        {sandbox && (
          <div className="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
            <Label>Simulated Outcome (sandbox only)</Label>
            <div className="mt-1 flex gap-2">
              <button
                type="button"
                onClick={() => setSimulateSuccess(true)}
                className={`rounded-lg px-3 py-1.5 text-sm ${simulateSuccess ? "bg-success-500 text-white" : "bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400"}`}
              >
                Simulate Success
              </button>
              <button
                type="button"
                onClick={() => setSimulateSuccess(false)}
                className={`rounded-lg px-3 py-1.5 text-sm ${!simulateSuccess ? "bg-error-500 text-white" : "bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400"}`}
              >
                Simulate Failure
              </button>
            </div>

            {!simulateSuccess && (
              <div className="mt-3 space-y-3">
                <div>
                  <Label htmlFor="sandbox_error_code">Error Code (Optional)</Label>
                  <Input id="sandbox_error_code" placeholder="e.g. insufficient_balance, or duplicate_reference to test needs_review (ADR-026)" value={errorCode} onChange={(e) => setErrorCode(e.target.value)} />
                </div>
                <div>
                  <Label htmlFor="sandbox_error_message">Error Message (Optional)</Label>
                  <Input id="sandbox_error_message" placeholder="e.g. Simulated insufficient supplier balance" value={errorMessage} onChange={(e) => setErrorMessage(e.target.value)} />
                </div>
              </div>
            )}
          </div>
        )}

        <div>
          <Label htmlFor="resend_note">Note (Optional)</Label>
          <Input id="resend_note" placeholder="e.g. Customer requested a bigger pack" value={note} onChange={(e) => setNote(e.target.value)} />
        </div>

        <div className="flex items-center justify-end gap-3 pt-2">
          <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" disabled={!canSubmit}>
            {submitting ? (sandbox ? "Submitting…" : "Queuing…") : isSamePackage ? "Retry Delivery" : "Resend Delivery"}
          </Button>
        </div>
      </form>
    </>
  );
}

export default function ResendDeliveryModal({ isOpen, onClose, onResent, order, token, sandbox }: ResendDeliveryModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-lg">
            <DialogHeader>
              <DialogTitle>Resend Delivery</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && (
                <ResendDeliveryFields onClose={onClose} onResent={onResent} order={order} token={token} sandbox={sandbox} />
              )}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
