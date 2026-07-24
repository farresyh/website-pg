import { NextResponse } from "next/server";
import { cookies } from "next/headers";
import { SESSION_COOKIE_NAME } from "@/lib/auth";

/**
 * Server-side proxy for Laravel's POST /api/login (AUTH-1/AUTH-2).
 *
 * Two things happen on success:
 * 1. The real Sanctum token + admin payload is returned to the client as
 *    JSON, for direct browser -> Laravel calls per ADR-009.
 * 2. An httpOnly, presence-only cookie is set purely so proxy.ts's
 *    optimistic gate can redirect signed-out visitors without a network
 *    round trip. The cookie never holds the token itself (see auth.ts).
 */
const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://backend.test";

export async function POST(request: Request) {
  const body = await request.json().catch(() => null);

  if (!body?.email || !body?.password) {
    return NextResponse.json(
      { message: "Email and password are required." },
      { status: 422 },
    );
  }

  const laravelResponse = await fetch(`${API_BASE_URL}/api/login`, {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json" },
    body: JSON.stringify({ email: body.email, password: body.password }),
  });

  const payload = await laravelResponse.json().catch(() => null);

  if (!laravelResponse.ok) {
    return NextResponse.json(
      { message: payload?.message ?? "Login failed." },
      { status: laravelResponse.status },
    );
  }

  const cookieStore = await cookies();
  cookieStore.set(SESSION_COOKIE_NAME, "1", {
    httpOnly: true,
    sameSite: "lax",
    secure: process.env.NODE_ENV === "production",
    path: "/",
  });

  return NextResponse.json(payload);
}
