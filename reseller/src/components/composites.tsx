"use client";

import { useState } from "react";
import { PortalIcon, type PortalIconName } from "@/components/shell/PortalIcon";
import { Button } from "@/components/ui/button";
import { Tag } from "@/components/ui/tag";

/**
 * The portal's own composites — presentational pieces the admin has no
 * equivalent for (page header, cards, balance hero, copy-id, pager …).
 * Built on the ADR-104 tokens in the ADR-128 "PekanGame Partner" variant,
 * and on the shared kit (`components/ui`) for primitives. Primitives that
 * the admin kit already provides (Button, Input, Tag, Switch, Tabs, Select,
 * Dialog …) are NOT defined here — import them from `components/ui/*`.
 */

export function PageHeader({
  title,
  subtitle,
  action,
}: {
  title: string;
  subtitle?: React.ReactNode;
  action?: React.ReactNode;
}) {
  return (
    <div className="mb-6 flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
      <div className="min-w-0">
        <h1 className="text-[26px] font-[650] leading-8 tracking-[-0.02em] text-ink">{title}</h1>
        {subtitle && <p className="mt-1 text-sm text-ink-muted">{subtitle}</p>}
      </div>
      {action && <div className="flex flex-wrap items-center gap-2">{action}</div>}
    </div>
  );
}

export function StatCard({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div className="rounded-lg border border-border bg-surface px-5 py-4 shadow-xs">
      <p className="text-[13px] font-medium text-ink-muted">{label}</p>
      <p className="mt-1.5 text-2xl font-[650] leading-8 tracking-[-0.02em] text-ink">{value}</p>
      {hint && <p className="mt-1 text-xs text-ink-subtle">{hint}</p>}
    </div>
  );
}

/** A card. `title` gives the classic header row; use `CardHead` for a title with a description and actions. */
export function Panel({ title, children, className = "" }: { title?: string; children: React.ReactNode; className?: string }) {
  return (
    <section className={`overflow-hidden rounded-lg border border-border bg-surface shadow-xs ${className}`}>
      {title && <div className="border-b border-border px-5 py-3.5 text-[15px] font-semibold leading-[22px] text-ink">{title}</div>}
      {children}
    </section>
  );
}

export function CardHead({
  title,
  description,
  action,
  divided = false,
}: {
  title: string;
  description?: string;
  action?: React.ReactNode;
  divided?: boolean;
}) {
  return (
    <div className={`flex items-center justify-between gap-3 px-5 pt-4 ${divided ? "border-b border-border pb-4" : "pb-4"}`}>
      <div className="min-w-0">
        <h2 className="text-[15px] font-semibold leading-[22px] text-ink">{title}</h2>
        {description && <p className="mt-0.5 text-xs text-ink-subtle">{description}</p>}
      </div>
      {action}
    </div>
  );
}

export type BadgeTone = "success" | "warning" | "danger" | "review" | "neutral" | "info";

const TONE_TO_SEVERITY: Record<BadgeTone, "success" | "warn" | "danger" | "review" | "secondary" | "info"> = {
  success: "success",
  warning: "warn",
  danger: "danger",
  review: "review",
  neutral: "secondary",
  info: "info",
};

/**
 * The portal's status badge — a thin wrapper on the shared kit's `Tag`
 * (ADR-128), keeping the portal's `tone` vocabulary and its always-on dot.
 * Colour never carries the meaning alone: every status is a word with a dot.
 */
export function Badge({ tone, children, large = false }: { tone: BadgeTone; children: React.ReactNode; large?: boolean }) {
  return (
    <Tag severity={TONE_TO_SEVERITY[tone]} dot className={large ? "h-[26px] px-2.5 text-[13px]" : ""}>
      {children}
    </Tag>
  );
}

const SEVERITY_TO_TONE: Record<string, BadgeTone> = { success: "success", warn: "warning", danger: "danger", muted: "neutral" };

/** The older severity names, kept for the screens PRs 3–4 haven't moved yet. */
export function StatusTag({ children, severity = "muted" }: { children: React.ReactNode; severity?: string }) {
  return <Badge tone={SEVERITY_TO_TONE[severity] ?? "neutral"}>{children}</Badge>;
}

export function ErrorNote({ message }: { message: string }) {
  return (
    <p role="alert" className="mb-4 rounded-lg border border-danger-ink/25 bg-danger-surface px-4 py-3 text-sm text-danger-ink">
      {message}
    </p>
  );
}

const BUTTON = {
  primary: "bg-cyan-600 text-on-cyan hover:bg-cyan-700",
  secondary: "border border-border-strong bg-surface text-ink shadow-xs hover:bg-subtle",
  ghost: "text-ink-muted hover:bg-overlay hover:text-ink",
};

/**
 * Class names for a **link** (`<Link>` / `<a>`) styled as the design
 * system's pg-btn. Real `<button>` elements use the shared kit's `Button`
 * instead (ADR-128); this stays only because a navigation link must keep
 * its `<a>` semantics (right-click, middle-click) that `Button` cannot.
 */
export function buttonClass(variant: keyof typeof BUTTON = "secondary", small = false): string {
  return `inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md font-semibold disabled:cursor-not-allowed disabled:opacity-45 ${
    small ? "h-[30px] px-2.5 text-xs" : "min-h-11 px-3.5 text-[13px] sm:min-h-9"
  } ${BUTTON[variant]}`;
}

export function EmptyRow({ children }: { children: React.ReactNode }) {
  return <p className="p-6 text-center text-sm text-ink-subtle">{children}</p>;
}

/** Order numbers, codes and IDs: mono, never wrapped, one click to copy. */
export function CopyId({ value, label = "Copy", large = false }: { value: string; label?: string; large?: boolean }) {
  const [copied, setCopied] = useState(false);
  return (
    <span className={`inline-flex min-w-0 items-center gap-1 font-mono font-medium tracking-[-0.01em] text-ink ${large ? "text-2xl leading-8" : "text-[13px] leading-[18px]"}`}>
      <span className="truncate">{value}</span>
      <button
        type="button"
        aria-label={`${label} ${value}`}
        onClick={(event) => {
          event.preventDefault();
          event.stopPropagation();
          void navigator.clipboard?.writeText(value).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
          });
        }}
        className="grid h-7 w-7 shrink-0 place-items-center rounded-sm text-ink-subtle hover:bg-subtle hover:text-ink"
      >
        <PortalIcon name={copied ? "check" : "copy"} className="h-3.5 w-3.5" />
      </button>
    </span>
  );
}

/** Two letters for a game until the real icon is wired. */
export function GameThumb({ name }: { name: string }) {
  const letters = name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0]!.toUpperCase())
    .join("");
  return (
    <span aria-hidden="true" className="grid h-8 w-8 shrink-0 place-items-center rounded-sm border border-border bg-subtle text-[11px] font-bold tracking-[0.02em] text-ink-muted">
      {letters || "—"}
    </span>
  );
}

export function GameCell({ game, pkg }: { game: string | null | undefined; pkg: string | null | undefined }) {
  return (
    <span className="flex min-w-0 items-center gap-2.5">
      <GameThumb name={game ?? ""} />
      <span className="flex min-w-0 flex-col">
        <span className="truncate text-sm font-semibold text-ink">{game ?? "—"}</span>
        <span className="truncate text-xs text-ink-subtle">{pkg ?? "—"}</span>
      </span>
    </span>
  );
}

/** A labelled figure under a balance or in a card: label, value, quiet sub-line. */
export function Facts({ items, columns = 3 }: { items: { label: string; value: React.ReactNode; sub?: React.ReactNode }[]; columns?: 2 | 3 }) {
  return (
    <dl className={`mt-4 grid gap-4 border-t border-border pt-4 ${columns === 2 ? "grid-cols-2" : "grid-cols-2 sm:grid-cols-3"}`}>
      {items.map((item) => (
        <div key={item.label} className="min-w-0">
          <dt className="text-xs text-ink-subtle">{item.label}</dt>
          <dd className="mt-0.5 truncate text-sm font-semibold text-ink">{item.value}</dd>
          {item.sub && <dd className="truncate text-xs text-ink-subtle">{item.sub}</dd>}
        </div>
      ))}
    </dl>
  );
}

/** The one big figure a money page leads with (ADR-128: balance hero). */
export function BalanceHero({
  icon,
  label,
  amount,
  note,
  actions,
  children,
}: {
  icon: PortalIconName;
  label: string;
  /** Pre-formatted by `formatRm`; the "RM" prefix is set quieter here. */
  amount: string;
  note: string;
  actions?: React.ReactNode;
  children?: React.ReactNode;
}) {
  const [currency, ...rest] = amount.startsWith("-") ? ["-RM", amount.slice(4)] : amount.split(" ");
  return (
    <section className="rounded-lg border border-border bg-surface px-5 py-5 shadow-xs sm:px-6">
      <div className="flex items-center justify-between gap-2">
        <span className="inline-flex items-center gap-1.5 text-[13px] font-medium text-ink-muted">
          <PortalIcon name={icon} className="h-4 w-4" />
          {label}
        </span>
        <span className="rounded-sm border border-border-strong px-1.5 text-[11px] font-semibold leading-[18px] text-ink-muted">MYR</span>
      </div>
      <p className="mt-1.5 text-[36px] font-[650] leading-[44px] tracking-[-0.03em] text-ink sm:text-[44px] sm:leading-[52px]">
        <span className="mr-1.5 align-[10px] text-lg font-semibold tracking-normal text-ink-subtle sm:text-xl">{currency}</span>
        {rest.join(" ")}
      </p>
      <p className="mt-1 text-[13px] text-ink-muted">{note}</p>
      {actions && <div className="mt-4 flex flex-wrap gap-2">{actions}</div>}
      {children}
    </section>
  );
}

export function Pager({
  page,
  lastPage,
  total,
  perPage,
  onPage,
}: {
  page: number;
  lastPage: number;
  total: number;
  perPage: number;
  onPage: (page: number) => void;
}) {
  if (total === 0) return null;
  const from = (page - 1) * perPage + 1;
  const to = Math.min(page * perPage, total);
  return (
    <div className="flex items-center justify-between gap-3 border-t border-border px-4 py-3 text-xs text-ink-subtle">
      <span>
        Showing {from}–{to} of {total}
      </span>
      {lastPage > 1 && (
        <div className="flex gap-1.5">
          <Button variant="outlined" severity="secondary" size="small" disabled={page <= 1} onClick={() => onPage(page - 1)}>
            Previous
          </Button>
          <Button variant="outlined" severity="secondary" size="small" disabled={page >= lastPage} onClick={() => onPage(page + 1)}>
            Next
          </Button>
        </div>
      )}
    </div>
  );
}

/** A loading placeholder the size of the content it stands for. */
export function Skeleton({ className }: { className: string }) {
  return <span aria-hidden="true" className={`block animate-pulse rounded-md bg-subtle ${className}`} />;
}

/**
 * ADR-133 decision 16: an empty screen says what it is for and offers the one
 * next step, instead of a bare "No data yet".
 */
export function EmptyState({ title, text, action }: { title: string; text: string; action?: React.ReactNode }) {
  return (
    <div className="border-t border-border px-6 py-10 text-center">
      <p className="text-sm font-semibold text-ink">{title}</p>
      <p className="mx-auto mt-1 max-w-md text-sm text-ink-subtle">{text}</p>
      {action && <div className="mt-4 flex justify-center">{action}</div>}
    </div>
  );
}
