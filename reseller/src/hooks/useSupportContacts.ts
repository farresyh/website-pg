"use client";

import { useEffect, useState } from "react";
import { apiFetch } from "@/lib/api-client";

export type SupportContacts = { phone: string | null; email: string | null };

/**
 * PekanGame's own support contacts (ADR-128 decision 6), read from the
 * primary brand's public branding — a request with no `X-Storefront-Host`
 * resolves to the primary brand — so the numbers stay edited in the admin
 * panel. One request per page load, shared by every caller.
 */
let pending: Promise<SupportContacts | null> | null = null;

function load(): Promise<SupportContacts | null> {
  pending ??= apiFetch<{ support_phone: string | null; support_email: string | null }>("/api/catalog/branding")
    .then((b) => ({ phone: b.support_phone, email: b.support_email }))
    .catch(() => {
      pending = null;
      return null;
    });
  return pending;
}

export function useSupportContacts(): SupportContacts | null {
  const [contacts, setContacts] = useState<SupportContacts | null>(null);

  useEffect(() => {
    let live = true;
    void load().then((c) => {
      if (live) setContacts(c);
    });
    return () => {
      live = false;
    };
  }, []);

  return contacts;
}

/** A wa.me link needs digits only. */
export function whatsAppLink(phone: string): string {
  return `https://wa.me/${phone.replace(/\D/g, "")}`;
}
