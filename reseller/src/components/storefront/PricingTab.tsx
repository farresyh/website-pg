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
import { Panel, ErrorNote } from "@/components/ui";
import { Field, inputClass, SaveButton, InactiveNotice, TabLoading } from "./shared";

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
        runPreview(result.effective_markup_pct);
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

  const clamped = pricing.markup_pct > pricing.max_markup_pct;

  return (
    <div className="space-y-6">
      {error && <ErrorNote message={error} />}
      {!writable && <InactiveNotice />}

      <Panel title="Your markup">
        <form onSubmit={save} className="space-y-4 p-5">
          <Field
            label="Markup over wholesale (%)"
            hint={`The platform allows up to ${pricing.max_markup_pct}%. Set 0 to sell at wholesale.`}
          >
            <input
              type="number"
              min={0}
              max={pricing.max_markup_pct}
              step={0.5}
              value={value}
              onChange={(e) => onChange(e.target.value)}
              disabled={!writable}
              className={`${inputClass} max-w-[160px]`}
            />
          </Field>

          {clamped && (
            <p className="rounded-lg bg-warning-50 px-3 py-2 text-theme-xs text-warning-700 dark:bg-warning-500/15 dark:text-warning-500">
              Your saved markup is {pricing.markup_pct}%, but the platform ceiling is now{" "}
              {pricing.max_markup_pct}% — your storefront currently prices at {pricing.effective_markup_pct}%. Save
              a value at or below the ceiling to clear this.
            </p>
          )}

          {!pricing.wholesale_rate_active && (
            <p className="rounded-lg bg-gray-100 px-3 py-2 text-theme-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">
              Your wholesale rate is paused while your subscription is lapsed — margins below are shown at
              standard pricing.
            </p>
          )}

          {writable && <SaveButton saving={saving} saved={saved} />}
        </form>
      </Panel>

      <Panel title="Preview">
        {preview === null ? (
          <p className="p-5 text-theme-sm text-gray-500 dark:text-gray-400">
            Enter a markup to preview customer prices.
          </p>
        ) : preview.rows.length === 0 ? (
          <p className="p-5 text-theme-sm text-gray-500 dark:text-gray-400">
            No packages in your visible catalog yet.
          </p>
        ) : (
          <div className="divide-y divide-gray-100 dark:divide-gray-800">
            {preview.rows.map((row, i) => (
              <div key={i} className="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-theme-sm">
                <div className="min-w-0">
                  <p className="truncate font-medium text-gray-800 dark:text-white/90">{row.package_name}</p>
                  {row.game_name && (
                    <p className="text-theme-xs text-gray-500 dark:text-gray-400">{row.game_name}</p>
                  )}
                </div>
                <div className="text-right">
                  <p className="text-gray-800 dark:text-white/90">
                    Customer pays <span className="font-medium">{formatRm(row.customer_price_sen)}</span>
                  </p>
                  <p className="text-theme-xs text-success-600 dark:text-success-500">
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
