"use client";

import { useEffect } from "react";
import { normalisePromoCode, recordPromoClick, rememberPromoCode } from "@/lib/promo";

/**
 * ADR-118 V16: `?promo=CODE` on any page. Read from `window.location` once
 * on load (not `useSearchParams`, which would opt every page out of static
 * rendering); a KOL link is always a fresh page load.
 */
export default function PromoLinkCapture() {
  useEffect(() => {
    const code = normalisePromoCode(new URLSearchParams(window.location.search).get("promo") ?? "");
    if (!code) return;
    rememberPromoCode(code);
    recordPromoClick(code);
  }, []);

  return null;
}
