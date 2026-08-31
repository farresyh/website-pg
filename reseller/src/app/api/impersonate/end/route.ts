import { NextResponse } from "next/server";
import { cookies } from "next/headers";
import { SESSION_COOKIE_NAME } from "@/lib/auth";

/**
 * ADR-059 59c: the portal "Exit impersonation" — proxies to the backend
 * `POST /api/reseller/impersonation/end` (closes the audit session +
 * revokes the token) and always clears the local gate cookie, so the
 * proxy sends the now-signed-out tab to /login.
 */
const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://backend.test";

export async function POST(request: Request) {
  const authHeader = request.headers.get("Authorization");

  if (authHeader) {
    await fetch(`${API_BASE_URL}/api/reseller/impersonation/end`, {
      method: "POST",
      headers: { Accept: "application/json", Authorization: authHeader },
    }).catch(() => null);
  }

  const cookieStore = await cookies();
  cookieStore.delete(SESSION_COOKIE_NAME);

  return NextResponse.json({ message: "Impersonation ended." });
}
