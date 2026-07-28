import { apiFetch } from "@/lib/api-client";

export type BlacklistEntryType = "player_id" | "email" | "phone";

export interface BlacklistHit {
  id: number;
  blacklist_entry_id: number;
  player_id: string | null;
  customer_email: string | null;
  customer_phone: string | null;
  ip: string | null;
  created_at: string;
}

export interface BlacklistEntry {
  id: number;
  type: BlacklistEntryType;
  value: string;
  reason: string;
  created_by: number | null;
  creator: { id: number; name: string } | null;
  is_active: boolean;
  hits_count?: number;
  created_at: string;
}

export interface BlacklistEntryDetail extends BlacklistEntry {
  hits: BlacklistHit[];
}

export interface BlacklistIndexResponse {
  stats: {
    active: number;
    total: number;
  };
  entries: BlacklistEntry[];
}

export interface CreateBlacklistEntryValues {
  type: BlacklistEntryType;
  value: string;
  reason: string;
}

export function listBlacklistEntries(token: string) {
  return apiFetch<BlacklistIndexResponse>("/api/blacklist", { token });
}

export function createBlacklistEntry(token: string, values: CreateBlacklistEntryValues) {
  return apiFetch<BlacklistEntry>("/api/blacklist", { method: "POST", token, body: values });
}

export function getBlacklistEntry(token: string, id: number) {
  return apiFetch<BlacklistEntryDetail>(`/api/blacklist/${id}`, { token });
}

export function deactivateBlacklistEntry(token: string, id: number) {
  return apiFetch<BlacklistEntry>(`/api/blacklist/${id}/deactivate`, { method: "PATCH", token });
}
