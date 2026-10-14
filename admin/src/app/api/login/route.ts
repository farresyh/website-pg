import { NextResponse } from "next/server";
import { cookies } from "next/headers";
import { SESSION_COOKIE_NAME } from "@/lib/auth";

/**
 * Sets the presence-only cookie behind proxy.ts's optimistic redirect gate,
 * after the browser has logged in against Laravel directly (ADR-121: the
 * real login is Laravel's own httpOnly session cookie on the API host, which
 * this server never sees). Not a security boundary — see auth.ts.
 */
const SEVEN_DAYS = 7 * 24 * 60 * 60; // matches the API login's hard cap

export async function POST() {
  const cookieStore = await cookies();
  cookieStore.set(SESSION_COOKIE_NAME, "1", {
    httpOnly: true,
    sameSite: "lax",
    secure: process.env.NODE_ENV === "production",
    path: "/",
    maxAge: SEVEN_DAYS,
  });

  return NextResponse.json({ ok: true });
}
