type StepState = "done" | "active" | "pending";

export interface StepInfo {
  label: string;
  state: StepState;
}

/**
 * Same circle+connecting-line visual language as OrderStatusTracker's
 * stage tracker — ties the wizard and the post-payment status page
 * together as one visual system instead of two unrelated components.
 */
export default function Stepper({ steps }: { steps: StepInfo[] }) {
  return (
    <div className="flex items-center gap-2 rounded-xl border border-border bg-surface p-4">
      {steps.map((step, i) => (
        <div key={step.label} className="flex flex-1 items-center gap-2 last:flex-none">
          <div className="flex items-center gap-2.5">
            <div
              className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full border font-mono text-xs font-bold ${
                step.state === "done"
                  ? "border-brand bg-brand text-on-brand"
                  : step.state === "active"
                    ? "border-brand bg-surface-2 text-brand-light"
                    : "border-border bg-bg text-text-muted"
              }`}
            >
              {step.state === "done" ? "✓" : i + 1}
            </div>
            <span className={`text-[13px] font-semibold whitespace-nowrap ${step.state === "pending" ? "text-text-muted" : "text-text"}`}>
              {step.label}
            </span>
          </div>
          {i < steps.length - 1 && <div className="h-px min-w-4 flex-1 bg-border" />}
        </div>
      ))}
    </div>
  );
}
