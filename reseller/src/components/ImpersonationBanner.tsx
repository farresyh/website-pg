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
    <div role="status" className="flex flex-wrap items-center justify-between gap-2 border-b border-warning-ink/30 bg-warning-surface px-4 py-2 text-sm font-medium text-warning-ink lg:px-8">
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
        className="min-h-9 rounded-md border border-warning-ink/40 bg-surface px-3 text-xs font-semibold text-ink hover:bg-subtle disabled:opacity-45"
      >
        {exiting ? "Exiting…" : "Exit impersonation"}
      </button>
    </div>
  );
}
