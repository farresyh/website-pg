"use client";

/**
 * ADR-058 2026-11-12 addendum, decision 1: a portal user (Reseller or
 * Affiliate) asks for a reset link. The server answers the same whatever
 * the email, so this page shows that one answer and never says whether an
 * account exists. The link opens `/set-password`, like the invite.
 */

import { useState } from "react";
import Link from "next/link";
import AuthLayout from "@/components/shell/AuthLayout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { apiFetch, ApiError } from "@/lib/api-client";

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState("");
  const [sent, setSent] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const result = await apiFetch<{ message: string }>("/api/affiliate/forgot-password", { method: "POST", body: { email } });
      setSent(result.message);
    } catch (err) {
      setError(err instanceof ApiError && err.status === 429 ? "Too many requests. Wait a minute and try again." : "Could not reach the server. Please try again.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <AuthLayout>
      <div className="flex flex-col gap-4">
        <div>
          <h2 className="text-[22px] font-[650] leading-7 text-ink">Forgot your password?</h2>
          <p className="mt-1 text-[13px] text-ink-muted">Enter the email you sign in with. We&apos;ll email you a link to choose a new password.</p>
        </div>

        {sent ? (
          <p role="status" className="rounded-md bg-success-surface px-3 py-2 text-sm text-success-ink">
            {sent} Check your spam folder too.
          </p>
        ) : (
          <form onSubmit={handleSubmit} className="flex flex-col gap-4">
            {error && (
              <p role="alert" className="rounded-md bg-danger-surface px-3 py-2 text-sm text-danger-ink">
                {error}
              </p>
            )}
            <div className="flex flex-col gap-1.5">
              <label htmlFor="email" className="text-[13px] font-semibold text-ink">
                Email
              </label>
              <Input id="email" type="email" required autoComplete="email" value={email} onChange={(event) => setEmail(event.target.value)} className="sm:min-h-11" />
            </div>
            <Button type="submit" disabled={submitting} className="h-11 w-full text-sm">
              {submitting ? "Sending…" : "Email me a reset link"}
            </Button>
          </form>
        )}

        <Link href="/login" className="text-center text-[13px] font-semibold text-cyan-ink hover:underline">
          Back to sign in
        </Link>
      </div>
    </AuthLayout>
  );
}
