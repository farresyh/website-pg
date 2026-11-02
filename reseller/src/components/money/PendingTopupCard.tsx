"use client";

/**
 * ADR-135 decisions 1, 2, 5: the one unpaid top-up, shown in place of the
 * Add Balance form. Pay now asks the server for the link (verified with CHIP),
 * Cancel asks CHIP to cancel; neither decides anything in the browser.
 */

import { useState } from "react";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import {
  cancelTopup,
  resumeTopup,
  type PendingTopup,
} from "@/lib/reseller-portal";
import { dateAndTime, formatRm } from "@/lib/format";
import { CardHead, Facts } from "@/components/composites";
import { notify } from "@/components/ui/toast";
import { Button } from "@/components/ui/button";
import { PortalIcon } from "@/components/shell/PortalIcon";

export default function PendingTopupCard({
  topup,
  onChanged,
}: {
  topup: PendingTopup;
  onChanged: () => void;
}) {
  const [busy, setBusy] = useState<"pay" | "cancel" | null>(null);
  const [confirming, setConfirming] = useState(false);
  const expires = dateAndTime(topup.expires_at).time;

  async function pay() {
    const session = getClientSession();
    if (!session) return;
    setBusy("pay");
    try {
      window.location.href = (
        await resumeTopup(session.token, topup.reference)
      ).checkout_url;
      return;
    } catch (err) {
      notify.stickyError(
        "Could not open the payment",
        err instanceof ApiError ? err.message : "Please try again.",
      );
      if (err instanceof ApiError && err.status === 409) onChanged();
    }
    setBusy(null);
  }

  async function cancel() {
    const session = getClientSession();
    if (!session) return;
    setBusy("cancel");
    try {
      const result = await cancelTopup(session.token, topup.reference);
      notify.success(result.message);
      onChanged();
      return;
    } catch (err) {
      // A 409 is CHIP's own answer (already paid, still processing, already ended): say it, then re-read.
      notify.warn(
        "Top-up not cancelled",
        err instanceof ApiError
          ? err.message
          : "Could not cancel right now. Please try again.",
      );
      onChanged();
    }
    setBusy(null);
    setConfirming(false);
  }

  return (
    <>
      <CardHead
        title="Payment waiting"
        description={
          topup.has_link
            ? `You have an unpaid top-up. Pay it, or cancel it to start a new one. It closes by itself at ${expires}.`
            : `Your top-up is still being set up. It closes by itself at ${expires}, then you can start a new one.`
        }
      />
      <div className="flex flex-col gap-4 px-5 pb-5">
        <Facts
          columns={2}
          items={[
            {
              label: "Adds to your balance",
              value: formatRm(topup.amount_sen),
            },
            {
              label: "Charged incl. payment fee",
              value: formatRm(topup.total_charged_sen),
            },
            { label: "Reference", value: topup.reference },
            { label: "Closes at", value: expires },
          ]}
        />
        {topup.has_link && (
          <div className="flex flex-col gap-2">
            <Button
              type="button"
              onClick={pay}
              disabled={busy !== null}
              className="h-11 w-full text-sm"
            >
              <PortalIcon name="shield" className="h-4 w-4" />
              {busy === "pay" ? "Opening secure payment…" : "Pay now"}
            </Button>
            {confirming ? (
              <div className="flex flex-col gap-2 rounded-md border border-border px-3.5 py-3">
                <p className="text-[13px] text-ink">
                  Cancel this top-up? Its payment link will stop working.
                </p>
                <div className="grid grid-cols-2 gap-2">
                  <Button
                    type="button"
                    severity="danger"
                    onClick={cancel}
                    disabled={busy !== null}
                  >
                    {busy === "cancel" ? "Cancelling…" : "Yes, cancel it"}
                  </Button>
                  <Button
                    type="button"
                    severity="secondary"
                    onClick={() => setConfirming(false)}
                    disabled={busy !== null}
                  >
                    Keep it
                  </Button>
                </div>
              </div>
            ) : (
              <Button
                type="button"
                severity="secondary"
                onClick={() => setConfirming(true)}
                disabled={busy !== null}
                className="w-full"
              >
                Cancel this top-up
              </Button>
            )}
          </div>
        )}
      </div>
    </>
  );
}
