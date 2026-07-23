import { NextResponse } from "next/server";
import type { NextRequest } from "next/server";
import { SESSION_COOKIE_NAME } from "@/lib/auth";

/**
 * Next.js 16 renamed `middleware.ts` to `proxy.ts` (same mechanism, new name).
 *
 * IMPORTANT: this is an OPTIMISTIC check only (fast redirect for UX), not a
 * security boundary. Real authorization happens in Laravel on every API
 * call, per foundation-security.md §1 ("Backend must enforce role-based
 * access control on every request — frontend routing is convenience only").
 * Do not add real permission logic here.
 */
export function proxy(request: NextRequest) {
  const hasSession = request.cookies.has(SESSION_COOKIE_NAME);
  const { pathname } = request.nextUrl;

  if (!hasSession && pathname !== "/login") {
    const loginUrl = new URL("/login", request.url);
    return NextResponse.redirect(loginUrl);
  }

  return NextResponse.next();
}

export const config = {
  // Guards the Admin Panel and Middleware Panel route groups only.
  // "/middleware" here is the Supplier Middleware business panel (§6.20/6.21
  // of the PRD) — unrelated to this Next.js `proxy`/middleware mechanism;
  // the naming overlap is coincidental.
  matcher: ["/admin/:path*", "/middleware/:path*"],
};
