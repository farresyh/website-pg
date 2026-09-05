"use client";

/**
 * ADR-058 58a reseller-portal login. Wired to Laravel's
 * POST /api/affiliate/login via the `/api/login` Route Handler (which
 * also sets the optimistic-gate cookie — see lib/auth.ts). MFA is
 * descoped for the reseller portal (matches admin AUTH-7 — ADR-059
 * session decision), so there is no second step here.
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { setClientSession } from "@/lib/session";

export default function LoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    // e.g. after `/set-password` redirects here on success (ADR-058
    // set-password addendum decision 3). Read from the query string
    // directly so the page needs no `useSearchParams` Suspense boundary;
    // async IIFE keeps clear of `react-hooks/set-state-in-effect`.
    (async () => {
      const message = new URLSearchParams(window.location.search).get("message");
      if (!message) return;
      setNotice(message);
      window.history.replaceState(null, "", window.location.pathname);
    })();
  }, []);

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    setSubmitting(true);

    try {
      const response = await fetch("/api/login", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email, password }),
      });

      const payload = await response.json().catch(() => null);

      if (!response.ok) {
        setError(
          payload?.errors?.email?.[0] ?? payload?.message ?? "Login failed.",
        );
        return;
      }

      setClientSession({
        token: payload.token,
        affiliate_user_id: payload.affiliate_user.id,
        owner_type: payload.affiliate_user.owner_type,
        owner_id: payload.affiliate_user.owner_id,
        name: payload.affiliate_user.name,
        email: payload.affiliate_user.email,
        // ADR-072 decision 5 / PR-G: exactly one of these two is
        // populated depending on owner_type (never both).
        business_name: payload.affiliate?.business_name ?? payload.reseller?.business_name ?? "",
      });
      router.push("/dashboard");
    } catch {
      setError("Could not reach the server. Please try again.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <main className="flex min-h-screen items-center justify-center bg-gray-50 p-8 dark:bg-gray-900">
      <form
        onSubmit={handleSubmit}
        className="w-full max-w-sm space-y-5 rounded-2xl border border-gray-200 bg-white p-8 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]"
      >
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">
            Reseller Portal
          </h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Sign in to your partner account.
          </p>
        </div>

        {notice && (
          <p className="rounded-lg bg-success-50 px-3 py-2 text-sm text-success-600 dark:bg-success-500/15 dark:text-success-400">
            {notice}
          </p>
        )}

        {error && (
          <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
            {error}
          </p>
        )}

        <div className="space-y-1.5">
          <label htmlFor="email" className="text-theme-sm font-medium text-gray-700 dark:text-gray-300">
            Email
          </label>
          <input
            id="email"
            type="email"
            required
            autoComplete="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-theme-sm text-gray-800 outline-none focus:border-brand-400 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
          />
        </div>
        <div className="space-y-1.5">
          <label htmlFor="password" className="text-theme-sm font-medium text-gray-700 dark:text-gray-300">
            Password
          </label>
          <input
            id="password"
            type="password"
            required
            autoComplete="current-password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-theme-sm text-gray-800 outline-none focus:border-brand-400 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
          />
        </div>
        <button
          type="submit"
          disabled={submitting}
          className="w-full rounded-lg bg-brand-500 py-2.5 text-theme-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
        >
          {submitting ? "Signing in…" : "Sign in"}
        </button>
      </form>
    </main>
  );
}
