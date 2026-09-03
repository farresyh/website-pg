"use client";

/**
 * ADR-027's 2026-08-29 addendum, decisions 14/15/19/20: /admin/membership
 * — edit-only against the two fixed membership_plans rows (no add/delete
 * tier action, per decision 15's anchor/decoy pricing requirement), plus
 * this feature's own pre-launch kill switch (PlatformSettings.membership_enabled).
 * The tier cards and the member registry are their own components
 * (ADR-068 PR-3); this file is the toggle + composition only.
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { getSettings } from "@/lib/settings";
import { getMembershipPlans, updateMembershipEnabled, type MembershipPlan } from "@/lib/membership";
import TierCard from "@/components/membership/TierCard";
import MembersSection from "@/components/membership/MembersSection";

function Switch({ checked, onChange }: { checked: boolean; onChange: (v: boolean) => void }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      onClick={() => onChange(!checked)}
      className={`relative h-5.5 w-10 flex-shrink-0 rounded-full transition-colors ${checked ? "bg-brand-500" : "bg-gray-300 dark:bg-gray-700"}`}
    >
      <span className={`absolute top-0.5 h-4.5 w-4.5 rounded-full bg-white transition-transform ${checked ? "translate-x-[19px]" : "translate-x-0.5"}`} />
    </button>
  );
}

export default function MembershipPage() {
  const router = useRouter();
  const session = useClientSession();
  const [plans, setPlans] = useState<MembershipPlan[] | null>(null);
  const [enabled, setEnabled] = useState(false);
  const [togglingEnabled, setTogglingEnabled] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function refresh(token: string) {
    return Promise.all([getMembershipPlans(token), getSettings(token)])
      .then(([plansResponse, settingsResponse]) => {
        setPlans(plansResponse);
        setEnabled(settingsResponse.platform.membership_enabled);
      })
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load membership settings.");
      });
  }

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    refresh(s.token);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function handleToggleEnabled(next: boolean) {
    if (!session) return;
    setTogglingEnabled(true);
    setError(null);
    try {
      await updateMembershipEnabled(session.token, next);
      setEnabled(next);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not update the membership feature toggle.");
    } finally {
      setTogglingEnabled(false);
    }
  }

  if (error) {
    return <p className="rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>;
  }

  if (!session || !plans) {
    return <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>;
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Membership</h1>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
          Two fixed tiers (ADR-027) — fee, monthly quota, and discount % are editable here. Adding or removing a tier isn&apos;t
          supported from this screen; the anchor/decoy pricing this feature relies on needs exactly two tiers live together.
        </p>
      </div>

      <div className="mb-5 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="flex items-center justify-between">
          <div>
            <h3 className="text-sm font-semibold text-gray-800 dark:text-white/90">Membership feature</h3>
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">
              Gates the storefront member-price badge and the checkout verify prompt. Keep this off until real numbers and the
              email OTP vendor are ready.
            </p>
          </div>
          <Switch checked={enabled} onChange={handleToggleEnabled} />
        </div>
        {togglingEnabled && <p className="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">Saving…</p>}
      </div>

      <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
        {plans.map((plan) => (
          <TierCard key={plan.id} token={session.token} plan={plan} onSaved={() => refresh(session.token)} />
        ))}
      </div>

      <MembersSection token={session.token} plans={plans} onChanged={() => refresh(session.token)} />
    </div>
  );
}
