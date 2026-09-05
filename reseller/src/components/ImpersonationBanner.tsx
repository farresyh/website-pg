"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useClientSession } from "@/hooks/useClientSession";
import { clearClientSession, getClientSession } from "@/lib/session";

/**
 * ADR-058 decision 4 / ADR-059 59c: shown above every screen whenever
 * the current session was started by an admin impersonating this
 * affiliate. "Exit" closes the impersonation session server-side
 * (revoking the token) and clears the local session.
 */
export default function ImpersonationBanner() {
  const session = useClientSession();
  const router = useRouter();
  const [exiting, setExiting] = useState(false);

  if (!session?.impersonating) return null;

  async function handleExit() {
    setExiting(true);
    const current = getClientSession();
    try {
      await fetch("/api/impersonate/end", {
        method: "POST",
        headers: current ? { Authorization: `Bearer ${current.token}` } : {},
      });
    } catch {
      // best-effort — clear locally regardless
    }
    clearClientSession();
    router.replace("/login");
  }

  return (
    <div className="flex flex-wrap items-center justify-between gap-2 bg-warning-500 px-4 py-2 text-theme-sm font-medium text-black">
      <span>
        Impersonating <strong>{session.business_name}</strong>
        {session.admin_name ? (
          <>
            {" "}
            — acting as <strong>{session.admin_name}</strong>
          </>
        ) : null}
      </span>
      <button
        type="button"
        onClick={handleExit}
        disabled={exiting}
        className="rounded-md bg-black/15 px-3 py-1 text-theme-xs hover:bg-black/25 disabled:opacity-50"
      >
        {exiting ? "Exiting…" : "Exit impersonation"}
      </button>
    </div>
  );
}
