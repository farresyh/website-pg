"use client";

import React from "react";
import {
  Dialog,
  DialogPortal,
  DialogBackdrop,
  DialogPositioner,
  DialogPopup,
  DialogHeader,
  DialogTitle,
  DialogContent,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";

/** ADR-121: freshly generated recovery codes, shown once. */
export default function RecoveryCodesDialog({ codes, onClose }: { codes: string[] | null; onClose: () => void }) {
  return (
    <Dialog open={codes !== null} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-sm">
            <DialogHeader>
              <DialogTitle>New recovery codes</DialogTitle>
            </DialogHeader>
            <DialogContent className="space-y-4 text-sm">
              <p>Your old codes no longer work. Each of these signs you in once; they are shown only now.</p>
              <ul className="grid grid-cols-2 gap-2 rounded border border-gray-200 p-3 font-mono dark:border-gray-800">
                {codes?.map((c) => (
                  <li key={c} className="select-all">
                    {c}
                  </li>
                ))}
              </ul>
              <div className="flex justify-end">
                <Button type="button" onClick={onClose}>
                  I&apos;ve saved them
                </Button>
              </div>
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
