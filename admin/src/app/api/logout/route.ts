import { NextResponse } from "next/server";
import { cookies } from "next/headers";
import { SESSION_COOKIE_NAME } from "@/lib/auth";

/**
 * Mirrors app/api/login/route.ts: proxies to Laravel's POST /api/logout
 * (revokes the Sanctum token server-side) then always clears the local
 * optimistic-gate cookie, even if the Laravel call fails — a stuck
 * session is worse than an unrevoked token the admin can't reach anyway.
 */
const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://backend.test";

export async function POST(request: Request) {
  const authHeader = request.headers.get("Authorization");

  if (authHeader) {
    await fetch(`${API_BASE_URL}/api/logout`, {
      method: "POST",
      headers: { Accept: "application/json", Authorization: authHeader },
    }).catch(() => null);
  }

  const cookieStore = await cookies();
  cookieStore.delete(SESSION_COOKIE_NAME);

  return NextResponse.json({ message: "Logged out" });
}
