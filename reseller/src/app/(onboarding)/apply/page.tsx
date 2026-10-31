"use client";

import { useEffect, useState } from "react";
import { ErrorNote, Panel } from "@/components/composites";
import NoticeBox from "@/components/application/NoticeBox";
import TurnstileWidget, { turnstileConfigured } from "@/components/application/TurnstileWidget";
import { Field } from "@/components/storefront/shared";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { OptionSelect } from "@/components/ui/option-select";
import { Textarea } from "@/components/ui/textarea";
import { ApiError } from "@/lib/api-client";
import {
  getApplicationStatus,
  ORDER_CHANNELS,
  SALES_RANGES,
  submitStageOne,
  type ApplicantType,
  type ApplicationStatus,
} from "@/lib/application";

/**
 * ADR-133 decision 3: stage 1 of the reseller application. No document is asked here; the founder
 * screens these answers first. The email is confirmed by a link before the application counts.
 */
const EMPTY = {
  applicant_type: "company" as ApplicantType,
  owner_name: "",
  business_name: "",
  email: "",
  phone: "",
  experience: "",
  monthly_sales_range: "",
  main_games: "",
  order_channel: "",
  heard_from: "",
};

export default function ApplyPage() {
  const [status, setStatus] = useState<ApplicationStatus | "error" | null>(null);
  const [form, setForm] = useState(EMPTY);
  const [consent, setConsent] = useState(false);
  const [token, setToken] = useState<string | null>(null);
  const [verifyFailed, setVerifyFailed] = useState(false);
  const [resetKey, setResetKey] = useState(0);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [sentTo, setSentTo] = useState<string | null>(null);

  useEffect(() => {
    getApplicationStatus().then(setStatus, () => setStatus("error"));
  }, []);

  const set = (key: keyof typeof EMPTY, value: string) => setForm((f) => ({ ...f, [key]: value }));
  const isCompany = form.applicant_type === "company";
  const fieldError = (key: string) => fieldErrors[key]?.[0];

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!token) return;
    setSubmitting(true);
    setError(null);
    setFieldErrors({});
    try {
      await submitStageOne({
        ...form,
        business_name: isCompany ? form.business_name.trim() : form.business_name.trim() || null,
        experience: form.experience.trim() || null,
        monthly_sales_range: form.monthly_sales_range || null,
        main_games: form.main_games.trim() || null,
        order_channel: form.order_channel || null,
        heard_from: form.heard_from.trim() || null,
        consent,
        turnstile_token: token,
      });
      setSentTo(form.email.trim());
    } catch (err) {
      if (err instanceof ApiError && err.status === 422 && err.errors) {
        setFieldErrors(err.errors);
        setError(err.errors.email?.[0] ?? "Please check the highlighted fields.");
      } else if (err instanceof ApiError && err.status === 429) {
        setError("Too many attempts from your connection. Please wait a few minutes and try again.");
      } else {
        setError(err instanceof ApiError ? err.message : "Could not reach the server. Please try again.");
      }
    } finally {
      setSubmitting(false);
      setToken(null);
      setResetKey((k) => k + 1);
    }
  }

  if (status === null) return <p className="text-sm text-ink-muted">Loading…</p>;
  if (status === "error") return <ErrorNote message="The application form could not be loaded. Please try again later." />;

  if (sentTo) {
    return (
      <Panel title="Check your email">
        <div className="space-y-3 p-5 text-sm text-ink-muted">
          <p>
            We sent a link to <b className="font-semibold text-ink">{sentTo}</b>. Open it within 24 hours to confirm your email; your
            application reaches us only after that.
          </p>
          <p>No email? Check your spam folder, or send the form again to get a new link.</p>
        </div>
      </Panel>
    );
  }

  if (!status.open) {
    return (
      <Panel title="Applications are closed">
        <p className="p-5 text-sm text-ink-muted">
          We are not taking reseller applications right now. Please check back later, or email partners@pekangame.space.
        </p>
      </Panel>
    );
  }

  const ready = consent && token !== null && !submitting;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-[26px] font-[650] leading-8 tracking-[-0.02em] text-ink">Become a PekanGame reseller</h1>
        <p className="mt-2 text-sm text-ink-muted">
          Tell us about you and your business. We review every application, and if it is a fit we email you a link to send your
          documents. No documents are needed at this step.
        </p>
      </div>

      {error && <ErrorNote message={error} />}

      <form onSubmit={submit} className="space-y-6" noValidate>
        <Panel title="About you">
          <div className="space-y-4 p-5">
            <fieldset>
              <legend className="mb-1.5 text-theme-sm font-medium text-ink-muted">You are applying as</legend>
              <div className="flex flex-wrap gap-4 text-sm text-ink">
                {(
                  [
                    ["company", "A company (registered with SSM)"],
                    ["individual", "An individual (not registered yet)"],
                  ] as const
                ).map(([value, label]) => (
                  <label key={value} className="flex items-center gap-2">
                    <input type="radio" name="applicant_type" value={value} checked={form.applicant_type === value} onChange={() => set("applicant_type", value)} />
                    {label}
                  </label>
                ))}
              </div>
            </fieldset>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Your full name">
                <Input value={form.owner_name} onChange={(e) => set("owner_name", e.target.value)} autoComplete="name" required error={Boolean(fieldError("owner_name"))} hint={fieldError("owner_name")} />
              </Field>
              <Field label={isCompany ? "Company name" : "Business or shop name (optional)"}>
                <Input value={form.business_name} onChange={(e) => set("business_name", e.target.value)} autoComplete="organization" required={isCompany} error={Boolean(fieldError("business_name"))} hint={fieldError("business_name")} />
              </Field>
              <Field label="Email">
                <Input type="email" value={form.email} onChange={(e) => set("email", e.target.value)} autoComplete="email" required error={Boolean(fieldError("email"))} />
              </Field>
              <Field label="Phone (WhatsApp)">
                <Input type="tel" value={form.phone} onChange={(e) => set("phone", e.target.value)} autoComplete="tel" required error={Boolean(fieldError("phone"))} hint={fieldError("phone")} />
              </Field>
            </div>
          </div>
        </Panel>

        <Panel title="Your reselling">
          <div className="space-y-4 p-5">
            <Field label="Your reselling experience (optional)" hint="How long, and on which platforms (for example Shopee, TikTok Shop, your own website).">
              <Textarea value={form.experience} onChange={(e) => set("experience", e.target.value)} maxLength={2000} />
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Estimated monthly sales (optional)">
                <OptionSelect value={form.monthly_sales_range} options={SALES_RANGES} onChange={(v) => set("monthly_sales_range", v)} placeholder="Choose a range" ariaLabel="Estimated monthly sales" />
              </Field>
              <Field label="How you plan to order (optional)">
                <OptionSelect value={form.order_channel} options={ORDER_CHANNELS} onChange={(v) => set("order_channel", v)} placeholder="Choose one" ariaLabel="How you plan to order" />
              </Field>
              <Field label="Main games you sell (optional)">
                <Input value={form.main_games} onChange={(e) => set("main_games", e.target.value)} maxLength={500} placeholder="For example MLBB, Free Fire" />
              </Field>
              <Field label="How did you hear about us? (optional)">
                <Input value={form.heard_from} onChange={(e) => set("heard_from", e.target.value)} maxLength={255} />
              </Field>
            </div>
          </div>
        </Panel>

        <Panel title="Privacy">
          <div className="space-y-4 p-5">
            <NoticeBox notice={status.notice} />
            <label className="flex items-start gap-2.5 text-sm text-ink">
              <input type="checkbox" className="mt-1" checked={consent} onChange={(e) => setConsent(e.target.checked)} />
              <span>I have read the privacy notice and agree to PekanGame using my details as it describes. / Saya telah membaca notis privasi dan bersetuju.</span>
            </label>
            {fieldError("consent") && <p className="text-theme-xs text-danger-ink">{fieldError("consent")}</p>}
          </div>
        </Panel>

        {turnstileConfigured ? (
          <TurnstileWidget
            resetKey={resetKey}
            onToken={(t) => {
              setToken(t);
              if (t) setVerifyFailed(false);
            }}
            onError={() => setVerifyFailed(true)}
          />
        ) : (
          <ErrorNote message="The verification check is not available right now, so the form cannot be sent. Please try again later." />
        )}
        {verifyFailed && <ErrorNote message="The verification check could not load. Refresh the page and try again." />}

        <div className="flex flex-wrap items-center gap-3">
          <Button type="submit" disabled={!ready}>
            {submitting ? "Sending…" : "Send application"}
          </Button>
          {!consent && <span className="text-theme-xs text-ink-subtle">Tick the privacy box to continue.</span>}
          {consent && token === null && turnstileConfigured && !verifyFailed && <span className="text-theme-xs text-ink-subtle">Checking your browser…</span>}
        </div>
      </form>
    </div>
  );
}
