/**
 * ADR-121 — the admin login, MFA and logout calls. The browser talks to
 * Laravel directly so the API's httpOnly session cookie lands on the API
 * host; the Next routes under app/api only set or clear proxy.ts's
 * presence-only gate cookie.
 */

import { apiFetch, forgetCsrfToken } from "@/lib/api-client";
import { clearClientSession, setClientSession } from "@/lib/session";
import { toSessionPayload, type SessionPayload } from "@/lib/auth";

export type LoginStep =
  | { kind: "done"; recoveryCodes?: string[] }
  | { kind: "challenge" }
  | { kind: "setup"; secret: string; qrSvg: string };

interface LoginResponse {
  admin?: SessionPayload;
  recovery_codes?: string[];
  mfa?: "setup" | "challenge";
  setup?: { secret: string; qr_svg: string };
}

async function finish(response: LoginResponse): Promise<LoginStep> {
  if (response.admin) {
    // Login rotates the session id; the CSRF token is fetched fresh next time.
    forgetCsrfToken();
    setClientSession(toSessionPayload(response.admin));
    await fetch("/api/login", { method: "POST" });
    return { kind: "done", recoveryCodes: response.recovery_codes };
  }
  if (response.mfa === "setup" && response.setup) {
    return { kind: "setup", secret: response.setup.secret, qrSvg: response.setup.qr_svg };
  }
  return { kind: "challenge" };
}

export async function login(email: string, password: string): Promise<LoginStep> {
  return finish(await apiFetch<LoginResponse>("/api/login", { method: "POST", body: { email, password } }));
}

/** The second step: a setup confirmation, or a TOTP / recovery code. */
export async function submitLoginCode(code: string): Promise<LoginStep> {
  return finish(await apiFetch<LoginResponse>("/api/login/mfa", { method: "POST", body: { code } }));
}

/** Restores the profile cache when the API session is still alive (e.g. the gate cookie was cleared). */
export async function resumeSession(): Promise<boolean> {
  try {
    setClientSession(toSessionPayload(await apiFetch<SessionPayload>("/api/me")));
    await fetch("/api/login", { method: "POST" });
    return true;
  } catch {
    return false;
  }
}

export async function logout(): Promise<void> {
  await apiFetch("/api/logout", { method: "POST" }).catch(() => null);
  forgetCsrfToken();
  clearClientSession();
  await fetch("/api/logout", { method: "POST" }).catch(() => null);
}

export async function stepUp(code: string): Promise<void> {
  await apiFetch("/api/mfa/step-up", { method: "POST", body: { code } });
}

export async function regenerateRecoveryCodes(): Promise<string[]> {
  const response = await apiFetch<{ recovery_codes: string[] }>("/api/mfa/recovery-codes", { method: "POST" });
  return response.recovery_codes;
}
