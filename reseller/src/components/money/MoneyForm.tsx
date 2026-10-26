"use client";

/**
 * Pieces of the portal's money forms (ADR-128 Money): a large ringgit field
 * with quick amounts, and a numbered "what happens" card. Amounts stay the
 * text the person typed; the page converts to sen once, on submit.
 */
export function AmountField({
  id,
  value,
  onChange,
  quick,
}: {
  id: string;
  value: string;
  onChange: (value: string) => void;
  quick: { label: string; value: string }[];
}) {
  return (
    <div className="flex flex-col gap-2">
      <label htmlFor={id} className="text-[13px] font-semibold text-ink">
        Amount
      </label>
      <span className="relative">
        <span className="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-base font-semibold text-ink-subtle">RM</span>
        <input
          id={id}
          inputMode="decimal"
          autoComplete="off"
          value={value}
          onChange={(e) => onChange(e.target.value.replace(/[^\d.]/g, ""))}
          className="h-[52px] w-full rounded-md border border-border-control bg-surface pr-3 pl-12 text-[22px] font-[650] tracking-[-0.01em] text-ink focus:border-transparent focus:outline-2 focus:outline-offset-[-1px] focus:outline-focus-ring"
        />
      </span>
      <div role="group" aria-label="Quick amounts" className="flex flex-wrap gap-2">
        {quick.map((q) => {
          const pressed = Number(value) === Number(q.value);
          return (
            <button
              key={q.label}
              type="button"
              aria-pressed={pressed}
              onClick={() => onChange(q.value)}
              className={`min-h-11 rounded-md border px-3 text-[13px] sm:min-h-8 ${
                pressed ? "border-cyan-600 bg-cyan-50 font-semibold text-cyan-ink" : "border-border-control bg-surface font-medium text-ink-muted hover:bg-subtle hover:text-ink"
              }`}
            >
              {q.label}
            </button>
          );
        })}
      </div>
    </div>
  );
}

export function StepsCard({ title, steps }: { title: string; steps: { title: string; body: string }[] }) {
  return (
    <section className="rounded-lg border border-border bg-surface px-5 py-5 shadow-xs">
      <h2 className="text-[15px] font-semibold text-ink">{title}</h2>
      <ol className="mt-3 flex flex-col gap-3">
        {steps.map((step, i) => (
          <li key={step.title} className="flex gap-3">
            <span className="grid h-6 w-6 shrink-0 place-items-center rounded-sm bg-nude-100 text-xs font-bold text-nude-ink">{i + 1}</span>
            <span className="flex flex-col">
              <b className="text-[13px] font-semibold text-ink">{step.title}</b>
              <span className="text-[13px] text-ink-muted">{step.body}</span>
            </span>
          </li>
        ))}
      </ol>
    </section>
  );
}

