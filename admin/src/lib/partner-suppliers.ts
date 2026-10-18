import { apiFetch } from "@/lib/api-client";
import type { WhatsAppGroup } from "@/lib/whatsapp-groups";

/**
 * ADR-125 decision 60: Admin → Partners → Suppliers, super_admin only. A
 * manual supplier as a partner: its WhatsApp group, reply SLA and active
 * switch. Creating one and its funding mode stay in Middleware → Suppliers.
 */
export interface PartnerSupplier {
  id: number;
  name: string;
  funding_mode: "prepaid" | "pay_after";
  is_active: boolean;
  reply_sla_minutes: number;
  pending_orders: number | null;
  whatsapp_groups: WhatsAppGroup[];
}

export function listPartnerSuppliers() {
  return apiFetch<PartnerSupplier[]>("/api/partner-suppliers");
}

export function linkSupplierWhatsAppGroup(supplierId: number, whatsappGroupId: string) {
  return apiFetch<PartnerSupplier>(`/api/partner-suppliers/${supplierId}/whatsapp-group`, {
    method: "POST",
    body: { whatsapp_group_id: whatsappGroupId },
  });
}

export function updateSupplierWhatsAppGroupStatus(supplierId: number, groupId: number, isActive: boolean) {
  return apiFetch<PartnerSupplier>(`/api/partner-suppliers/${supplierId}/whatsapp-group/${groupId}/status`, {
    method: "PATCH",
    body: { is_active: isActive },
  });
}

export function updateSupplierSla(supplierId: number, replySlaMinutes: number) {
  return apiFetch<PartnerSupplier>(`/api/partner-suppliers/${supplierId}/sla`, {
    method: "PATCH",
    body: { reply_sla_minutes: replySlaMinutes },
  });
}

export function updatePartnerSupplierStatus(supplierId: number, isActive: boolean) {
  return apiFetch<PartnerSupplier>(`/api/partner-suppliers/${supplierId}/status`, {
    method: "PATCH",
    body: { is_active: isActive },
  });
}
