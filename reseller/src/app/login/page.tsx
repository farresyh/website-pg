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
import { useSupportContacts, whatsAppLink } from "@/hooks/useSupportContacts";
import { formatMalaysianPhone } from "@/lib/format";
import AuthLayout from "@/components/shell/AuthLayout";
import { buttonClass, inputClass } from "@/components/ui";

export default function LoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const support = useSupportContacts();

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
    <AuthLayout>
      <form onSubmit={handleSubmit} className="flex flex-col gap-4">
        <div>
          <h2 className="text-[22px] font-[650] leading-7 text-ink">Sign in</h2>
          <p className="mt-1 text-[13px] text-ink-muted">Use the email your PekanGame invite was sent to.</p>
        </div>

        {notice && <p className="rounded-md bg-success-surface px-3 py-2 text-sm text-success-ink">{notice}</p>}
        {error && (
          <p role="alert" className="rounded-md bg-danger-surface px-3 py-2 text-sm text-danger-ink">
            {error}
          </p>
        )}

        <div className="flex flex-col gap-1.5">
          <label htmlFor="email" className="text-[13px] font-semibold text-ink">
            Email
          </label>
          <input id="email" type="email" required autoComplete="email" value={email} onChange={(event) => setEmail(event.target.value)} className={`${inputClass} sm:min-h-11`} />
        </div>
        <div className="flex flex-col gap-1.5">
          <label htmlFor="password" className="text-[13px] font-semibold text-ink">
            Password
          </label>
          <input
            id="password"
            type="password"
            required
            autoComplete="current-password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            className={`${inputClass} sm:min-h-11`}
          />
        </div>
        <button type="submit" disabled={submitting} className={`${buttonClass("primary")} h-11 w-full text-sm`}>
          {submitting ? "Signing in…" : "Sign in"}
        </button>
        {support?.phone && (
          <p className="text-center text-xs leading-[18px] text-ink-subtle">
            Forgot your password, or no invite yet?{" "}
            <a href={whatsAppLink(support.phone)} target="_blank" rel="noreferrer" className="whitespace-nowrap font-semibold text-cyan-ink hover:underline">
              WhatsApp {formatMalaysianPhone(support.phone)}
            </a>
          </p>
        )}
      </form>
    </AuthLayout>
  );
}
