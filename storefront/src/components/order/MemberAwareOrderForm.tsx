import OrderForm from "@/components/order/OrderForm";
import { getGamePackages, type GameDetail, type GamePackage } from "@/lib/catalog";
import { getMe, type MembershipPlan } from "@/lib/membership";
import { getServerMembershipToken } from "@/lib/membership-session-server";
import type { PaymentChannel } from "@/lib/payment-methods";

interface Props {
  /** ADR-097 decision 9 — needs GameDetail (not the narrower Game), for zoneOptions. */
  game: GameDetail;
  packages: GamePackage[];
  paymentChannels: PaymentChannel[];
  membershipPlans: MembershipPlan[];
}

/**
 * ADR-071 PR2b (ADR-027 addendum) — reads the membership session cookie
 * and, for a verified member, resolves personalized package pricing +
 * their verified email in the server render, then hands `OrderForm` the
 * props. This is what removes `OrderForm`'s second client-side
 * `getGamePackages(token)` + `getMe(token)` wave for a member who
 * verified before shopping (the common case).
 *
 * It is deliberately its own async server component, wrapped in
 * `<Suspense>` in the page — NOT an `await cookies()` in the page body.
 * A dynamic-API read at the page level holds up the route's
 * `loading.tsx` fallback (Next's `loading.js` docs); confined to this
 * subtree it only holds up the order form, behind its own skeleton.
 *
 * A guest (no cookie) resolves near-instantly — `cookies()` is a
 * request read, not a network call, and the conditional fetches are
 * skipped. A lapsed/invalid token → `getMe().catch(() => null)` → the
 * member is treated as a guest, same as the old client fallback.
 */
export default async function MemberAwareOrderForm({ game, packages, paymentChannels, membershipPlans }: Props) {
  const token = await getServerMembershipToken();

  const [memberPackagesRaw, memberInfo] = token
    ? await Promise.all([getGamePackages(game.slug, token), getMe(token).catch(() => null)])
    : [null, null];

  // A failed personalized fetch (`safeRead` → `[]`) or a game with
  // genuinely no packages both fall back to the anonymous anchor, never
  // an empty member view.
  const memberPackages = memberPackagesRaw && memberPackagesRaw.length > 0 ? memberPackagesRaw : null;
  const memberSession = memberInfo
    ? {
        tierName: memberInfo.membership?.tierName ?? null,
        email: memberInfo.email,
        quotaRemainingRm: memberInfo.membership?.quotaRemainingRm ?? null,
      }
    : null;

  return (
    <OrderForm
      game={game}
      packages={packages}
      paymentChannels={paymentChannels}
      membershipPlans={membershipPlans}
      memberPackages={memberPackages}
      memberSession={memberSession}
      ssrMembershipToken={token}
    />
  );
}
