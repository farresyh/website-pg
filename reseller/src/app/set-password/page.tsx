"use client";

import AuthLayout from "@/components/shell/AuthLayout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

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
import { clearClientSession, getClientSession } from "@/lib/session";

// PasswordBroker collapses wrong / expired (24h) / already-used token into
// one identical 422 on the `email` field — there is nothing to distinguish
// server-side, so one generic message covers all three (addendum decision 1).
const INVALID_LINK_MESSAGE =
  "This link is invalid or has expired. Please ask us to send you a new invite.";

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
  const [passwordSaved, setPasswordSaved] = useState(false);

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
    if (!passwordSaved && password.length < 8) {
      setError("Your password must be at least 8 characters.");
      return;
    }
    if (!passwordSaved && password !== passwordConfirmation) {
      setError("The two passwords do not match.");
      return;
    }

    setSubmitting(true);
    let saved = passwordSaved;
    try {
      if (!saved) {
        await apiFetch("/api/affiliate/set-password", {
          method: "POST",
          body: {
            token: credentials.token,
            email: credentials.email,
            password,
            password_confirmation: passwordConfirmation,
          },
        });
        saved = true;
        setPasswordSaved(true);
      }
      // An invite can be opened while another partner is signed in. Clear
      // that old token and the presence-only cookie before visiting /login;
      // otherwise proxy.ts redirects straight back to the old dashboard.
      const previousSession = getClientSession();
      const logoutResponse = await fetch("/api/logout", {
        method: "POST",
        headers: previousSession
          ? { Authorization: `Bearer ${previousSession.token}` }
          : {},
      });
      if (!logoutResponse.ok) {
        throw new Error("Could not clear the previous portal session.");
      }
      clearClientSession();
      router.replace("/login?message=Password+set.+You+can+now+sign+in.");
    } catch (err) {
      if (saved) {
        // An invite token is single-use: retry cookie cleanup, not the
        // already successful password request.
        setError("Your password is set, but the previous session could not be closed. Please try to continue again.");
      } else if (err instanceof ApiError) {
        // A weak/mismatched password comes back on the `password` field —
        // show Laravel's own message verbatim. Anything on the `email`
        // field is a bad/expired/used token — one generic message.
        const passwordError = err.errors?.password?.[0];
        setError(passwordError ?? INVALID_LINK_MESSAGE);
      } else {
        setError("Could not reach the server. Please try again.");
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <AuthLayout>
      <div className="flex flex-col gap-5">
        <div>
          <h2 className="text-[22px] font-[650] leading-7 text-ink">
            Set your password
          </h2>
          <p className="mt-1 text-[13px] text-ink-muted">
            Choose a password for your partner account.
          </p>
        </div>

        {link === "invalid" && (
          <p className="rounded-md bg-danger-surface px-3 py-2 text-sm text-danger-ink">
            {INVALID_LINK_MESSAGE}
          </p>
        )}

        {credentials && (
          <form onSubmit={handleSubmit} className="space-y-5">
            {error && (
              <p className="rounded-md bg-danger-surface px-3 py-2 text-sm text-danger-ink">
                {error}
              </p>
            )}

            {!passwordSaved && (
              <>
                <div className="space-y-1.5">
                  <label
                    htmlFor="password"
                    className="text-theme-sm font-medium text-ink-muted"
                  >
                    New password
                  </label>
                  <Input
                    id="password"
                    type="password"
                    required
                    minLength={8}
                    autoComplete="new-password"
                    value={password}
                    onChange={(event) => setPassword(event.target.value)}
                  />
                </div>
                <div className="space-y-1.5">
                  <label
                    htmlFor="password_confirmation"
                    className="text-theme-sm font-medium text-ink-muted"
                  >
                    Confirm password
                  </label>
                  <Input
                    id="password_confirmation"
                    type="password"
                    required
                    minLength={8}
                    autoComplete="new-password"
                    value={passwordConfirmation}
                    onChange={(event) => setPasswordConfirmation(event.target.value)}
                  />
                </div>
              </>
            )}
            <Button
              type="submit"
              disabled={submitting}
              className="h-11 w-full text-sm"
            >
              {submitting ? "Please wait…" : passwordSaved ? "Continue to sign in" : "Set password"}
            </Button>
          </form>
        )}
      </div>
    </AuthLayout>
  );
}
