"use client";

import { useEffect, useRef, useState } from "react";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import {
  getStorefrontBranding,
  updateStorefrontBranding,
  updateStorefrontSeo,
  uploadStorefrontLogo,
  deleteStorefrontLogo,
  uploadStorefrontFavicon,
  deleteStorefrontFavicon,
  type StorefrontBrandingResponse,
} from "@/lib/portal";
import { Panel, ErrorNote } from "@/components/ui";
import { Field, inputClass, SaveButton, InactiveNotice, TabLoading } from "./shared";

const SOCIAL_KEYS = ["facebook", "instagram", "tiktok", "youtube", "whatsapp"] as const;

type Form = {
  store_name: string;
  description: string;
  support_email: string;
  support_phone: string;
  telegram_contact_link: string;
  social: Record<(typeof SOCIAL_KEYS)[number], string>;
  ga_measurement_id: string;
  fb_pixel_id: string;
  tiktok_pixel_id: string;
};

const EMPTY_SOCIAL = Object.fromEntries(SOCIAL_KEYS.map((k) => [k, ""])) as Form["social"];

function toForm(data: StorefrontBrandingResponse): Form {
  return {
    store_name: data.branding.store_name ?? "",
    description: data.branding.description ?? "",
    support_email: data.branding.support_email ?? "",
    support_phone: data.branding.support_phone ?? "",
    telegram_contact_link: data.branding.telegram_contact_link ?? "",
    social: { ...EMPTY_SOCIAL, ...(data.branding.social_links ?? {}) },
    ga_measurement_id: data.seo.ga_measurement_id ?? "",
    fb_pixel_id: data.seo.fb_pixel_id ?? "",
    tiktok_pixel_id: data.seo.tiktok_pixel_id ?? "",
  };
}

export default function BrandingTab() {
  const [data, setData] = useState<StorefrontBrandingResponse | null>(null);
  const [form, setForm] = useState<Form | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [logoBusy, setLogoBusy] = useState(false);
  const [faviconBusy, setFaviconBusy] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);
  const faviconRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    const session = getClientSession();
    if (!session) return;
    let cancelled = false;
    getStorefrontBranding(session.token)
      .then((result) => {
        if (cancelled) return;
        setData(result);
        setForm(toForm(result));
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : "Could not load your branding.");
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const writable = data?.writable ?? false;

  function set<K extends keyof Form>(key: K, value: Form[K]) {
    setSaved(false);
    setForm((f) => (f ? { ...f, [key]: value } : f));
  }

  function setSocial(key: (typeof SOCIAL_KEYS)[number], value: string) {
    setSaved(false);
    setForm((f) => (f ? { ...f, social: { ...f.social, [key]: value } } : f));
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    if (!form) return;
    const session = getClientSession();
    if (!session) return;

    setError(null);
    setSaving(true);
    try {
      const social = Object.fromEntries(Object.entries(form.social).filter(([, v]) => v.trim() !== ""));
      const brandingResult = await updateStorefrontBranding(session.token, {
        store_name: form.store_name,
        description: form.description || null,
        support_email: form.support_email || null,
        support_phone: form.support_phone || null,
        telegram_contact_link: form.telegram_contact_link || null,
        social_links: social,
      });
      await updateStorefrontSeo(session.token, {
        ga_measurement_id: form.ga_measurement_id || null,
        fb_pixel_id: form.fb_pixel_id || null,
        tiktok_pixel_id: form.tiktok_pixel_id || null,
      });
      setData(brandingResult);
      setForm(toForm(brandingResult));
      setSaved(true);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save your branding.");
    } finally {
      setSaving(false);
    }
  }

  async function handleLogo(file: File) {
    const session = getClientSession();
    if (!session) return;
    setError(null);
    setLogoBusy(true);
    try {
      const result = await uploadStorefrontLogo(session.token, file);
      setData(result);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not upload that image.");
    } finally {
      setLogoBusy(false);
      if (fileRef.current) fileRef.current.value = "";
    }
  }

  async function removeLogo() {
    const session = getClientSession();
    if (!session) return;
    setError(null);
    setLogoBusy(true);
    try {
      setData(await deleteStorefrontLogo(session.token));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not remove the logo.");
    } finally {
      setLogoBusy(false);
    }
  }

  async function handleFavicon(file: File) {
    const session = getClientSession();
    if (!session) return;
    setError(null);
    setFaviconBusy(true);
    try {
      const result = await uploadStorefrontFavicon(session.token, file);
      setData(result);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not upload that favicon.");
    } finally {
      setFaviconBusy(false);
      if (faviconRef.current) faviconRef.current.value = "";
    }
  }

  async function removeFavicon() {
    const session = getClientSession();
    if (!session) return;
    setError(null);
    setFaviconBusy(true);
    try {
      setData(await deleteStorefrontFavicon(session.token));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not remove the favicon.");
    } finally {
      setFaviconBusy(false);
    }
  }

  if (error && !form) return <ErrorNote message={error} />;
  if (!form || !data) return <TabLoading />;

  return (
    <div className="space-y-6">
      {error && <ErrorNote message={error} />}
      {!writable && <InactiveNotice />}

      <Panel title="Logo">
        <div className="flex flex-wrap items-center gap-4 p-5">
          <div className="flex h-16 w-16 items-center justify-center overflow-hidden rounded-lg border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900">
            {data.branding.logo_url ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={data.branding.logo_url} alt="" className="h-full w-full object-contain" />
            ) : (
              <span className="text-theme-xs text-gray-400">None</span>
            )}
          </div>
          <div className="space-y-1.5">
            <input
              ref={fileRef}
              type="file"
              accept="image/jpeg,image/png,image/webp"
              disabled={!writable || logoBusy}
              onChange={(e) => e.target.files?.[0] && handleLogo(e.target.files[0])}
              className="block text-theme-xs text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-theme-xs file:font-medium file:text-brand-600 disabled:opacity-50 dark:text-gray-400 dark:file:bg-brand-500/15 dark:file:text-brand-400"
            />
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">
              PNG, JPG or WebP, up to 2&nbsp;MB. Shown in your header + footer — a wide logo/wordmark works fine, it&apos;s not cropped to a square.
            </p>
            {data.branding.logo_url && writable && (
              <button
                type="button"
                onClick={removeLogo}
                disabled={logoBusy}
                className="text-theme-xs text-error-600 hover:underline disabled:opacity-50 dark:text-error-400"
              >
                Remove logo
              </button>
            )}
          </div>
        </div>
      </Panel>

      <Panel title="Favicon">
        <div className="flex flex-wrap items-center gap-4 p-5">
          <div className="flex h-16 w-16 items-center justify-center overflow-hidden rounded-lg border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900">
            {data.branding.favicon_url ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={data.branding.favicon_url} alt="" className="h-full w-full object-contain" />
            ) : (
              <span className="text-theme-xs text-gray-400">None</span>
            )}
          </div>
          <div className="space-y-1.5">
            <input
              ref={faviconRef}
              type="file"
              accept="image/jpeg,image/png,image/webp"
              disabled={!writable || faviconBusy}
              onChange={(e) => e.target.files?.[0] && handleFavicon(e.target.files[0])}
              className="block text-theme-xs text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-theme-xs file:font-medium file:text-brand-600 disabled:opacity-50 dark:text-gray-400 dark:file:bg-brand-500/15 dark:file:text-brand-400"
            />
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">
              Recommended: 512×512px square, PNG, transparent background — this becomes your browser tab icon. A separate asset from the logo above.
            </p>
            {data.branding.favicon_url && writable && (
              <button
                type="button"
                onClick={removeFavicon}
                disabled={faviconBusy}
                className="text-theme-xs text-error-600 hover:underline disabled:opacity-50 dark:text-error-400"
              >
                Remove favicon
              </button>
            )}
          </div>
        </div>
      </Panel>

      <form onSubmit={handleSubmit} className="space-y-6">
        <Panel title="Store identity">
          <div className="space-y-4 p-5">
            <Field label="Store name">
              <input
                value={form.store_name}
                onChange={(e) => set("store_name", e.target.value)}
                required
                disabled={!writable}
                className={inputClass}
              />
            </Field>
            <Field label="Description" hint="Shown in your storefront footer.">
              <textarea
                value={form.description}
                onChange={(e) => set("description", e.target.value)}
                rows={3}
                disabled={!writable}
                className={inputClass}
              />
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Support email">
                <input
                  type="email"
                  value={form.support_email}
                  onChange={(e) => set("support_email", e.target.value)}
                  disabled={!writable}
                  className={inputClass}
                />
              </Field>
              <Field label="Support phone">
                <input
                  value={form.support_phone}
                  onChange={(e) => set("support_phone", e.target.value)}
                  disabled={!writable}
                  className={inputClass}
                />
              </Field>
            </div>
            <Field label="Telegram contact link">
              <input
                value={form.telegram_contact_link}
                onChange={(e) => set("telegram_contact_link", e.target.value)}
                placeholder="https://t.me/yourbrand"
                disabled={!writable}
                className={inputClass}
              />
            </Field>
          </div>
        </Panel>

        <Panel title="Social links">
          <div className="grid gap-4 p-5 sm:grid-cols-2">
            {SOCIAL_KEYS.map((key) => (
              <Field key={key} label={key[0].toUpperCase() + key.slice(1)}>
                <input
                  value={form.social[key]}
                  onChange={(e) => setSocial(key, e.target.value)}
                  placeholder="https://…"
                  disabled={!writable}
                  className={inputClass}
                />
              </Field>
            ))}
          </div>
        </Panel>

        <Panel title="Tracking pixels">
          <div className="space-y-4 p-5">
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">
              Only the pixel IDs are yours to set — meta tags and structured data stay platform-managed.
            </p>
            <Field label="Google Analytics measurement ID" hint="Looks like G-XXXXXXXXXX">
              <input
                value={form.ga_measurement_id}
                onChange={(e) => set("ga_measurement_id", e.target.value)}
                disabled={!writable}
                className={inputClass}
              />
            </Field>
            <Field label="Meta (Facebook) pixel ID" hint="A 15–16 digit number">
              <input
                value={form.fb_pixel_id}
                onChange={(e) => set("fb_pixel_id", e.target.value)}
                disabled={!writable}
                className={inputClass}
              />
            </Field>
            <Field label="TikTok pixel ID">
              <input
                value={form.tiktok_pixel_id}
                onChange={(e) => set("tiktok_pixel_id", e.target.value)}
                disabled={!writable}
                className={inputClass}
              />
            </Field>
          </div>
        </Panel>

        {writable && (
          <div className="p-1">
            <SaveButton saving={saving} saved={saved} />
          </div>
        )}
      </form>
    </div>
  );
}
