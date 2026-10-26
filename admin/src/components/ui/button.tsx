import { cn } from '@/lib/utils';
import { ButtonProps } from '@primereact/types/primitive/button';
import { cva, VariantProps } from 'class-variance-authority';
import { Button as PRButton } from 'primereact/button';
import * as React from 'react';

const buttonVariants = cva(
    'inline-flex gap-2 cursor-pointer select-none items-center justify-center overflow-hidden relative rounded-md font-medium disabled:pointer-events-none disabled:opacity-60 transition-[background-color,color] duration-200 focus-visible:outline-1 focus-visible:outline-offset-2 focus-visible:outline-focus-ring',
    {
        variants: {
            variant: {
                default: '',
                link: 'hover:underline',
                text: 'focus-visible:outline-offset-0!',
                outlined: ''
            },
            severity: {
                default: '',
                secondary: '',
                info: '',
                success: '',
                warn: '',
                danger: '',
                contrast: '',
                help: ''
            },
            raised: {
                true: 'shadow-sm'
            },
            rounded: {
                true: 'rounded-full!'
            },
            iconOnly: {
                true: 'aspect-square px-0'
            },
            size: {
                small: "h-[30px] px-2.5 text-xs [&_svg:not([class*='size-'])]:size-3",
                normal: "min-h-11 px-3.5 text-[13px] sm:min-h-9 [&_svg:not([class*='size-'])]:size-3.5",
                large: "py-2 px-3 [&_svg:not([class*='size-'])]:size-4"
            }
        },
        defaultVariants: {
            variant: 'default',
            size: 'normal',
            severity: 'default',
            raised: false,
            rounded: false
        },
        compoundVariants: [
            {
                variant: 'default',
                severity: 'default',
                className: 'bg-cyan-600 hover:bg-cyan-700 active:bg-cyan-700 text-on-cyan'
            },
            {
                variant: 'default',
                severity: 'secondary',
                className: 'bg-subtle hover:bg-border active:bg-border-strong text-ink-muted hover:text-ink active:text-ink'
            },
            // Status severities are the design system's `-surface`/`-ink` badge
            // pairs (ADR-104 decision 3), not solid fills — the `-ink` token
            // flips to a light value in dark mode, so a solid `bg-*-ink` would
            // fail dark contrast. A border gives the soft fill a button edge,
            // and it intensifies on hover.
            {
                variant: 'default',
                severity: 'info',
                className: 'border border-info-ink/20 bg-info-surface text-info-ink hover:border-info-ink/50'
            },
            {
                variant: 'default',
                severity: 'success',
                className: 'border border-success-ink/20 bg-success-surface text-success-ink hover:border-success-ink/50'
            },
            {
                variant: 'default',
                severity: 'warn',
                className: 'border border-warning-ink/20 bg-warning-surface text-warning-ink hover:border-warning-ink/50'
            },
            {
                variant: 'default',
                severity: 'danger',
                className: 'border border-danger-ink/20 bg-danger-surface text-danger-ink hover:border-danger-ink/50'
            },
            {
                variant: 'default',
                severity: 'help',
                className: 'border border-purple-600/20 bg-purple-50 text-purple-ink hover:border-purple-600/50'
            },
            {
                variant: 'default',
                severity: 'contrast',
                className: 'bg-ink hover:bg-ink/90 active:bg-ink/80 text-surface'
            },
            {
                variant: 'outlined',
                severity: 'default',
                className: 'border border-cyan-600/40 hover:bg-cyan-50 active:bg-cyan-50 text-cyan-ink'
            },
            {
                variant: 'outlined',
                severity: 'secondary',
                className: 'border border-border hover:bg-subtle active:bg-border text-ink-muted'
            },
            {
                variant: 'outlined',
                severity: 'info',
                className: 'border border-info-ink/30 hover:bg-info-surface active:bg-info-surface text-info-ink'
            },
            {
                variant: 'outlined',
                severity: 'success',
                className: 'border border-success-ink/30 hover:bg-success-surface active:bg-success-surface text-success-ink'
            },
            {
                variant: 'outlined',
                severity: 'warn',
                className: 'border border-warning-ink/30 hover:bg-warning-surface active:bg-warning-surface text-warning-ink'
            },
            {
                variant: 'outlined',
                severity: 'danger',
                className: 'border border-danger-ink/30 hover:bg-danger-surface active:bg-danger-surface text-danger-ink'
            },
            {
                variant: 'outlined',
                severity: 'help',
                className: 'border border-purple-600/30 hover:bg-purple-50 active:bg-purple-50 text-purple-ink'
            },
            {
                variant: 'outlined',
                severity: 'contrast',
                className: 'border border-border-strong hover:bg-subtle active:bg-border text-ink'
            },
            {
                variant: 'text',
                severity: 'default',
                className: 'hover:bg-cyan-50 active:bg-cyan-50 text-cyan-ink'
            },
            {
                variant: 'text',
                severity: 'secondary',
                className: 'hover:bg-subtle active:bg-border text-ink-muted'
            },
            {
                variant: 'text',
                severity: 'info',
                className: 'text-info-ink hover:bg-info-surface active:bg-info-surface'
            },
            {
                variant: 'text',
                severity: 'success',
                className: 'text-success-ink hover:bg-success-surface active:bg-success-surface'
            },
            {
                variant: 'text',
                severity: 'warn',
                className: 'text-warning-ink hover:bg-warning-surface active:bg-warning-surface'
            },
            {
                variant: 'text',
                severity: 'danger',
                className: 'text-danger-ink hover:bg-danger-surface active:bg-danger-surface'
            },
            {
                variant: 'text',
                severity: 'help',
                className: 'text-purple-ink hover:bg-purple-50 active:bg-purple-50'
            },
            {
                variant: 'text',
                severity: 'contrast',
                className: 'hover:bg-subtle active:bg-border text-ink'
            }
        ]
    }
);

function Button({ className, variant, size = 'normal', severity, raised, rounded, iconOnly, ...props }: ButtonProps & VariantProps<typeof buttonVariants>) {
    return <PRButton variant={variant} size={size} severity={severity} raised={raised} rounded={rounded} iconOnly={iconOnly} className={cn(buttonVariants({ variant, size, severity, raised, rounded, iconOnly, className }))} {...props} />;
}

export { Button, buttonVariants };
