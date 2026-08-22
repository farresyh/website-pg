"use client";

import { useState } from "react";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import Button from "@/components/ui/button/Button";
import { ApiError } from "@/lib/api-client";
import { updateBranding, type Branding } from "@/lib/settings";

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
        social_links: { facebook: facebook || undefined, instagram: instagram || undefined },
      });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save branding.");
    } finally {
      setSaving(false);
    }
  }

  return (
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
          <Input id="support_email" type="email" value={supportEmail} onChange={(e) => setSupportEmail(e.target.value)} placeholder="support@kedairuncitsoloz.my" />
        </div>
        <div>
          <Label htmlFor="facebook">Facebook URL</Label>
          <Input id="facebook" value={facebook} onChange={(e) => setFacebook(e.target.value)} placeholder="https://facebook.com/…" />
        </div>
        <div>
          <Label htmlFor="instagram">Instagram URL</Label>
          <Input id="instagram" value={instagram} onChange={(e) => setInstagram(e.target.value)} placeholder="https://instagram.com/…" />
        </div>
      </div>

      <div className="mt-6 flex justify-end gap-3 border-t border-gray-100 pt-5 dark:border-gray-800">
        <Button type="button" onClick={handleSave} disabled={saving}>
          {saving ? "Saving…" : "Save Branding"}
        </Button>
      </div>
    </div>
  );
}
