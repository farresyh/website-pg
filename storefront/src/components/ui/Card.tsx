import type { ElementType, ReactNode } from "react";

/**
 * ADR-064 primitive. The display-tier container for this world: 2px ink
 * border, hard offset shadow, 8px radius, white ground. `tier="flat"`
 * drops the shadow for nested / utility contexts; `interactive` adds the
 * hover lift (pair with a real link/button wrapper for semantics).
 */
export default function Card({
  as: Tag = "div",
  tier = "raised",
  interactive = false,
  className = "",
  children,
}: {
  as?: ElementType;
  tier?: "raised" | "flat";
  interactive?: boolean;
  className?: string;
  children: ReactNode;
}) {
  const base = "rounded-lg border-2 border-ink bg-surface-container-lowest";
  const elevation = tier === "raised" ? "neo" : "";
  const hover = interactive ? "neo-hover transition-all cursor-pointer" : "";
  return <Tag className={`${base} ${elevation} ${hover} ${className}`}>{children}</Tag>;
}
