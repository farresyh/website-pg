"use client";

/**
 * AUTH-1/AUTH-2: wired to Laravel Sanctum login via the app/api/login Route
 * Handler (server-side proxy, sets the optimistic-gate cookie — see
 * lib/auth.ts). MFA (AUTH-7) will need a second step here before the
 * session is granted; not built server-side yet, so not wired here.
 */

import { useState } from "react";
import { useRouter } from "next/navigation";
import { setClientSession } from "@/lib/session";

export default function LoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

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
        setError(payload?.message ?? "Login failed.");
        return;
      }

      setClientSession({
        token: payload.token,
        id: payload.admin.id,
        role: payload.admin.role,
        name: payload.admin.name,
        email: payload.admin.email,
      });
      router.push("/admin");
    } catch {
      setError("Could not reach the server. Please try again.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <main className="flex min-h-screen items-center justify-center p-8">
      <form
        onSubmit={handleSubmit}
        className="w-full max-w-sm space-y-4 rounded-lg border border-black/10 p-6 dark:border-white/15"
      >
        <h1 className="text-lg font-semibold">Sign in</h1>

        {error && (
          <p className="rounded bg-red-500/10 px-3 py-2 text-sm text-red-600 dark:text-red-400">
            {error}
          </p>
        )}

        <div className="space-y-1">
          <label htmlFor="email" className="text-sm font-medium">
            Email
          </label>
          <input
            id="email"
            type="email"
            required
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            className="w-full rounded border border-black/10 px-3 py-2 text-sm dark:border-white/15"
          />
        </div>
        <div className="space-y-1">
          <label htmlFor="password" className="text-sm font-medium">
            Password
          </label>
          <input
            id="password"
            type="password"
            required
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            className="w-full rounded border border-black/10 px-3 py-2 text-sm dark:border-white/15"
          />
        </div>
        <button
          type="submit"
          disabled={submitting}
          className="w-full rounded bg-foreground py-2 text-sm font-medium text-background disabled:opacity-50"
        >
          {submitting ? "Signing in…" : "Sign in"}
        </button>
      </form>
    </main>
  );
}
