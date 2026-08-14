/**
 * Thin fetch wrapper around the Laravel backend API.
 *
 * All money/pricing/auth logic lives in Laravel, per foundation-security.md.
 * This client never computes or trusts a price/fee/profit value itself — it
 * only forwards requests and surfaces whatever the backend returns.
 */

import { clearClientSession } from "@/lib/session";

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://backend.test";

/**
 * A 401 here means Sanctum rejected the bearer token outright (expired or
 * revoked) — distinct from a 403 (authenticated but wrong role), which
 * callers still handle themselves. Sanctum tokens gained a real expiration
 * 2026-08-14 (previously `null` forever, foundation-security.md gap); this
 * is the one central place to react to that instead of every page growing
 * its own expired-session redirect.
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
  ) {
    super(message);
    this.name = "ApiError";
  }
}

interface RequestOptions extends Omit<RequestInit, "body"> {
  body?: unknown;
  token?: string;
}

export async function apiFetch<T>(
  path: string,
  { body, token, headers, ...init }: RequestOptions = {},
): Promise<T> {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    ...init,
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...headers,
    },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });

  const payload = await response.json().catch(() => null);

  if (!response.ok) {
    handleUnauthenticated(response.status);
    throw new ApiError(
      response.status,
      payload?.code,
      payload?.message ?? `Request to ${path} failed (${response.status})`,
    );
  }

  return payload as T;
}

/**
 * Multipart upload variant of apiFetch — a `FormData` body must never
 * be JSON.stringify'd or sent with an explicit `Content-Type` (the
 * browser sets the multipart boundary itself); everything else
 * (base URL, auth header, error handling) matches apiFetch exactly.
 */
export async function apiUpload<T>(
  path: string,
  formData: FormData,
  { token }: { token?: string } = {},
): Promise<T> {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    method: "POST",
    headers: {
      Accept: "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: formData,
  });

  const payload = await response.json().catch(() => null);

  if (!response.ok) {
    handleUnauthenticated(response.status);
    throw new ApiError(
      response.status,
      payload?.code,
      payload?.message ?? `Request to ${path} failed (${response.status})`,
    );
  }

  return payload as T;
}
