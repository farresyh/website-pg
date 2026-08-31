"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { getClientSession } from "@/lib/session";
import PortalShell from "@/components/PortalShell";

/**
 * Client-side session guard. `proxy.ts` already redirects when the
 * optimistic-gate cookie is missing; this also covers the
 * cookie-present-but-sessionStorage-empty case (a fresh tab). Not a
 * security boundary — the Laravel `reseller` guard authorizes every API
 * call. Each screen also early-returns when `getClientSession()` is null,
 * so nothing fetches before the redirect lands.
 */
export default function PortalLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const router = useRouter();

  useEffect(() => {
    if (!getClientSession()) {
      router.replace("/login");
    }
  }, [router]);

  return <PortalShell>{children}</PortalShell>;
}
