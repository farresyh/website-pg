'use client';
import { cn } from '@/lib/utils';
import type { TagProps } from '@primereact/types/primitive/tag';
import { cva, VariantProps } from 'class-variance-authority';
import { Tag as PRTag } from 'primereact/tag';
import * as React from 'react';

const tagVariants = cva('inline-flex items-center justify-center gap-1 font-bold text-xs leading-normal px-1.5 py-0.5 rounded-md [&>svg]:size-3 [&>i]:text-xs', {
    variants: {
        severity: {
            default: 'bg-primary-100 dark:bg-primary-500/15 text-primary-700 dark:text-primary-300',
            secondary: 'bg-surface-100 dark:bg-surface-800 text-surface-600 dark:text-surface-300',
            info: 'bg-sky-100 dark:bg-sky-500/15 text-sky-700 dark:text-sky-300',
            success: 'bg-green-100 dark:bg-green-500/15 text-green-700 dark:text-green-300',
            warn: 'bg-orange-100 dark:bg-orange-500/15 text-orange-700 dark:text-orange-300',
            danger: 'bg-red-100 dark:bg-red-500/15 text-red-700 dark:text-red-300',
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

function Tag({ className, severity, rounded, ...props }: TagProps & VariantProps<typeof tagVariants>) {
    return <PRTag severity={severity} rounded={rounded} className={cn('leading-', tagVariants({ severity, rounded, className }))} {...props} />;
}

export { Tag, tagVariants };
