"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import {
  getStorefrontPricing,
  updateStorefrontMarkup,
  previewStorefrontMarkup,
  type StorefrontPricing,
  type MarkupPreview,
} from "@/lib/portal";
import { formatRm } from "@/lib/format";
import { Panel, ErrorNote } from "@/components/composites";
import { Input } from "@/components/ui/input";
import { Field, SaveButton, InactiveNotice, TabLoading } from "./shared";

export default function PricingTab() {
  const [pricing, setPricing] = useState<StorefrontPricing | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [value, setValue] = useState("");
  const [preview, setPreview] = useState<MarkupPreview | null>(null);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [writable, setWritable] = useState(true);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const runPreview = useCallback((markup: number) => {
    const session = getClientSession();
    if (!session) return;
    previewStorefrontMarkup(session.token, markup)
      .then(setPreview)
      .catch(() => setPreview(null));
  }, []);

  useEffect(() => {
    const session = getClientSession();
    if (!session) return;
    let cancelled = false;
    getStorefrontPricing(session.token)
      .then((result) => {
        if (cancelled) return;
        setPricing(result);
        setValue(String(result.markup_pct));
        setWritable(true);
        if (!result.is_owned) runPreview(result.effective_markup_pct);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        if (err instanceof ApiError && err.status === 403) setWritable(false);
        setError(err instanceof ApiError ? err.message : "Could not load your pricing.");
      });
    return () => {
      cancelled = true;
    };
  }, [runPreview]);

  function onChange(next: string) {
    setValue(next);
    setSaved(false);
    const markup = Number(next);
    if (!Number.isFinite(markup) || markup < 0) return;
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(() => runPreview(markup), 350);
  }

  async function save(event: React.FormEvent) {
    event.preventDefault();
    const session = getClientSession();
    if (!session) return;
    setError(null);
    setSaving(true);
    try {
      const result = await updateStorefrontMarkup(session.token, Number(value));
      setPricing(result);
      setValue(String(result.markup_pct));
      setSaved(true);
      runPreview(result.effective_markup_pct);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save your markup.");
    } finally {
      setSaving(false);
    }
  }

  if (error && !pricing) return <ErrorNote message={error} />;
  if (!pricing) return <TabLoading />;

  if (pricing.is_owned) {
    return (
      <Panel title="Your pricing">
        <p className="p-5 text-theme-sm text-ink-subtle">
          Your brand prices as the platform: there is no markup to set, and your storefront shows the standard
          prices.
        </p>
      </Panel>
    );
  }

  const ceiling = pricing.max_markup_pct;
  const clamped = ceiling !== null && pricing.markup_pct > ceiling;

  return (
    <div className="space-y-6">
      {error && <ErrorNote message={error} />}
      {!writable && <InactiveNotice />}

      <Panel title="Your markup">
        <form onSubmit={save} className="space-y-4 p-5">
          <Field
            label="Markup over wholesale (%)"
            hint={
              ceiling === null
                ? "The platform has not opened a markup range for your brand yet."
                : `The platform allows up to ${ceiling}%. Set 0 to sell at wholesale.`
            }
          >
            <Input
              type="number"
              min={0}
              max={ceiling ?? 0}
              step={0.5}
              value={value}
              onChange={(e) => onChange(e.target.value)}
              disabled={!writable}
              className="max-w-[160px]"
            />
          </Field>

          {clamped && (
            <p className="rounded-md bg-warning-surface px-3 py-2 text-theme-xs text-warning-ink">
              Your saved markup is {pricing.markup_pct}%, but the platform ceiling is now{" "}
              {ceiling}% — your storefront currently prices at {pricing.effective_markup_pct}%. Save
              a value at or below the ceiling to clear this.
            </p>
          )}

          {!pricing.wholesale_rate_active && (
            <p className="rounded-md bg-subtle px-3 py-2 text-theme-xs text-ink-muted">
              Your wholesale rate is paused while your subscription is lapsed — margins below are shown at
              standard pricing.
            </p>
          )}

          {writable && <SaveButton saving={saving} saved={saved} />}
        </form>
      </Panel>

      <Panel title="Preview">
        {preview === null ? (
          <p className="p-5 text-theme-sm text-ink-subtle">
            Enter a markup to preview customer prices.
          </p>
        ) : preview.rows.length === 0 ? (
          <p className="p-5 text-theme-sm text-ink-subtle">
            No packages in your visible catalog yet.
          </p>
        ) : (
          <div className="divide-y divide-border">
            {preview.rows.map((row, i) => (
              <div key={i} className="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-theme-sm">
                <div className="min-w-0">
                  <p className="truncate font-medium text-ink">{row.package_name}</p>
                  {row.game_name && (
                    <p className="text-theme-xs text-ink-subtle">{row.game_name}</p>
                  )}
                </div>
                <div className="text-right">
                  <p className="text-ink">
                    Customer pays <span className="font-medium">{formatRm(row.customer_price_sen)}</span>
                  </p>
                  <p className="text-theme-xs text-success-ink">
                    You earn {formatRm(row.your_margin_sen)}
                  </p>
                </div>
              </div>
            ))}
          </div>
        )}
      </Panel>
    </div>
  );
}
