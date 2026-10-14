"use client";

/**
 * AUTH-1/AUTH-2 + ADR-121: email and password, then — for an account that
 * requires MFA — either first-time setup (scan the QR, confirm a code, save
 * the recovery codes) or a code from the authenticator app. The browser
 * logs in against Laravel directly; see lib/login.ts.
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api-client";
import { login, resumeSession, submitLoginCode, type LoginStep } from "@/lib/login";

type Screen =
  | { kind: "credentials" }
  | { kind: "challenge" }
  | { kind: "setup"; secret: string; qrSvg: string }
  | { kind: "recovery"; codes: string[] };

const inputClass = "w-full rounded border border-black/10 px-3 py-2 text-sm dark:border-white/15";

export default function LoginPage() {
  const router = useRouter();
  const [screen, setScreen] = useState<Screen>({ kind: "credentials" });
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [code, setCode] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  // A live API session (another tab, or the gate cookie was cleared) skips the form.
  useEffect(() => {
    resumeSession().then((alive) => {
      if (alive) router.replace("/admin");
    });
  }, [router]);

  function next(step: LoginStep) {
    setCode("");
    if (step.kind === "done") {
      if (step.recoveryCodes?.length) setScreen({ kind: "recovery", codes: step.recoveryCodes });
      else router.push("/admin");
    } else {
      setScreen(step);
    }
  }

  async function run(action: () => Promise<LoginStep>) {
    setError(null);
    setSubmitting(true);
    try {
      next(await action());
    } catch (e) {
      const message = e instanceof ApiError ? e.message : "Could not reach the server. Please try again.";
      setError(message);
      if (message.includes("expired")) setScreen({ kind: "credentials" });
    } finally {
      setSubmitting(false);
    }
  }

  function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    run(() => (screen.kind === "credentials" ? login(email, password) : submitLoginCode(code)));
  }

  return (
    <main className="flex min-h-screen items-center justify-center p-8">
      <form
        onSubmit={handleSubmit}
        className="w-full max-w-sm space-y-4 rounded-lg border border-black/10 p-6 dark:border-white/15"
      >
        <h1 className="text-lg font-semibold">
          {screen.kind === "setup"
            ? "Set up two-factor sign-in"
            : screen.kind === "recovery"
              ? "Save your recovery codes"
              : "Sign in"}
        </h1>

        {error && (
          <p role="alert" className="rounded bg-red-500/10 px-3 py-2 text-sm text-red-600 dark:text-red-400">
            {error}
          </p>
        )}

        {screen.kind === "credentials" && (
          <>
            <div className="space-y-1">
              <label htmlFor="email" className="text-sm font-medium">
                Email
              </label>
              <input
                id="email"
                type="email"
                autoComplete="username"
                required
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                className={inputClass}
              />
            </div>
            <div className="space-y-1">
              <label htmlFor="password" className="text-sm font-medium">
                Password
              </label>
              <input
                id="password"
                type="password"
                autoComplete="current-password"
                required
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                className={inputClass}
              />
            </div>
          </>
        )}

        {screen.kind === "setup" && (
          <div className="space-y-3 text-sm">
            <p>Scan this QR code with Google Authenticator on your own phone, then enter the 6-digit code it shows.</p>
            {/* eslint-disable-next-line @next/next/no-img-element -- inline SVG data URL from our own API */}
            <img
              src={`data:image/svg+xml;utf8,${encodeURIComponent(screen.qrSvg)}`}
              alt="QR code for Google Authenticator"
              width={200}
              height={200}
              className="mx-auto rounded bg-white p-2"
            />
            <p className="text-xs text-black/60 dark:text-white/60">
              Can&apos;t scan? Enter this key instead:{" "}
              <code className="break-all select-all font-mono">{screen.secret}</code>
            </p>
          </div>
        )}

        {(screen.kind === "setup" || screen.kind === "challenge") && (
          <div className="space-y-1">
            <label htmlFor="code" className="text-sm font-medium">
              {screen.kind === "setup" ? "6-digit code" : "Code from your authenticator app, or a recovery code"}
            </label>
            <input
              id="code"
              autoComplete="one-time-code"
              inputMode={screen.kind === "setup" ? "numeric" : "text"}
              required
              autoFocus
              value={code}
              onChange={(event) => setCode(event.target.value)}
              className={`${inputClass} font-mono tracking-widest`}
            />
          </div>
        )}

        {screen.kind === "recovery" ? (
          <div className="space-y-3 text-sm">
            <p>
              Each code signs you in once if you lose your phone. They are shown only now — store them somewhere safe,
              away from your phone.
            </p>
            <ul className="grid grid-cols-2 gap-2 rounded border border-black/10 p-3 font-mono dark:border-white/15">
              {screen.codes.map((c) => (
                <li key={c} className="select-all">
                  {c}
                </li>
              ))}
            </ul>
            <Button type="button" className="w-full" onClick={() => router.push("/admin")}>
              I&apos;ve saved them — continue
            </Button>
          </div>
        ) : (
          <Button type="submit" disabled={submitting} className="w-full">
            {submitting ? "Checking…" : screen.kind === "credentials" ? "Sign in" : "Verify"}
          </Button>
        )}
      </form>
    </main>
  );
}
