import { apiFetch } from "@/lib/api-client";

/**
 * ADR-075 decision 2, generalised by ADR-125 decision 11: a bot-session
 * WhatsApp group, owned by a reseller or a manual supplier. A pending row is
 * platform-wide (a captured group isn't attributed to anyone yet); the admin
 * links it from the reseller's or the supplier's own screen. super_admin only.
 */
export interface WhatsAppGroup {
  id: number;
  whatsapp_group_id: string;
  is_active: boolean;
  created_at: string;
}

export interface WhatsAppPendingLink {
  whatsapp_group_id: string;
  last_message_preview: string | null;
  last_message_at: string | null;
}

export function listPendingWhatsAppGroups() {
  return apiFetch<WhatsAppPendingLink[]>("/api/whatsapp-groups/pending");
}
