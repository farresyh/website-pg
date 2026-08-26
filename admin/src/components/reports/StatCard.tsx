import type { ReactNode } from "react";

const ICON_THEME = {
  blue: "bg-blue-50 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400",
  indigo: "bg-indigo-50 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-400",
  green: "bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-400",
  violet: "bg-violet-50 text-violet-600 dark:bg-violet-500/15 dark:text-violet-400",
  amber: "bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400",
} as const;

export function StatCard({
  icon,
  color,
  label,
  value,
  sub,
}: {
  icon: ReactNode;
  color: keyof typeof ICON_THEME;
  label: string;
  value: string;
  sub?: string;
}) {
  return (
    <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
      <div className={`mb-3 flex h-9 w-9 items-center justify-center rounded-lg ${ICON_THEME[color]}`}>{icon}</div>
      <p className="text-theme-xs text-gray-500 dark:text-gray-400">{label}</p>
      <p className="mt-1 truncate text-lg font-semibold text-gray-800 dark:text-white/90">{value}</p>
      {sub && <p className="mt-0.5 text-theme-xs text-gray-400 dark:text-gray-500">{sub}</p>}
    </div>
  );
}
