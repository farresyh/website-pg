"use client";

import { Tag as PrimeTag } from "primereact/tag";

/** Small presentational helpers shared across the portal's read screens. */

export function PageHeader({
  title,
  subtitle,
  action,
}: {
  title: string;
  subtitle?: string;
  action?: React.ReactNode;
}) {
  return (
    <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">
          {title}
        </h1>
        {subtitle && (
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            {subtitle}
          </p>
        )}
      </div>
      {action}
    </div>
  );
}

export function StatCard({
  label,
  value,
  hint,
}: {
  label: string;
  value: string;
  hint?: string;
}) {
  return (
    <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
      <p className="text-theme-xs text-gray-500 dark:text-gray-400">{label}</p>
      <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">
        {value}
      </p>
      {hint && (
        <p className="text-theme-xs text-gray-500 dark:text-gray-400">{hint}</p>
      )}
    </div>
  );
}

export function Panel({
  title,
  children,
}: {
  title?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
      {title && (
        <div className="border-b border-gray-100 px-5 py-3 text-theme-sm font-medium text-gray-700 dark:border-gray-800 dark:text-gray-300">
          {title}
        </div>
      )}
      {children}
    </div>
  );
}

const SEVERITY: Record<string, string> = {
  success:
    "bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500",
  warn:
    "bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-500",
  danger:
    "bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500",
  info: "bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400",
  muted: "bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300",
};

export function StatusTag({
  children,
  severity = "muted",
}: {
  children: React.ReactNode;
  severity?: keyof typeof SEVERITY | string;
}) {
  return (
    <PrimeTag
      severity={severity === "muted" ? undefined : (severity as "success" | "warn" | "danger" | "info")}
      rounded
      className={`text-theme-xs font-medium capitalize ${SEVERITY[severity] ?? SEVERITY.muted}`}
    >
      {children}
    </PrimeTag>
  );
}

export function ErrorNote({ message }: { message: string }) {
  return (
    <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
      {message}
    </p>
  );
}

export function EmptyRow({ children }: { children: React.ReactNode }) {
  return (
    <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">
      {children}
    </p>
  );
}
