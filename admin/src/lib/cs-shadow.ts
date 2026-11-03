import { apiFetch } from "@/lib/api-client";

/**
 * ADR-136 PR 3b: the CS Assistant's settings and its Shadow drafts. Mirrors
 * `Admin\CsShadowController`. Shadow drafts are never sent to customers.
 */

export type Mode = "off" | "shadow";

export interface AssistantSettings {
  mode: Mode;
  answer_model: string;
  judge_model: string;
  reassure_minutes: number;
  handover_minutes: number;
  manual_handover_minutes: number;
  burst_wait_seconds: number;
  daily_cost_cap_sen: number;
  live_min_drafts: number;
  live_min_accuracy_pct: number;
  cost_cap_tripped_at?: string | null;
  updated_at?: string | null;
}

export interface SettingsResponse {
  data: AssistantSettings;
  models: string[];
  missing_keys: string[];
  spent_today_sen: number;
}

export const GRADES = [
  { value: "same", label: "Same meaning" },
  { value: "partly", label: "Partly right" },
  { value: "different", label: "Different" },
  { value: "correct_handover", label: "Correct handover" },
  { value: "should_have_handed_over", label: "Should have handed over" },
  { value: "unclear", label: "Unclear" },
] as const;
export type Grade = (typeof GRADES)[number]["value"];
export const gradeLabel = (g: string | null) => GRADES.find((x) => x.value === g)?.label ?? "—";

export interface Draft {
  id: number;
  question: string;
  order_numbers: string[] | null;
  asked_at: string;
  model: string;
  action: "reply" | "handover";
  reply: string | null;
  handover_reason: string | null;
  category: string | null;
  violations: string[] | null;
  error: string | null;
  staff_answer: string | null;
  grade: Grade | null;
  grade_note: string | null;
  judged_at: string | null;
  admin_grade: Grade | null;
  final_grade: Grade | null;
  brand: string | null;
}

export interface AccuracyRow {
  category: string;
  graded: number;
  right: number;
  accuracy_pct: number | null;
  should_have_handed_over: number;
  guard_hits: number;
  ready: boolean;
}

const BASE = "/api/cs-knowledge";

export const getSettings = () => apiFetch<SettingsResponse>(`${BASE}/assistant-settings`);
export const saveSettings = (s: AssistantSettings) => apiFetch<SettingsResponse>(`${BASE}/assistant-settings`, { method: "PUT", body: s });
export const listDrafts = (page: number, category: string, grade: string) => {
  const q = new URLSearchParams({ page: String(page) });
  if (category) q.set("category", category);
  if (grade) q.set("grade", grade);
  return apiFetch<{ data: Draft[]; meta: { current_page: number; last_page: number; total: number } }>(`${BASE}/shadow-drafts?${q}`);
};
export const gradeDraft = (id: number, grade: Grade | null) =>
  apiFetch<{ id: number; admin_grade: Grade | null; final_grade: Grade | null }>(`${BASE}/shadow-drafts/${id}/grade`, { method: "PATCH", body: { grade } });
export const getAccuracy = () => apiFetch<{ data: AccuracyRow[] }>(`${BASE}/shadow-accuracy`);
