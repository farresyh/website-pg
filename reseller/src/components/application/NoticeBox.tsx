"use client";

import { useState } from "react";
import type { Notice } from "@/lib/application";

/**
 * ADR-133 decision 10: the PDPA notice in both languages (section 7), shown in full above its own
 * consent box. English first; the applicant can switch to Malay.
 */
export default function NoticeBox({ notice }: { notice: Notice }) {
  const [lang, setLang] = useState<"en" | "ms">("en");

  return (
    <div className="rounded-lg border border-border bg-subtle">
      <div className="flex items-center justify-between gap-3 border-b border-border px-4 py-2.5">
        <span className="text-theme-xs font-medium text-ink-muted">Privacy notice (version {notice.version})</span>
        <div role="group" aria-label="Notice language" className="flex gap-1">
          {(["en", "ms"] as const).map((code) => (
            <button
              key={code}
              type="button"
              aria-pressed={lang === code}
              onClick={() => setLang(code)}
              className={`rounded px-2 py-1 text-theme-xs font-medium ${lang === code ? "bg-surface text-ink shadow-xs" : "text-ink-muted hover:text-ink"}`}
            >
              {code === "en" ? "English" : "Bahasa Melayu"}
            </button>
          ))}
        </div>
      </div>
      <div lang={lang} tabIndex={0} className="max-h-64 overflow-y-auto whitespace-pre-line px-4 py-3 text-theme-xs leading-5 text-ink-muted">
        {notice[lang]}
      </div>
    </div>
  );
}
