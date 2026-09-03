import { cookies } from "next/headers";

/**
 * ADR-071 PR2 — the server-side read of the membership session cookie
 * (`lib/membership-session.ts` is its client counterpart). Lets the
 * order-page RSC personalize member pricing and resolve the member's
 * email in the single server render, instead of `OrderForm` doing it in
 * a second client-side `useEffect` wave.
 *
 * Reading this makes the calling route dynamic — which `/order/[slug]`
 * already is.
 */
export async function getServerMembershipToken(): Promise<string | null> {
  const store = await cookies();
  return store.get("krs_membership_token")?.value ?? null;
}
