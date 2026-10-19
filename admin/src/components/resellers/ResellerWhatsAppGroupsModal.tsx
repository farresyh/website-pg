"use client";

/**
 * ADR-075 / PR-F build addendum decision 3: link/unlink a WhatsApp
 * group to this Reseller (Bot channel account identity). The linking UI
 * is WhatsAppGroupLinker, shared with manual suppliers (ADR-125).
 */

import React from "react";
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
import WhatsAppGroupLinker from "@/components/whatsapp/WhatsAppGroupLinker";
import {
  listResellerWhatsAppGroups,
  linkResellerWhatsAppGroup,
  updateResellerWhatsAppGroupStatus,
  type ResellerRow,
} from "@/lib/resellers";

interface Props {
  isOpen: boolean;
  onClose: () => void;
  reseller: ResellerRow;
}

export default function ResellerWhatsAppGroupsModal({ isOpen, onClose, reseller }: Props) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-2xl">
            <DialogHeader>
              <DialogTitle>{reseller.business_name} — WhatsApp Groups</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && (
                <WhatsAppGroupLinker
                  key={reseller.id}
                  ownerNoun="reseller"
                  loadGroups={() => listResellerWhatsAppGroups(reseller.id)}
                  link={(groupId) => linkResellerWhatsAppGroup(reseller.id, groupId)}
                  setActive={(groupId, isActive) => updateResellerWhatsAppGroupStatus(reseller.id, groupId, isActive)}
                />
              )}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
