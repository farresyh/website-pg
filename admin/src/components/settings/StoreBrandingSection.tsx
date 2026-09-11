"use client";

import { useRef, useState } from "react";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api-client";
import {
  deleteBrandingFavicon,
  deleteBrandingLogo,
  updateBranding,
  uploadBrandingFavicon,
  uploadBrandingLogo,
  type Branding,
} from "@/lib/settings";

/** ADR-089: primary brand's own Logo + Favicon upload — was previously only available to affiliates via the reseller portal's Branding tab. */
function LogoAndFaviconPanel({ token, branding, onSaved }: { token: string; branding: Branding; onSaved: () => void }) {
  const logoRef = useRef<HTMLInputElement>(null);
  const faviconRef = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState<"logo" | "favicon" | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function handleLogo(file: File) {
    setBusy("logo");
    setError(null);
    try {
      await uploadBrandingLogo(token, file);
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not upload that logo.");
    } finally {
      setBusy(null);
      if (logoRef.current) logoRef.current.value = "";
    }
  }

  async function removeLogo() {
    setBusy("logo");
    setError(null);
    try {
      await deleteBrandingLogo(token);
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not remove the logo.");
    } finally {
      setBusy(null);
    }
  }

  async function handleFavicon(file: File) {
    setBusy("favicon");
    setError(null);
    try {
      await uploadBrandingFavicon(token, file);
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not upload that favicon.");
    } finally {
      setBusy(null);
      if (faviconRef.current) faviconRef.current.value = "";
    }
  }

  async function removeFavicon() {
    setBusy("favicon");
    setError(null);
    try {
      await deleteBrandingFavicon(token);
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not remove the favicon.");
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="mb-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}
      <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
        <div className="flex flex-wrap items-center gap-4">
          <div className="flex h-16 w-16 items-center justify-center overflow-hidden rounded-lg border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900">
            {branding.logo_url ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={branding.logo_url} alt="" className="h-full w-full object-contain" />
            ) : (
              <span className="text-theme-xs text-gray-400">None</span>
            )}
          </div>
          <div className="space-y-1.5">
            <Label>Logo</Label>
            <input
              ref={logoRef}
              type="file"
              accept="image/jpeg,image/png,image/webp"
              disabled={busy === "logo"}
              onChange={(e) => e.target.files?.[0] && handleLogo(e.target.files[0])}
              className="block text-theme-xs text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-theme-xs file:font-medium file:text-brand-600 disabled:opacity-50 dark:text-gray-400 dark:file:bg-brand-500/15 dark:file:text-brand-400"
            />
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">PNG, JPG or WebP, up to 2&nbsp;MB. Shown in the header + footer — a wide logo/wordmark works fine, it&apos;s no longer cropped to a square.</p>
            {branding.logo_url && (
              <button type="button" onClick={removeLogo} disabled={busy === "logo"} className="text-theme-xs text-error-600 hover:underline disabled:opacity-50 dark:text-error-400">
                Remove logo
              </button>
            )}
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-4">
          <div className="flex h-16 w-16 items-center justify-center overflow-hidden rounded-lg border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900">
            {branding.favicon_url ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={branding.favicon_url} alt="" className="h-full w-full object-contain" />
            ) : (
              <span className="text-theme-xs text-gray-400">None</span>
            )}
          </div>
          <div className="space-y-1.5">
            <Label>Favicon</Label>
            <input
              ref={faviconRef}
              type="file"
              accept="image/jpeg,image/png,image/webp"
              disabled={busy === "favicon"}
              onChange={(e) => e.target.files?.[0] && handleFavicon(e.target.files[0])}
              className="block text-theme-xs text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-theme-xs file:font-medium file:text-brand-600 disabled:opacity-50 dark:text-gray-400 dark:file:bg-brand-500/15 dark:file:text-brand-400"
            />
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">
              Recommended: 512×512px square, PNG, transparent background — this becomes your browser tab icon. A separate asset from the logo above.
            </p>
            {branding.favicon_url && (
              <button type="button" onClick={removeFavicon} disabled={busy === "favicon"} className="text-theme-xs text-error-600 hover:underline disabled:opacity-50 dark:text-error-400">
                Remove favicon
              </button>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

export default function StoreBrandingSection({
  token,
  branding,
  onSaved,
}: {
  token: string;
  branding: Branding;
  onSaved: () => void;
}) {
  const [storeName, setStoreName] = useState(branding.store_name);
  const [description, setDescription] = useState(branding.description ?? "");
  const [supportEmail, setSupportEmail] = useState(branding.support_email ?? "");
  const [supportPhone, setSupportPhone] = useState(branding.support_phone ?? "");
  const [facebook, setFacebook] = useState(branding.social_links?.facebook ?? "");
  const [instagram, setInstagram] = useState(branding.social_links?.instagram ?? "");
  const [tiktok, setTiktok] = useState(branding.social_links?.tiktok ?? "");
  const [youtube, setYoutube] = useState(branding.social_links?.youtube ?? "");
  const [whatsapp, setWhatsapp] = useState(branding.social_links?.whatsapp ?? "");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSave() {
    setSaving(true);
    setError(null);
    try {
      await updateBranding(token, {
        store_name: storeName,
        description: description || null,
        support_email: supportEmail || null,
        support_phone: supportPhone || null,
        telegram_contact_link: branding.telegram_contact_link,
        social_links: {
          facebook: facebook || undefined,
          instagram: instagram || undefined,
          tiktok: tiktok || undefined,
          youtube: youtube || undefined,
          whatsapp: whatsapp || undefined,
        },
      });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save branding.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div>
      <LogoAndFaviconPanel token={token} branding={branding} onSaved={onSaved} />

      <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <Label htmlFor="store_name">Store name</Label>
          <Input id="store_name" value={storeName} onChange={(e) => setStoreName(e.target.value)} required />
        </div>
        <div>
          <Label htmlFor="support_phone">Support WhatsApp number</Label>
          <Input id="support_phone" value={supportPhone} onChange={(e) => setSupportPhone(e.target.value)} placeholder="+60 1X-XXX XXXX" />
          <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">Replaces the placeholder number previously hardcoded in the storefront.</p>
        </div>
        <div className="sm:col-span-2">
          <Label htmlFor="description">Store description</Label>
          <textarea
            id="description"
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            rows={3}
            className="w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
            placeholder="Short description shown in the footer and SEO tags"
          />
        </div>
        <div>
          <Label htmlFor="support_email">Support email</Label>
          <Input id="support_email" type="email" value={supportEmail} onChange={(e) => setSupportEmail(e.target.value)} placeholder="support@pekangame.space" />
        </div>
        <div>
          <Label htmlFor="facebook">Facebook URL</Label>
          <Input id="facebook" value={facebook} onChange={(e) => setFacebook(e.target.value)} placeholder="https://facebook.com/…" />
        </div>
        <div>
          <Label htmlFor="instagram">Instagram URL</Label>
          <Input id="instagram" value={instagram} onChange={(e) => setInstagram(e.target.value)} placeholder="https://instagram.com/…" />
        </div>
        <div>
          <Label htmlFor="tiktok">TikTok URL</Label>
          <Input id="tiktok" value={tiktok} onChange={(e) => setTiktok(e.target.value)} placeholder="https://tiktok.com/@…" />
        </div>
        <div>
          <Label htmlFor="youtube">YouTube URL</Label>
          <Input id="youtube" value={youtube} onChange={(e) => setYoutube(e.target.value)} placeholder="https://youtube.com/@…" />
        </div>
        <div>
          <Label htmlFor="whatsapp">WhatsApp URL</Label>
          <Input id="whatsapp" value={whatsapp} onChange={(e) => setWhatsapp(e.target.value)} placeholder="https://wa.me/60…" />
          <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">A shareable link (wa.me/community), not the support number above.</p>
        </div>
      </div>

      <div className="mt-6 flex justify-end gap-3 border-t border-gray-100 pt-5 dark:border-gray-800">
        <Button type="button" onClick={handleSave} disabled={saving}>
          {saving ? "Saving…" : "Save Branding"}
        </Button>
      </div>
      </div>
    </div>
  );
}
