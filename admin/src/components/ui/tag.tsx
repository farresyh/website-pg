'use client';
import { cn } from '@/lib/utils';
import type { TagProps } from '@primereact/types/primitive/tag';
import { cva, VariantProps } from 'class-variance-authority';
import { Tag as PRTag } from 'primereact/tag';
import * as React from 'react';

/**
 * ADR-104 decision 2/3 — severities read from the artifact's own status
 * token pairs (`-surface`/`-ink`), not PrimeReact's/Tailwind's stock
 * hues. Each pair already carries its own `.dark` override in
 * globals.css, so no `dark:` variant is needed here at all. `review` is
 * a new severity (not one PrimeReact's own `TagProps.severity` type
 * knows) for the `needs_review` family of statuses specifically —
 * distinct from `warn`, per the artifact's own token set.
 */
const tagVariants = cva('inline-flex items-center justify-center gap-1 font-bold text-xs leading-normal px-1.5 py-0.5 rounded-md [&>svg]:size-3 [&>i]:text-xs', {
    variants: {
        severity: {
            default: 'bg-cyan-50 text-cyan-ink',
            secondary: 'bg-neutral-surface text-neutral-ink',
            info: 'bg-info-surface text-info-ink',
            success: 'bg-success-surface text-success-ink',
            warn: 'bg-warning-surface text-warning-ink',
            danger: 'bg-danger-surface text-danger-ink',
            review: 'bg-review-surface text-review-ink',
            contrast: 'bg-surface-950 dark:bg-surface-0 text-surface-0 dark:text-surface-950'
        },
        rounded: {
            true: 'rounded-full'
        }
    },
    defaultVariants: {
        severity: 'default'
    }
});

// PrimeReact's own Tag doesn't know "review" (or our "default") — every
// class comes from tagVariants() above regardless, so PRTag's severity
// prop only needs to stay within its own type for the values it applies
// its own built-in styling to; anything else passes undefined through.
const PRIMEREACT_SEVERITIES = new Set(['secondary', 'success', 'info', 'warn', 'danger', 'contrast']);

function Tag({
    className,
    severity,
    rounded,
    dot,
    children,
    ...props
}: Omit<TagProps, 'severity'> & VariantProps<typeof tagVariants> & { dot?: boolean }) {
    const prSeverity = severity && PRIMEREACT_SEVERITIES.has(severity) ? (severity as TagProps['severity']) : undefined;
    return (
        <PRTag severity={prSeverity} rounded={rounded ?? undefined} className={cn('leading-', tagVariants({ severity, rounded, className }))} {...props}>
            {dot && <span aria-hidden="true" className="inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-current" />}
            {children}
        </PRTag>
    );
}

export { Tag, tagVariants };
