"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { setClientSession } from "@/lib/session";

/**
 * ADR-059 59c: entry point for an admin RES-4 impersonation session.
 * The admin opens this with `#token=<minted token>` in the URL hash
 * (hash, not query — it never reaches a server log or the Referer
 * header). We hand the token to `/api/impersonate`, which verifies it
 * and sets the gate cookie, then store the session and go to the
 * dashboard. The persistent banner (PortalShell) takes over from there.
 */
export default function ImpersonatePage() {
  const router = useRouter();
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const token = new URLSearchParams(window.location.hash.slice(1)).get("token");
    // Clear the token from the address bar immediately.
    window.history.replaceState(null, "", window.location.pathname);

    (async () => {
      if (!token) {
        setError("No impersonation token in the link.");
        return;
      }

      try {
        const response = await fetch("/api/impersonate", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ token }),
        });
        const payload = await response.json().catch(() => null);

        if (!response.ok) {
          setError(payload?.message ?? "Could not start the impersonation session.");
          return;
        }

        setClientSession({
          token: payload.token,
          reseller_user_id: payload.reseller_user.id,
          reseller_id: payload.reseller_user.reseller_id,
          name: payload.reseller_user.name,
          email: payload.reseller_user.email,
          business_name: payload.reseller?.business_name ?? "",
          impersonating: true,
          admin_name: payload.impersonation?.admin_name ?? null,
          impersonation_session_id: payload.impersonation?.session_id,
        });
        router.replace("/dashboard");
      } catch {
        setError("Could not reach the server.");
      }
    })();
  }, [router]);

  return (
    <main className="flex min-h-screen items-center justify-center bg-gray-50 p-8 dark:bg-gray-900">
      <div className="w-full max-w-sm rounded-2xl border border-gray-200 bg-white p-8 text-center dark:border-gray-800 dark:bg-white/[0.03]">
        {error ? (
          <>
            <p className="text-sm font-medium text-error-600 dark:text-error-400">
              {error}
            </p>
            <p className="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
              You can close this tab.
            </p>
          </>
        ) : (
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Starting impersonation session…
          </p>
        )}
      </div>
    </main>
  );
}
