/**
 * Thin fetch wrapper around the Laravel backend API — mirrors
 * admin/src/lib/api-client.ts. All money/pricing logic lives in
 * Laravel (ORD-9): this client never computes or trusts a
 * price/fee/profit value itself, only forwards requests and surfaces
 * whatever the backend returns. Guest checkout (ADR-011) — no auth
 * token concept here, unlike admin's Bearer-token variant.
 */

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://backend.test";

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
}

export async function apiFetch<T>(path: string, { body, headers, ...init }: RequestOptions = {}): Promise<T> {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    ...init,
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      ...headers,
    },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });

  const payload = await response.json().catch(() => null);

  if (!response.ok) {
    throw new ApiError(
      response.status,
      payload?.code,
      payload?.message ?? `Request to ${path} failed (${response.status})`,
    );
  }

  return payload as T;
}
