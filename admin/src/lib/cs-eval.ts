import { apiFetch } from "@/lib/api-client";

/**
 * ADR-136 decision 28 (PR 3a): the model test. Mirrors `Admin\CsEvalController`.
 * Model names, cost and speed come back only after a run is revealed.
 */

export const HANDOVER_REASONS = ["order_failed", "not_received", "late", "no_knowledge", "wants_human", "other", "guard", "model_error"] as const;
export type HandoverReason = (typeof HANDOVER_REASONS)[number];

export const PAYMENT_STATUSES = ["paid", "pending", "failed"] as const;
export const DELIVERY_STATUSES = ["processing", "pending", "not_started", "delivered", "failed", "needs_review", "partially_delivered"] as const;

export interface OrderFacts {
  payment_status: (typeof PAYMENT_STATUSES)[number];
  delivery_status: (typeof DELIVERY_STATUSES)[number];
  minutes_since_paid?: number | null;
  expected_minutes?: number | null;
  package?: string | null;
  game?: string | null;
}

export interface EvalCaseValues {
  title: string;
  kind: "safety" | "quality";
  messages: string[];
  affiliate_id: number | null;
  order_facts: OrderFacts | null;
  expected_action: "reply" | "handover" | "any";
  expected_reason: HandoverReason | null;
  must_not_contain: string[] | null;
  notes: string | null;
  is_active: boolean;
}

export interface EvalCase extends EvalCaseValues {
  id: number;
}

export interface RunRow {
  id: number;
  cases_count: number;
  knowledge_count: number;
  results_count: number;
  completed_count: number;
  revealed_at: string | null;
  created_at: string;
}

export interface Answer {
  id: number;
  label: string;
  done: boolean;
  action: "reply" | "handover" | null;
  reply: string | null;
  handover_reason: string | null;
  safety_passed: boolean | null;
  safety_notes: string[];
  picked: boolean;
  model?: string;
  cost_usd_micros?: number;
  latency_ms?: number;
}

export interface RunDetail {
  id: number;
  cases_count: number;
  knowledge_count: number;
  completed: number;
  total: number;
  revealed_at: string | null;
  cases: { case: Pick<EvalCase, "id" | "title" | "kind" | "messages" | "order_facts" | "expected_action" | "expected_reason">; answers: Answer[] }[];
  summary: { model: string; answered: number; safety_failed: number; checks_failed: number; picked: number; cost_usd_micros: number; avg_latency_ms: number }[] | null;
}

export const listEvalCases = () => apiFetch<{ data: EvalCase[] }>("/api/cs-knowledge/eval-cases");
export const createEvalCase = (v: EvalCaseValues) => apiFetch<EvalCase>("/api/cs-knowledge/eval-cases", { method: "POST", body: v });
export const updateEvalCase = (id: number, v: EvalCaseValues) => apiFetch<EvalCase>(`/api/cs-knowledge/eval-cases/${id}`, { method: "PATCH", body: v });
export const importEvalCases = (cases: unknown[]) => apiFetch<{ created: number }>("/api/cs-knowledge/eval-cases/import", { method: "POST", body: { cases } });
export const listRuns = () => apiFetch<{ data: RunRow[]; configured: boolean }>("/api/cs-knowledge/eval-runs");
export const startRun = () => apiFetch<{ id: number }>("/api/cs-knowledge/eval-runs", { method: "POST" });
export const getRun = (id: number) => apiFetch<RunDetail>(`/api/cs-knowledge/eval-runs/${id}`);
export const pickAnswer = (runId: number, resultId: number) => apiFetch<{ picked: number }>(`/api/cs-knowledge/eval-runs/${runId}/results/${resultId}/pick`, { method: "POST" });
export const revealRun = (id: number) => apiFetch<RunDetail>(`/api/cs-knowledge/eval-runs/${id}/reveal`, { method: "POST" });

export const usd = (micros: number) => `$${(micros / 1_000_000).toFixed(4)}`;
