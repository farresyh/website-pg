"use client";

import { useState } from "react";
import { ErrorNote, Panel } from "@/components/composites";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api-client";
import { verifyApplicationEmail } from "@/lib/application";

/**
 * ADR-133 decision 3: the link in the confirmation email. The application counts from here.
 * Confirming takes a click, not the page load: the token is single-use, and an email security
 * scanner that opens links (or React's development double effect) must not spend it first.
 */
const LINK_GONE = "This link has expired or was already used. You can send the application form again to get a new link.";

export default function VerifyApplicationEmailPage() {
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null);
  const [busy, setBusy] = useState(false);

  async function confirm() {
    // Read from the query string directly (as `/set-password` does), so no Suspense boundary is needed.
    const token = new URLSearchParams(window.location.search).get("token") ?? "";
    if (token.length !== 64) {
      setResult({ ok: false, message: LINK_GONE });
      return;
    }
    setBusy(true);
    try {
      setResult({ ok: true, message: (await verifyApplicationEmail(token)).message });
    } catch (err) {
      setResult({ ok: false, message: err instanceof ApiError && err.status !== 422 ? err.message : LINK_GONE });
    } finally {
      setBusy(false);
    }
  }

  if (result?.ok) {
    return (
      <Panel title="Email confirmed">
        <p className="p-5 text-sm text-ink-muted">{result.message}</p>
      </Panel>
    );
  }

  return (
    <div className="space-y-4">
      {result && <ErrorNote message={result.message} />}
      <Panel title="Confirm your email">
        <div className="space-y-4 p-5">
          <p className="text-sm text-ink-muted">Confirm that this is your email so we can review your reseller application.</p>
          {result ? (
            <a href="/apply" className="text-sm text-cyan-ink underline underline-offset-2">
              Back to the application form
            </a>
          ) : (
            <Button type="button" onClick={confirm} disabled={busy}>
              {busy ? "Confirming…" : "Confirm my email"}
            </Button>
          )}
        </div>
      </Panel>
    </div>
  );
}
