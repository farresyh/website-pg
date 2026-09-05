"use client";

import { useEffect, useState } from "react";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { getProfile, updateProfile, type AffiliateProfile } from "@/lib/portal";
import { getResellerProfile, type ResellerProfile } from "@/lib/reseller-portal";
import { PageHeader, Panel, ErrorNote, StatusTag } from "@/components/ui";

const inputClass =
  "w-full rounded-lg border border-gray-300 px-3 py-2 text-theme-sm text-gray-800 outline-none focus:border-brand-400 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90";

export default function ProfilePage() {
  const session = useClientSession();
  const ownerType = session?.owner_type ?? "affiliate";

  return ownerType === "reseller" ? <ResellerProfileView /> : <AffiliateProfileView />;
}

/**
 * ADR-072 decision 5 / PR-G: a Reseller (wallet) portal account's
 * Profile screen is view-only — its business details are
 * admin-curated (`/admin/resellers`), no existing precedent to mirror
 * for self-service edit here (unlike Affiliate's payout-details form).
 */
function ResellerProfileView() {
  const [profile, setProfile] = useState<ResellerProfile | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const session = getClientSession();
    if (!session) return;

    let cancelled = false;
    getResellerProfile(session.token)
      .then((result) => {
        if (!cancelled) setProfile(result);
      })
      .catch((err: unknown) => {
        if (!cancelled) {
          setError(err instanceof ApiError ? err.message : "Could not load your profile.");
        }
      });

    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <div>
      <PageHeader
        title="Profile"
        subtitle="Your account details. Managed by the platform — contact support to make changes."
      />

      {error && <ErrorNote message={error} />}
      {!profile && !error && (
        <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>
      )}

      {profile && (
        <Panel title="Account">
          <div className="divide-y divide-gray-100 dark:divide-gray-800">
            <Row label="Business name" value={profile.business_name} />
            <Row label="Contact name" value={profile.contact_name ?? "—"} />
            <Row label="Email" value={profile.email ?? "—"} />
            <Row label="Phone" value={profile.phone ?? "—"} />
            <Row label="Tier" value={profile.tier_name ?? "—"} />
            <Row
              label="Markup over cost"
              value={profile.markup_percent !== null ? `${profile.markup_percent}%` : "—"}
            />
            <Row
              label="Status"
              value={
                <StatusTag severity={profile.is_active ? "success" : "danger"}>
                  {profile.is_active ? "Active" : "Deactivated"}
                </StatusTag>
              }
            />
          </div>
        </Panel>
      )}
    </div>
  );
}

function AffiliateProfileView() {
  const [profile, setProfile] = useState<AffiliateProfile | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);

  const [form, setForm] = useState({
    contact_name: "",
    phone: "",
    bank_name: "",
    bank_account_no: "",
    bank_account_holder: "",
  });

  useEffect(() => {
    const session = getClientSession();
    if (!session) return;

    let cancelled = false;
    getProfile(session.token)
      .then((result) => {
        if (cancelled) return;
        setProfile(result);
        setForm({
          contact_name: result.contact_name ?? "",
          phone: result.phone ?? "",
          bank_name: result.bank_name ?? "",
          bank_account_no: result.bank_account_no ?? "",
          bank_account_holder: result.bank_account_holder ?? "",
        });
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(
          err instanceof ApiError ? err.message : "Could not load your profile.",
        );
      });

    return () => {
      cancelled = true;
    };
  }, []);

  function set(key: keyof typeof form, value: string) {
    setSaved(false);
    setForm((f) => ({ ...f, [key]: value }));
  }

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    const session = getClientSession();
    if (!session) return;

    setSaving(true);
    try {
      const updated = await updateProfile(session.token, form);
      setProfile(updated);
      setSaved(true);
    } catch (err) {
      setError(
        err instanceof ApiError ? err.message : "Could not save your profile.",
      );
    } finally {
      setSaving(false);
    }
  }

  return (
    <div>
      <PageHeader
        title="Profile"
        subtitle="Your contact and payout details. Store name and login email are managed by the platform."
      />

      {error && <ErrorNote message={error} />}
      {!profile && !error && (
        <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>
      )}

      {profile && (
        <div className="grid gap-6 lg:grid-cols-2">
          <Panel title="Company">
            <div className="divide-y divide-gray-100 dark:divide-gray-800">
              <Row label="Business name" value={profile.business_name} />
              <Row label="Login email" value={profile.email ?? "—"} />
            </div>
          </Panel>

          <Panel title="Contact & payout">
            <form onSubmit={handleSubmit} className="space-y-4 p-5">
              <Field label="Contact name">
                <input
                  value={form.contact_name}
                  onChange={(e) => set("contact_name", e.target.value)}
                  className={inputClass}
                />
              </Field>
              <Field label="Contact phone">
                <input
                  value={form.phone}
                  onChange={(e) => set("phone", e.target.value)}
                  className={inputClass}
                />
              </Field>
              <Field label="Bank name">
                <input
                  value={form.bank_name}
                  onChange={(e) => set("bank_name", e.target.value)}
                  className={inputClass}
                />
              </Field>
              <Field label="Account number">
                <input
                  value={form.bank_account_no}
                  onChange={(e) => set("bank_account_no", e.target.value)}
                  className={inputClass}
                />
              </Field>
              <Field label="Account holder">
                <input
                  value={form.bank_account_holder}
                  onChange={(e) => set("bank_account_holder", e.target.value)}
                  className={inputClass}
                />
              </Field>

              <div className="flex items-center gap-3">
                <button
                  type="submit"
                  disabled={saving}
                  className="rounded-lg bg-brand-500 px-4 py-2 text-theme-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                >
                  {saving ? "Saving…" : "Save changes"}
                </button>
                {saved && (
                  <span className="text-theme-sm text-success-600 dark:text-success-500">
                    Saved.
                  </span>
                )}
              </div>
            </form>
          </Panel>
        </div>
      )}
    </div>
  );
}

function Row({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex justify-between gap-6 px-5 py-3 text-theme-sm">
      <span className="text-gray-500 dark:text-gray-400">{label}</span>
      <span className="text-right font-medium text-gray-800 dark:text-white/90">
        {value}
      </span>
    </div>
  );
}

function Field({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) {
  return (
    <label className="block space-y-1.5">
      <span className="text-theme-sm font-medium text-gray-700 dark:text-gray-300">
        {label}
      </span>
      {children}
    </label>
  );
}
