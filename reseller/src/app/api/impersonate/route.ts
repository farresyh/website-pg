import { NextResponse } from "next/server";
import { cookies } from "next/headers";
import { SESSION_COOKIE_NAME } from "@/lib/auth";

/**
 * ADR-059 59c: the landing endpoint for an admin RES-4 impersonation.
 * The admin opens `/impersonate#token=<minted affiliate token>` in a new
 * tab; the page POSTs that token here. We verify it against the backend
 * (`GET /api/affiliate/me` must return a non-null `impersonation` block —
 * i.e. an open impersonation session exists for this exact token), then
 * set the presence-only gate cookie and hand the page the identity to
 * store client-side. Mirrors `app/api/login/route.ts`.
 */
const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://backend.test";

export async function POST(request: Request) {
  const body = await request.json().catch(() => null);
  const token: unknown = body?.token;

  if (typeof token !== "string" || token.length === 0) {
    return NextResponse.json({ message: "Missing token." }, { status: 422 });
  }

  const meResponse = await fetch(`${API_BASE_URL}/api/affiliate/me`, {
    headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
  });

  const me = await meResponse.json().catch(() => null);

  if (!meResponse.ok || !me?.impersonation) {
    return NextResponse.json(
      { message: "This impersonation link is invalid or has expired." },
      { status: 401 },
    );
  }

  const cookieStore = await cookies();
  cookieStore.set(SESSION_COOKIE_NAME, "1", {
    httpOnly: true,
    sameSite: "lax",
    secure: process.env.NODE_ENV === "production",
    path: "/",
  });

  return NextResponse.json({
    token,
    affiliate_user: me.affiliate_user,
    affiliate: me.affiliate,
    impersonation: me.impersonation,
  });
}
