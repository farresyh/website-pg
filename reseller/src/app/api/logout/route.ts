import { NextResponse } from "next/server";
import { cookies } from "next/headers";
import { SESSION_COOKIE_NAME } from "@/lib/auth";

/**
 * Mirrors `admin/src/app/api/logout/route.ts`: proxies to Laravel's
 * POST /api/affiliate/logout (revokes the Sanctum token) then always
 * clears the local optimistic-gate cookie, even if the Laravel call
 * fails.
 */
const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://backend.test";

export async function POST(request: Request) {
  const authHeader = request.headers.get("Authorization");

  if (authHeader) {
    await fetch(`${API_BASE_URL}/api/affiliate/logout`, {
      method: "POST",
      headers: { Accept: "application/json", Authorization: authHeader },
    }).catch(() => null);
  }

  const cookieStore = await cookies();
  cookieStore.delete(SESSION_COOKIE_NAME);

  return NextResponse.json({ message: "Logged out" });
}
