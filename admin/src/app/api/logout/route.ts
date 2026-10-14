import { NextResponse } from "next/server";
import { cookies } from "next/headers";
import { SESSION_COOKIE_NAME } from "@/lib/auth";

/**
 * Clears proxy.ts's presence-only gate cookie. The browser ends the real
 * Laravel session itself (`POST /api/logout` with credentials, ADR-121).
 */
export async function POST() {
  const cookieStore = await cookies();
  cookieStore.delete(SESSION_COOKIE_NAME);

  return NextResponse.json({ message: "Logged out" });
}
