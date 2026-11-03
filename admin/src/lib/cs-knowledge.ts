import { apiFetch } from "@/lib/api-client";

/**
 * ADR-136 decisions 2, 10, 16, 17 (PR 2): the admin side of the CS knowledge
 * book. Mirrors `Admin\CsKnowledgeController`; every state change happens there.
 */

export const CATEGORIES = [
  { value: "how_to_buy", label: "How to buy" },
  { value: "price_package", label: "Prices / packages" },
  { value: "payment", label: "Payment" },
  { value: "order_status", label: "Order status" },
  { value: "order_failed", label: "Failed order" },
  { value: "game_id_server", label: "Game ID / server" },
  { value: "voucher_promo", label: "Voucher / promo" },
  { value: "partner", label: "Become a reseller / affiliate" },
  { value: "other", label: "Other" },
] as const;

export type Category = (typeof CATEGORIES)[number]["value"];

export const categoryLabel = (c: string) => CATEGORIES.find((x) => x.value === c)?.label ?? c;

export type SuggestionStatus = "pending" | "approved" | "merged" | "rejected" | "ignored";
export type EntryStatus = "approved" | "archived";

export interface Suggestion {
  id: number;
  question: string;
  answer: string;
  // PR 3b: the model's reviewable rewrite (null until the nightly run writes it).
  ai_question: string | null;
  ai_answer: string | null;
  ai_category: Category | null;
  ai_entry_id: number | null;
  order_numbers: string[] | null;
  affiliate_id: number | null;
  affiliate: { id: number; business_name: string } | null;
  asked_at: string;
  status: SuggestionStatus;
  cs_knowledge_entry_id: number | null;
  entry: { id: number; questions: string[] } | null;
  reviewer: { id: number; name: string } | null;
  reviewed_at: string | null;
}

export interface Entry {
  id: number;
  category: Category;
  questions: string[];
  answer: string;
  affiliate_id: number | null;
  affiliate: { id: number; business_name: string } | null;
  game_id: number | null;
  game: { id: number; name: string } | null;
  valid_until: string | null;
  status: EntryStatus;
  approver: { id: number; name: string } | null;
  approved_at: string | null;
  use_count: number;
  updated_at: string;
}

export interface EntryValues {
  category: Category;
  questions: string[];
  answer: string;
  affiliate_id: number | null;
  game_id: number | null;
  valid_until: string | null;
}

export interface Page<T, S extends string> {
  data: T[];
  meta: { current_page: number; last_page: number; total: number };
  counts: Partial<Record<S, number>>;
}

export function listSuggestions(status: SuggestionStatus | "all", page: number) {
  const qs = new URLSearchParams({ page: String(page), ...(status === "all" ? {} : { status }) });
  return apiFetch<Page<Suggestion, SuggestionStatus>>(`/api/cs-knowledge/suggestions?${qs}`);
}

export function approveSuggestion(id: number, values: EntryValues) {
  return apiFetch<Entry>(`/api/cs-knowledge/suggestions/${id}/approve`, { method: "POST", body: values });
}

export function mergeSuggestion(id: number, values: { cs_knowledge_entry_id: number; question: string; answer: string | null }) {
  return apiFetch<Entry>(`/api/cs-knowledge/suggestions/${id}/merge`, { method: "POST", body: values });
}

export function rejectSuggestion(id: number) {
  return apiFetch<Suggestion>(`/api/cs-knowledge/suggestions/${id}/reject`, { method: "POST" });
}

export function ignoreSuggestionChat(id: number) {
  return apiFetch<{ closed: number }>(`/api/cs-knowledge/suggestions/${id}/ignore-chat`, { method: "POST" });
}

export function listEntries(status: EntryStatus, page: number, perPage = 25) {
  return apiFetch<Page<Entry, EntryStatus>>(`/api/cs-knowledge/entries?status=${status}&page=${page}&per_page=${perPage}`);
}

export function createEntry(values: EntryValues) {
  return apiFetch<Entry>("/api/cs-knowledge/entries", { method: "POST", body: values });
}

export function updateEntry(id: number, values: EntryValues) {
  return apiFetch<Entry>(`/api/cs-knowledge/entries/${id}`, { method: "PATCH", body: values });
}

export function setEntryArchived(id: number, archived: boolean) {
  return apiFetch<Entry>(`/api/cs-knowledge/entries/${id}/${archived ? "archive" : "restore"}`, { method: "PATCH" });
}
