import { Tag } from "@/components/ui/tag";
import { needLabel, type Readiness } from "@/lib/readiness";

/** One cell of the Affiliates and Resellers lists: ready, waiting on you, or what is still open. */
export default function ReadinessCell({ readiness }: { readiness: Readiness | null | undefined }) {
  if (!readiness) return <span className="text-ink-subtle">—</span>;
  if (readiness.ready) return <Tag severity="success">Ready</Tag>;
  if (readiness.waiting_on_us) return <Tag severity="warn">Awaiting your approval</Tag>;

  return <span className="text-theme-sm text-gray-600 dark:text-gray-300">Needs: {readiness.needs.map(needLabel).join(", ")}</span>;
}
