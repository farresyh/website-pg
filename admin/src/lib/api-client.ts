/**
 * Thin fetch wrapper around the Laravel backend API.
 *
 * All money/pricing/auth logic lives in Laravel, per foundation-security.md.
 * This client never computes or trusts a price/fee/profit value itself — it
 * only forwards requests and surfaces whatever the backend returns.
 *
 * ADR-121: auth is the Laravel session cookie (httpOnly, on the API host),
 * sent with `credentials: "include"`. JS never sees a token. Mutating
 * requests carry `X-CSRF-TOKEN`, fetched from `/api/csrf-token` (the session
 * cookie is host-only, so there is no readable XSRF cookie).
 */

import { clearClientSession } from "@/lib/session";

export const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://backend.test";

/**
 * A 401 means the session is gone (logged out, 2 days idle, or past the
 * 7-day cap) — distinct from a 403 (authenticated but not allowed), which
 * callers handle themselves. The one central place to react to it.
 */
function handleUnauthenticated(status: number): void {
  if (status !== 401 || typeof window === "undefined") return;
  clearClientSession();
  if (window.location.pathname !== "/login") {
    window.location.href = "/login";
  }
}

export class ApiError extends Error {
  constructor(
    public status: number,
    public code: string | undefined,
    message: string,
    // ADR-096 — the manual-poll cooldown 422 carries a retry_after_seconds
    // field alongside message; kept generic (raw response body) rather
    // than a one-off constructor param, for any future caller that needs
    // more than message/code.
    public payload?: Record<string, unknown> | null,
  ) {
    super(message);
    this.name = "ApiError";
  }
}

let csrfToken: string | null = null;

async function getCsrfToken(refresh = false): Promise<string> {
  if (csrfToken && !refresh) return csrfToken;
  const response = await fetch(`${API_BASE_URL}/api/csrf-token`, {
    credentials: "include",
    headers: { Accept: "application/json" },
  });
  const payload = await response.json().catch(() => null);
  const token: string = typeof payload?.token === "string" ? payload.token : "";
  csrfToken = token;
  return token;
}

/** Login and logout rotate the session's CSRF token. */
export function forgetCsrfToken(): void {
  csrfToken = null;
}

/**
 * ADR-121 step-up: StepUpDialog registers a prompt here. On a
 * `mfa_step_up_required` 403 the request waits for the admin to enter a
 * code, then retries once. Resolves false when the admin cancels.
 */
type StepUpPrompt = () => Promise<boolean>;
let stepUpPrompt: StepUpPrompt | null = null;

export function registerStepUpPrompt(prompt: StepUpPrompt | null): void {
  stepUpPrompt = prompt;
}

const SAFE_METHODS = new Set(["GET", "HEAD", "OPTIONS"]);

/**
 * The one place a browser request reaches Laravel. Adds the session
 * cookie and CSRF header, retries once on a stale CSRF token (419) and
 * once after a step-up, and throws ApiError for anything else non-2xx.
 * Returns the raw Response so callers can read JSON or a file download.
 */
export async function apiRequest(path: string, init: RequestInit = {}): Promise<Response> {
  const method = (init.method ?? "GET").toUpperCase();

  const send = async (refreshCsrf = false) =>
    fetch(`${API_BASE_URL}${path}`, {
      ...init,
      credentials: "include",
      headers: {
        Accept: "application/json",
        ...(SAFE_METHODS.has(method) ? {} : { "X-CSRF-TOKEN": await getCsrfToken(refreshCsrf) }),
        ...init.headers,
      },
    });

  let response = await send();

  if (response.status === 419) {
    response = await send(true);
  }

  if (response.status === 403 && stepUpPrompt) {
    const payload = await response.clone().json().catch(() => null);
    if (payload?.code === "mfa_step_up_required" && (await stepUpPrompt())) {
      response = await send();
    }
  }

  if (!response.ok) {
    const payload = await response.json().catch(() => null);
    handleUnauthenticated(response.status);
    throw new ApiError(
      response.status,
      payload?.code,
      payload?.message ?? `Request to ${path} failed (${response.status})`,
      payload,
    );
  }

  return response;
}

interface RequestOptions extends Omit<RequestInit, "body"> {
  body?: unknown;
}

export async function apiFetch<T>(path: string, { body, headers, ...init }: RequestOptions = {}): Promise<T> {
  const response = await apiRequest(path, {
    ...init,
    headers: { "Content-Type": "application/json", ...headers },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });

  return (await response.json().catch(() => null)) as T;
}

/**
 * Multipart upload variant of apiFetch — a `FormData` body must never
 * be JSON.stringify'd or sent with an explicit `Content-Type` (the
 * browser sets the multipart boundary itself).
 */
export async function apiUpload<T>(path: string, formData: FormData): Promise<T> {
  const response = await apiRequest(path, { method: "POST", body: formData });

  return (await response.json().catch(() => null)) as T;
}

/**
 * A private-file download or export (receipts, CSV/PDF exports): fetched
 * through the session like any other call, then handed to the browser as a
 * Blob + object URL — the routes are auth-gated, so a plain `<a href>`
 * can't be used.
 */
export async function apiDownload(path: string, filename: string): Promise<void> {
  const response = await apiRequest(path);
  const url = URL.createObjectURL(await response.blob());
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
