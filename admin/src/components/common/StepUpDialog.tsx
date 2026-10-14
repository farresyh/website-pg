"use client";

import React, { useEffect, useRef, useState } from "react";
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
import { Times as CloseIcon } from "@primeicons/react/times";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { ApiError, registerStepUpPrompt } from "@/lib/api-client";
import { stepUp } from "@/lib/login";

/**
 * ADR-121 decision 5: when one of the five dangerous actions answers
 * `mfa_step_up_required`, api-client waits on this dialog. A correct code
 * unlocks those actions for 5 minutes and the original request is retried;
 * closing the dialog cancels it. Mounted once, in PanelShell.
 */
export default function StepUpDialog() {
  const [open, setOpen] = useState(false);
  const [code, setCode] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const pending = useRef<((ok: boolean) => void) | null>(null);

  useEffect(() => {
    registerStepUpPrompt(
      () =>
        new Promise<boolean>((resolve) => {
          pending.current?.(false);
          pending.current = resolve;
          setCode("");
          setError(null);
          setOpen(true);
        }),
    );
    return () => registerStepUpPrompt(null);
  }, []);

  function settle(ok: boolean) {
    pending.current?.(ok);
    pending.current = null;
    setOpen(false);
  }

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      await stepUp(code);
      settle(true);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "Could not verify the code. Try again.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={(e) => { if (!e.value) settle(false); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-sm">
            <DialogHeader>
              <DialogTitle>Confirm it&apos;s you</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              <form onSubmit={handleSubmit} className="space-y-4">
                <p className="text-sm">
                  This action needs a code from your authenticator app. It stays unlocked for 5 minutes.
                </p>
                {error && (
                  <p role="alert" className="text-sm text-red-600 dark:text-red-400">
                    {error}
                  </p>
                )}
                <div className="space-y-1">
                  <Label htmlFor="step_up_code">Code</Label>
                  <Input
                    id="step_up_code"
                    autoComplete="one-time-code"
                    autoFocus
                    required
                    value={code}
                    onChange={(e) => setCode(e.target.value)}
                    className="font-mono tracking-widest"
                  />
                </div>
                <div className="flex justify-end gap-2">
                  <Button type="button" variant="outlined" onClick={() => settle(false)} disabled={submitting}>
                    Cancel
                  </Button>
                  <Button type="submit" disabled={submitting}>
                    {submitting ? "Checking…" : "Confirm"}
                  </Button>
                </div>
              </form>
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
