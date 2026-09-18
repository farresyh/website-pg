import { Check } from "@phosphor-icons/react/dist/ssr";

type StepState = "done" | "active" | "pending";

export interface StepInfo {
  label: string;
  state: StepState;
}

/**
 * ADR-064: the wizard progress bar, in the neo-brutalist language —
 * shared circle+line vocabulary with OrderStatusTracker so the wizard
 * and the post-payment status page read as one system.
 */
export default function Stepper({ steps }: { steps: StepInfo[] }) {
  const activeIndex = steps.findIndex((s) => s.state === "active");
  const current = activeIndex >= 0 ? activeIndex : steps.length - 1;

  return (
    <div className="rounded-lg border-2 border-ink bg-surface-container-lowest p-4 neo">
      <div className="flex items-center gap-2">
        {steps.map((step, i) => (
          <div key={step.label} className="flex flex-1 items-center gap-2 last:flex-none">
            <div className="flex items-center gap-2.5">
              <div
                className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 border-ink font-mono text-xs font-bold ${
                  step.state === "done"
                    ? "bg-primary text-on-primary"
                    : step.state === "active"
                      ? "bg-secondary-container text-on-secondary-container"
                      : "bg-surface-container-lowest text-on-surface-variant"
                }`}
              >
                {step.state === "done" ? <Check size={14} weight="bold" /> : i + 1}
              </div>
              {/* Full labels only fit on wider screens; on mobile the
                * caption below names the current step instead. */}
              <span
                className={`hidden whitespace-nowrap font-display text-[12px] font-bold uppercase tracking-wide lg:inline ${
                  step.state === "pending" ? "text-on-surface-variant" : "text-on-surface"
                }`}
              >
                {step.label}
              </span>
            </div>
            {i < steps.length - 1 && <div className="h-0.5 min-w-4 flex-1 bg-ink/30" />}
          </div>
        ))}
      </div>

      <p className="mt-3 font-display text-[12px] font-bold uppercase tracking-wide text-on-surface lg:hidden">
        <span className="text-on-surface-variant">
          Step {current + 1} of {steps.length}:{" "}
        </span>
        {steps[current]?.label}
      </p>
    </div>
  );
}
