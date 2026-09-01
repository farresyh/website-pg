"use client";

/**
 * DASH-1 — wraps reports/StatCard with the comparison badge + info
 * tooltip every dashboard metric carries (ADR-045 decisions 4/25).
 * StatCard itself is left untouched (still used bare by Reports).
 */

import type { ReactNode } from "react";
import { StatCard } from "@/components/reports/StatCard";
import { ComparisonBadge } from "./ComparisonBadge";
import { InfoTooltip } from "./InfoTooltip";
import type { DashboardMetric } from "@/lib/dashboard";

type StatCardColor = Parameters<typeof StatCard>[0]["color"];

export function DashboardKpiCard({
  icon,
  color,
  label,
  value,
  sub,
  metric,
}: {
  icon: ReactNode;
  color: StatCardColor;
  label: string;
  value: string;
  sub?: string;
  metric: DashboardMetric | null;
}) {
  return (
    <div className="relative">
      <StatCard icon={icon} color={color} label={label} value={value} sub={sub} />
      <div className="absolute top-4 right-4 flex items-center gap-1.5">
        {metric && <ComparisonBadge comparison={metric.comparison} />}
        {metric && <InfoTooltip definition={metric.definition} />}
      </div>
    </div>
  );
}
