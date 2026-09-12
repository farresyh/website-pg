"use client";

/**
 * ADR-087 — the LLM Report Assistant. Its own route under Reports
 * (decision 9: a genuinely different kind of surface — chat, not a
 * table/chart tab — so it's not the Reports page's 9th tab, and not a
 * top-level nav item either; reached via the "Ask Assistant" button on
 * `/admin/reports`). Backend-enforced `super_admin`-only (decision 6) —
 * this page adds no separate client-side role gate, same convention as
 * the other super_admin-tier screens (e.g. Blacklist): a regular admin
 * hitting `/api/reports/assistant/ask` just sees the 403's error text.
 *
 * Chat history is session-scoped only (decision 7) — plain React state,
 * deliberately not localStorage/sessionStorage. A page refresh starting
 * a fresh conversation is correct, not a bug: the backend's own audit
 * log (decision 8) is the durable record, not this UI.
 */

import { useState, useRef, useEffect } from "react";
import Link from "next/link";
import { Button } from "@/components/ui/button";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { askReportAssistant, type ReportAssistantTurn } from "@/lib/report-assistant";

interface Message {
  role: "user" | "assistant";
  text: string;
  sql?: string | null;
}

const SUGGESTIONS = [
  "Produk apa yang paling laris bulan ni?",
  "Game mana bagi margin paling tinggi?",
  "Macam mana perbandingan jualan member vs standard?",
];

export default function ReportAssistantPage() {
  const session = useClientSession();
  const [messages, setMessages] = useState<Message[]>([]);
  const [question, setQuestion] = useState("");
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const bottomRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [messages, sending]);

  function historyFromMessages(): ReportAssistantTurn[] {
    const turns: ReportAssistantTurn[] = [];
    for (let i = 0; i < messages.length - 1; i++) {
      if (messages[i].role === "user" && messages[i + 1]?.role === "assistant") {
        turns.push({ question: messages[i].text, answer: messages[i + 1].text });
      }
    }
    return turns;
  }

  async function send(text: string) {
    if (!session || sending || !text.trim()) return;

    const history = historyFromMessages();
    setMessages((prev) => [...prev, { role: "user", text }]);
    setQuestion("");
    setSending(true);
    setError(null);

    try {
      const result = await askReportAssistant(session.token, text, history);
      setMessages((prev) => [...prev, { role: "assistant", text: result.answer, sql: result.sql }]);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not reach the assistant.");
    } finally {
      setSending(false);
    }
  }

  return (
    <div className="mx-auto flex h-[calc(100vh-8rem)] max-w-3xl flex-col">
      <div className="mb-4">
        <Link href="/admin/reports" className="text-theme-xs text-brand-500 hover:underline">
          ← Back to Reports
        </Link>
        <h1 className="mt-1 text-xl font-semibold text-gray-800 dark:text-white/90">Report Assistant</h1>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
          Ask about sales, profit, games, or membership — grounded in the actual data, never guessed. Super Admin
          only. This chat isn&apos;t saved; every question and query is logged for 90 days.
        </p>
      </div>

      {error && (
        <p className="mb-3 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <div className="flex-1 space-y-4 overflow-y-auto rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
        {messages.length === 0 && (
          <div className="flex h-full flex-col items-center justify-center gap-3 text-center">
            <p className="text-sm text-gray-400 dark:text-gray-500">Try asking:</p>
            <div className="flex flex-wrap justify-center gap-2">
              {SUGGESTIONS.map((s) => (
                <button
                  key={s}
                  onClick={() => send(s)}
                  className="rounded-full border border-gray-200 px-3 py-1.5 text-xs text-gray-600 hover:border-brand-300 hover:text-brand-500 dark:border-gray-700 dark:text-gray-300"
                >
                  {s}
                </button>
              ))}
            </div>
          </div>
        )}

        {messages.map((m, i) => (
          <div key={i} className={`flex ${m.role === "user" ? "justify-end" : "justify-start"}`}>
            <div
              className={`max-w-[85%] rounded-2xl px-4 py-2.5 text-sm whitespace-pre-wrap ${
                m.role === "user"
                  ? "bg-brand-500 text-white"
                  : "bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-white/90"
              }`}
            >
              {m.text}
              {m.sql && (
                <details className="mt-2 text-xs opacity-80">
                  <summary className="cursor-pointer select-none">View query</summary>
                  <code className="mt-1 block overflow-x-auto rounded bg-black/10 p-2 font-mono">{m.sql}</code>
                </details>
              )}
            </div>
          </div>
        ))}

        {sending && (
          <div className="flex justify-start">
            <div className="rounded-2xl bg-gray-100 px-4 py-2.5 text-sm text-gray-400 dark:bg-gray-800">
              Thinking…
            </div>
          </div>
        )}

        <div ref={bottomRef} />
      </div>

      <form
        onSubmit={(e) => {
          e.preventDefault();
          send(question);
        }}
        className="mt-4 flex gap-2"
      >
        <textarea
          value={question}
          onChange={(e) => setQuestion(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter" && !e.shiftKey) {
              e.preventDefault();
              send(question);
            }
          }}
          placeholder="Tanya apa-apa pasal jualan, profit, atau strategi…"
          rows={2}
          disabled={sending}
          className="h-11 flex-1 resize-none rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30"
        />
        <Button type="submit" disabled={sending || !question.trim()}>
          Send
        </Button>
      </form>
    </div>
  );
}
