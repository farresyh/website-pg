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
                small: "py-1 px-2 text-xs [&_svg:not([class*='size-'])]:size-3",
                normal: "py-1.5 px-2.5 text-sm [&_svg:not([class*='size-'])]:size-3.5",
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
            // Deferred (PRD §16): the info/success/warn/danger/help severities
            // still use Tailwind's stock blue/green/amber/red/violet, not the
            // design system's status tokens. The `-ink` tokens flip to a light
            // value in dark mode, so a solid `bg-*-ink text-white` fails dark
            // contrast; the design system defines status only as a
            // `-surface`/`-ink` badge pair. Mapping them needs a founder
            // decision (solid vs badge) — ADR-104 decision 7 (visual change).
            {
                variant: 'default',
                severity: 'info',
                className: 'bg-blue-500 text-white hover:bg-blue-600 active:bg-blue-700'
            },
            {
                variant: 'default',
                severity: 'success',
                className: 'bg-green-500 text-white hover:bg-green-600 active:bg-green-700'
            },
            {
                variant: 'default',
                severity: 'warn',
                className: 'bg-amber-500 text-white hover:bg-amber-600 active:bg-amber-700'
            },
            {
                variant: 'default',
                severity: 'danger',
                className: 'bg-red-500 text-white hover:bg-red-600 active:bg-red-700'
            },
            {
                variant: 'default',
                severity: 'help',
                className: 'bg-violet-500 text-white hover:bg-violet-600 active:bg-violet-700'
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
            // Deferred — see the note above.
            {
                variant: 'outlined',
                severity: 'info',
                className: 'border border-blue-200 dark:border-blue-700 hover:bg-blue-500/10 active:bg-blue-500/20 text-blue-500'
            },
            {
                variant: 'outlined',
                severity: 'success',
                className: 'border border-green-200 dark:border-green-700 hover:bg-green-500/10 active:bg-green-500/20 text-green-500'
            },
            {
                variant: 'outlined',
                severity: 'warn',
                className: 'border border-amber-200 dark:border-amber-700 hover:bg-amber-500/10 active:bg-amber-500/20 text-amber-500'
            },
            {
                variant: 'outlined',
                severity: 'danger',
                className: 'border border-red-200 dark:border-red-700 hover:bg-red-500/10 active:bg-red-500/20 text-red-500'
            },
            {
                variant: 'outlined',
                severity: 'help',
                className: 'border border-violet-200 dark:border-violet-700 hover:bg-violet-500/10 active:bg-violet-500/20 text-violet-500'
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
            // Deferred — see the note above.
            {
                variant: 'text',
                severity: 'info',
                className: 'text-blue-500 hover:bg-blue-500/10 active:bg-blue-500/20'
            },
            {
                variant: 'text',
                severity: 'success',
                className: 'text-green-500 hover:bg-green-500/10 active:bg-green-500/20'
            },
            {
                variant: 'text',
                severity: 'warn',
                className: 'text-amber-500 hover:bg-amber-500/10 active:bg-amber-500/20'
            },
            {
                variant: 'text',
                severity: 'danger',
                className: 'text-red-500 hover:bg-red-500/10 active:bg-red-500/20'
            },
            {
                variant: 'text',
                severity: 'help',
                className: 'text-violet-500 hover:bg-violet-500/10 active:bg-violet-500/20'
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
