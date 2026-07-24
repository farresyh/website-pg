"use client";

/**
 * Proves the auth loop closes end-to-end: reads the Bearer token stashed by
 * login/page.tsx and calls GET /api/me directly from the browser (ADR-009).
 * DASH-1..DASH-6 (docs/prd.md §6.2) come later — this is deliberately just
 * "who am I", scoped to what auth alone unlocks.
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { apiFetch, ApiError } from "@/lib/api-client";
import { getClientSession, clearClientSession } from "@/lib/session";

interface Admin {
  id: number;
  name: string;
  email: string;
  role: "super_admin" | "admin";
}

export default function AdminDashboardPage() {
  const router = useRouter();
  const [admin, setAdmin] = useState<Admin | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const session = getClientSession();

    if (!session) {
      router.replace("/login");
      return;
    }

    apiFetch<Admin>("/api/me", { token: session.token })
      .then(setAdmin)
      .catch((err: unknown) => {
        clearClientSession();
        setError(err instanceof ApiError ? err.message : "Session expired.");
        router.replace("/login");
      });
  }, [router]);

  if (error) {
    return <p className="text-sm text-red-600 dark:text-red-400">{error}</p>;
  }

  if (!admin) {
    return <p className="text-sm text-black/60 dark:text-white/60">Loading…</p>;
  }

  return (
    <div>
      <h1 className="text-xl font-semibold">Dashboard</h1>
      <p className="mt-2 text-sm text-black/60 dark:text-white/60">
        Signed in as {admin.name} ({admin.role}).
      </p>
      <p className="mt-4 text-sm text-black/60 dark:text-white/60">
        Placeholder — wire up DASH-1..DASH-6 once Laravel API endpoints
        exist.
      </p>
    </div>
  );
}
