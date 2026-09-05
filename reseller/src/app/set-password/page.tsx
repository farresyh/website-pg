"use client";

/**
 * ADR-058 decision 2 (set-password addendum, grilled 2026-09-05): the
 * acceptance page for the emailed invite link
 * `/set-password?token=…&email=…`. Serves both an `Affiliate` (whitelabel)
 * and a `Reseller` (wallet) invite identically — the `affiliate_users`
 * password broker operates purely on the row's email/token and has no
 * visibility into its polymorphic `owner_type` (addendum decision 4).
 *
 * No Route Handler proxy (addendum decision 2): unlike `/login`, this call
 * sets no cookie/session, so the page hits `POST /api/affiliate/set-password`
 * directly via `apiFetch`. On success it redirects to `/login` — the
 * endpoint returns no token, so there is no auto-login (addendum decision 3).
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { apiFetch, ApiError } from "@/lib/api-client";

// PasswordBroker collapses wrong / expired (24h) / already-used token into
// one identical 422 on the `email` field — there is nothing to distinguish
// server-side, so one generic message covers all three (addendum decision 1).
const INVALID_LINK_MESSAGE =
  "Link tidak sah atau sudah tamat tempoh. Sila hubungi admin untuk hantar invite baharu.";

export default function SetPasswordPage() {
  const router = useRouter();
  // `null` = still reading the URL; `"invalid"` = link missing token/email;
  // otherwise the parsed credentials. One state, set once from an async
  // IIFE in the effect — mirrors `/impersonate` and keeps clear of the
  // `react-hooks/set-state-in-effect` trap (ADR-038 decision 8).
  const [link, setLink] = useState<{ token: string; email: string } | "invalid" | null>(null);
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    // Read from the query string directly (mirrors `/impersonate`) so the
    // page needs no `useSearchParams` Suspense boundary.
    (async () => {
      const params = new URLSearchParams(window.location.search);
      const token = params.get("token");
      const email = params.get("email");
      setLink(token && email ? { token, email } : "invalid");
    })();
  }, []);

  const credentials = link && link !== "invalid" ? link : null;

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!credentials) return;
    setError(null);

    // Mirror the backend's own `min:8|confirmed` rule so a doomed request
    // never round-trips (addendum "Shape").
    if (password.length < 8) {
      setError("Kata laluan mesti sekurang-kurangnya 8 aksara.");
      return;
    }
    if (password !== passwordConfirmation) {
      setError("Pengesahan kata laluan tidak sepadan.");
      return;
    }

    setSubmitting(true);
    try {
      await apiFetch("/api/affiliate/set-password", {
        method: "POST",
        body: {
          token: credentials.token,
          email: credentials.email,
          password,
          password_confirmation: passwordConfirmation,
        },
      });
      router.push("/login?message=Password+berjaya+ditetapkan");
    } catch (err) {
      if (err instanceof ApiError) {
        // A weak/mismatched password comes back on the `password` field —
        // show Laravel's own message verbatim. Anything on the `email`
        // field is a bad/expired/used token — one generic message.
        const passwordError = err.errors?.password?.[0];
        setError(passwordError ?? INVALID_LINK_MESSAGE);
      } else {
        setError("Tidak dapat menghubungi pelayan. Sila cuba lagi.");
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <main className="flex min-h-screen items-center justify-center bg-gray-50 p-8 dark:bg-gray-900">
      <div className="w-full max-w-sm space-y-5 rounded-2xl border border-gray-200 bg-white p-8 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">
            Tetapkan Kata Laluan
          </h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Pilih kata laluan untuk akaun partner anda.
          </p>
        </div>

        {link === "invalid" && (
          <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
            {INVALID_LINK_MESSAGE}
          </p>
        )}

        {credentials && (
          <form onSubmit={handleSubmit} className="space-y-5">
            {error && (
              <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
                {error}
              </p>
            )}

            <div className="space-y-1.5">
              <label
                htmlFor="password"
                className="text-theme-sm font-medium text-gray-700 dark:text-gray-300"
              >
                Kata Laluan Baharu
              </label>
              <input
                id="password"
                type="password"
                required
                minLength={8}
                autoComplete="new-password"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-theme-sm text-gray-800 outline-none focus:border-brand-400 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
              />
            </div>
            <div className="space-y-1.5">
              <label
                htmlFor="password_confirmation"
                className="text-theme-sm font-medium text-gray-700 dark:text-gray-300"
              >
                Sahkan Kata Laluan
              </label>
              <input
                id="password_confirmation"
                type="password"
                required
                minLength={8}
                autoComplete="new-password"
                value={passwordConfirmation}
                onChange={(event) => setPasswordConfirmation(event.target.value)}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-theme-sm text-gray-800 outline-none focus:border-brand-400 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
              />
            </div>
            <button
              type="submit"
              disabled={submitting}
              className="w-full rounded-lg bg-brand-500 py-2.5 text-theme-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
            >
              {submitting ? "Menyimpan…" : "Tetapkan Kata Laluan"}
            </button>
          </form>
        )}
      </div>
    </main>
  );
}
