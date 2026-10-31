"use client";

import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { ErrorNote, Panel } from "@/components/composites";
import NoticeBox from "@/components/application/NoticeBox";
import { Field } from "@/components/storefront/shared";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { ApiError } from "@/lib/api-client";
import {
  deleteDocument,
  DOCUMENT_LABELS,
  formatBytes,
  getStageTwo,
  MAX_FILE_BYTES,
  saveStageTwo,
  submitStageTwo,
  uploadDocument,
  type DocumentKind,
  type StageTwo,
} from "@/lib/application";

/**
 * ADR-133 decisions 7–8: stage 2, reached only through the link emailed to the applicant. Answers
 * and files are saved as they go, so the applicant can leave and come back until they submit;
 * submitting locks the application and the link stops working.
 */
const LINK_GONE = "This link has expired or was already used. If you still need to send your documents, reply to our email and we will send you a new link.";

export default function StageTwoPage() {
  const { token } = useParams<{ token: string }>();
  const [application, setApplication] = useState<StageTwo | "gone" | null>(null);
  const [answers, setAnswers] = useState({ owner_address: "", business_address: "", tax_number: "" });
  const [consent, setConsent] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [saved, setSaved] = useState(false);
  const [done, setDone] = useState<string | null>(null);

  function load(next: StageTwo) {
    setApplication(next);
    setAnswers({ owner_address: next.owner_address ?? "", business_address: next.business_address ?? "", tax_number: next.tax_number ?? "" });
  }

  useEffect(() => {
    getStageTwo(token).then(load, () => setApplication("gone"));
  }, [token]);

  function fail(err: unknown) {
    if (err instanceof ApiError && err.status === 404) {
      setApplication("gone");
    } else if (err instanceof ApiError && err.status === 422 && err.errors) {
      setFieldErrors(err.errors);
      setError(Object.values(err.errors)[0]?.[0] ?? err.message);
    } else {
      setError(err instanceof ApiError ? err.message : "Could not reach the server. Please try again.");
    }
  }

  const payload = () => ({
    owner_address: answers.owner_address.trim() || null,
    business_address: answers.business_address.trim() || null,
    tax_number: answers.tax_number.trim() || null,
  });

  async function save() {
    setBusy("save");
    setError(null);
    setFieldErrors({});
    try {
      load(await saveStageTwo(token, payload()));
      setSaved(true);
    } catch (err) {
      fail(err);
    } finally {
      setBusy(null);
    }
  }

  async function upload(kind: DocumentKind, file: File | undefined) {
    if (!file) return;
    setError(null);
    if (file.size > MAX_FILE_BYTES) {
      setError("That file is larger than 10 MB. Please upload a smaller scan or photo.");
      return;
    }
    setBusy(kind);
    try {
      setApplication(await uploadDocument(token, kind, file));
    } catch (err) {
      fail(err);
    } finally {
      setBusy(null);
    }
  }

  async function remove(id: number) {
    setBusy(`remove-${id}`);
    setError(null);
    try {
      setApplication(await deleteDocument(token, id));
    } catch (err) {
      fail(err);
    } finally {
      setBusy(null);
    }
  }

  async function submit() {
    setBusy("submit");
    setError(null);
    setFieldErrors({});
    try {
      await saveStageTwo(token, payload());
      setDone((await submitStageTwo(token)).message);
    } catch (err) {
      fail(err);
    } finally {
      setBusy(null);
    }
  }

  if (application === null) return <p className="text-sm text-ink-muted">Loading…</p>;
  if (application === "gone") return <ErrorNote message={LINK_GONE} />;
  if (done) {
    return (
      <Panel title="Documents submitted">
        <p className="p-5 text-sm text-ink-muted">{done}</p>
      </Panel>
    );
  }

  const isCompany = application.applicant_type === "company";
  const fieldError = (key: string) => fieldErrors[key]?.[0];
  const missing = application.required_documents.filter((kind) => !application.documents.some((d) => d.kind === kind));
  const expires = application.expires_at ? new Date(application.expires_at).toLocaleDateString("en-MY", { day: "numeric", month: "long", year: "numeric" }) : null;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-[26px] font-[650] leading-8 tracking-[-0.02em] text-ink">Finish your application, {application.owner_name}</h1>
        <p className="mt-2 text-sm text-ink-muted">
          We need your address and a few documents to verify you{isCompany && application.business_name ? ` and ${application.business_name}` : ""}. Your progress is
          saved as you go{expires ? `; this link works until ${expires}` : ""}.
        </p>
      </div>

      {error && <ErrorNote message={error} />}

      <Panel title="Your details">
        <div className="space-y-4 p-5">
          <p className="text-theme-xs text-ink-subtle">
            Applying as {isCompany ? "a company" : "an individual"} · {application.email}
          </p>
          <Field label="Your address">
            <Textarea value={answers.owner_address} onChange={(e) => setAnswers((a) => ({ ...a, owner_address: e.target.value }))} maxLength={500} error={Boolean(fieldError("owner_address"))} />
          </Field>
          {isCompany && (
            <Field label="Business address">
              <Textarea value={answers.business_address} onChange={(e) => setAnswers((a) => ({ ...a, business_address: e.target.value }))} maxLength={500} error={Boolean(fieldError("business_address"))} />
            </Field>
          )}
          <Field label="Tax identification number (optional)">
            <Input value={answers.tax_number} onChange={(e) => setAnswers((a) => ({ ...a, tax_number: e.target.value }))} maxLength={50} />
          </Field>
          <div className="flex items-center gap-3">
            <Button type="button" severity="secondary" onClick={save} disabled={busy !== null}>
              {busy === "save" ? "Saving…" : "Save progress"}
            </Button>
            {saved && busy === null && <span className="text-theme-xs text-success-ink">Saved</span>}
          </div>
        </div>
      </Panel>

      <Panel title="Documents">
        <ul className="divide-y divide-border">
          {application.required_documents.map((kind) => {
            const files = application.documents.filter((d) => d.kind === kind);
            const limit = application.document_limits[kind];
            const canAdd = files.length < limit || limit === 1;
            const inputId = `upload-${kind}`;
            return (
              <li key={kind} className="space-y-2.5 px-5 py-4">
                <div>
                  <p className="text-sm font-medium text-ink">{DOCUMENT_LABELS[kind].title}</p>
                  <p className="text-theme-xs text-ink-subtle">{DOCUMENT_LABELS[kind].hint} PDF, JPG or PNG, up to 10 MB.</p>
                  {fieldError(`documents.${kind}`) && <p className="mt-1 text-theme-xs text-danger-ink">{fieldError(`documents.${kind}`)}</p>}
                </div>
                {files.map((file) => (
                  <div key={file.id} className="flex items-center justify-between gap-3 rounded-md bg-subtle px-3 py-2 text-theme-xs">
                    <span className="min-w-0 truncate text-ink">
                      {file.original_name} <span className="text-ink-subtle">· {formatBytes(file.size_bytes)}</span>
                    </span>
                    <Button type="button" variant="text" size="small" onClick={() => remove(file.id)} disabled={busy !== null}>
                      {busy === `remove-${file.id}` ? "Removing…" : "Remove"}
                    </Button>
                  </div>
                ))}
                {canAdd && (
                  <div>
                    <label htmlFor={inputId} className="sr-only">
                      Upload {DOCUMENT_LABELS[kind].title}
                    </label>
                    <input
                      id={inputId}
                      type="file"
                      accept="application/pdf,image/jpeg,image/png"
                      disabled={busy !== null}
                      onChange={(e) => {
                        upload(kind, e.target.files?.[0]);
                        e.target.value = "";
                      }}
                      className="block text-theme-xs text-ink-muted file:mr-3 file:rounded-md file:border-0 file:bg-cyan-50 file:px-3 file:py-1.5 file:text-theme-xs file:font-medium file:text-cyan-ink"
                    />
                    {busy === kind && <p className="mt-1 text-theme-xs text-ink-subtle">Uploading…</p>}
                    {limit === 1 && files.length === 1 && <p className="mt-1 text-theme-xs text-ink-subtle">Uploading again replaces this file.</p>}
                  </div>
                )}
              </li>
            );
          })}
        </ul>
      </Panel>

      <Panel title="Privacy">
        <div className="space-y-4 p-5">
          <NoticeBox notice={application.notice} />
          <label className="flex items-start gap-2.5 text-sm text-ink">
            <input type="checkbox" className="mt-1" checked={consent} onChange={(e) => setConsent(e.target.checked)} />
            <span>I have read the privacy notice and agree to PekanGame using my details and documents as it describes. / Saya telah membaca notis privasi dan bersetuju.</span>
          </label>
        </div>
      </Panel>

      <div className="flex flex-wrap items-center gap-3">
        <Button type="button" onClick={submit} disabled={busy !== null || !consent || missing.length > 0}>
          {busy === "submit" ? "Submitting…" : "Submit application"}
        </Button>
        {missing.length > 0 ? (
          <span className="text-theme-xs text-ink-subtle">Still needed: {missing.map((k) => DOCUMENT_LABELS[k].title).join(", ")}.</span>
        ) : (
          !consent && <span className="text-theme-xs text-ink-subtle">Tick the privacy box to submit.</span>
        )}
      </div>
      <p className="text-theme-xs text-ink-subtle">After you submit, you can no longer change your answers or documents.</p>
    </div>
  );
}
