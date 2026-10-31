"use client";

import { useEffect, useState } from "react";
import { getClientSession } from "@/lib/session";
import { getOnboarding } from "@/lib/portal";
import type { Onboarding, OwnerType } from "@/lib/onboarding";

/**
 * ADR-133: the account's checklist, or null while it loads or if it cannot
 * be read. A failure is silent on purpose: the checklist and the banners are
 * help, never a reason to break the screen they sit on.
 */
export function useOnboarding(ownerType: OwnerType | undefined): Onboarding | null {
  const [onboarding, setOnboarding] = useState<Onboarding | null>(null);

  useEffect(() => {
    const session = getClientSession();
    if (!session || !ownerType) return;
    let cancelled = false;
    getOnboarding(session.token, ownerType)
      .then((data) => {
        if (!cancelled) setOnboarding(data);
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, [ownerType]);

  return onboarding;
}
