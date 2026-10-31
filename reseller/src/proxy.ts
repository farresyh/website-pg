import { NextResponse } from "next/server";
import type { NextRequest } from "next/server";
import { SESSION_COOKIE_NAME } from "@/lib/auth";

/**
 * Next.js 16 renamed `middleware.ts` to `proxy.ts` (same mechanism).
 *
 * OPTIMISTIC redirect only (fast UX), NOT a security boundary — the
 * Laravel `affiliate` guard authorizes every API call
 * (foundation-security.md §1). Do not add real permission logic here.
 *
 * ADR-133 decision 4: the same app also serves the public reseller
 * application on its own host. The onboarding host (`onboarding.…`, so
 * `onboarding.localhost:3002` works in development) serves only `/apply`
 * and `/onboard/…`, never indexed; the portal host serves neither.
 */
const isApplicationPath = (pathname: string) =>
  pathname === "/apply" || pathname.startsWith("/apply/") || pathname.startsWith("/onboard/");

export function proxy(request: NextRequest) {
  const { pathname } = request.nextUrl;
  const onOnboardingHost = (request.headers.get("host") ?? "").toLowerCase().startsWith("onboarding.");

  if (onOnboardingHost) {
    if (!isApplicationPath(pathname)) {
      return NextResponse.redirect(new URL("/apply", request.url));
    }
    const response = NextResponse.next();
    response.headers.set("X-Robots-Tag", "noindex, nofollow");
    return response;
  }

  if (isApplicationPath(pathname)) {
    return new NextResponse("Not found", { status: 404 });
  }

  const hasSession = request.cookies.has(SESSION_COOKIE_NAME);

  // `/impersonate` is an entry point like `/login` — it arrives with no
  // gate cookie yet (the admin opens it with a token in the URL hash)
  // and sets its own session.
  // `/set-password` arrives from an emailed invite link with no gate
  // cookie yet — it is an entry point like `/login` (ADR-058 set-password
  // addendum).
  const isEntryPoint =
    pathname === "/login" ||
    pathname === "/impersonate" ||
    pathname === "/set-password";

  if (!hasSession && !isEntryPoint) {
    return NextResponse.redirect(new URL("/login", request.url));
  }

  if (hasSession && pathname === "/login") {
    return NextResponse.redirect(new URL("/dashboard", request.url));
  }

  return NextResponse.next();
}

export const config = {
  // Everything except Next internals, the API route handlers, and static
  // files. `images/` holds public artwork (the payment marks, ADR-128);
  // the image optimizer fetches it without the session cookie, so it must
  // not be sent to /login.
  matcher: ["/((?!_next/static|_next/image|favicon.ico|images/|api/).*)"],
};
