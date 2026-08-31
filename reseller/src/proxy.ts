import { NextResponse } from "next/server";
import type { NextRequest } from "next/server";
import { SESSION_COOKIE_NAME } from "@/lib/auth";

/**
 * Next.js 16 renamed `middleware.ts` to `proxy.ts` (same mechanism).
 *
 * OPTIMISTIC redirect only (fast UX), NOT a security boundary — the
 * Laravel `reseller` guard authorizes every API call
 * (foundation-security.md §1). Do not add real permission logic here.
 */
export function proxy(request: NextRequest) {
  const hasSession = request.cookies.has(SESSION_COOKIE_NAME);
  const { pathname } = request.nextUrl;

  if (!hasSession && pathname !== "/login") {
    return NextResponse.redirect(new URL("/login", request.url));
  }

  if (hasSession && pathname === "/login") {
    return NextResponse.redirect(new URL("/dashboard", request.url));
  }

  return NextResponse.next();
}

export const config = {
  // Everything except Next internals, the API route handlers, and static
  // files — the portal has no public pages.
  matcher: ["/((?!_next/static|_next/image|favicon.ico|api/).*)"],
};
