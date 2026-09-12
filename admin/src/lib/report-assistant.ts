import { apiFetch } from "@/lib/api-client";

/**
 * ADR-087 — the LLM Report Assistant. `super_admin`-only
 * (backend/routes/api.php: `admin.role:super_admin` on
 * `/api/reports/assistant/ask`). `history` is sent back on every call —
 * decision 7 keeps chat state session-scoped only, never persisted
 * server-side, so this client is the sole holder of the conversation
 * (see the assistant page's in-memory `messages` state, not
 * localStorage — a refresh starting a fresh chat is the intended
 * behavior, not a bug).
 */
export interface ReportAssistantTurn {
  question: string;
  answer: string;
}

export interface ReportAssistantAnswer {
  answer: string;
  sql: string | null;
  row_count: number | null;
}

export function askReportAssistant(token: string, question: string, history: ReportAssistantTurn[]) {
  return apiFetch<ReportAssistantAnswer>("/api/reports/assistant/ask", {
    method: "POST",
    token,
    body: { question, history },
  });
}
