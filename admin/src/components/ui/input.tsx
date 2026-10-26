'use client';

import { cn } from '@/lib/utils';
import { cva } from 'class-variance-authority';
import type { InputTextProps } from '@primereact/types/primitive/inputtext';
import { InputText as PRInputText } from 'primereact/inputtext';
import * as React from 'react';

const inputVariants = cva(
    'h-11 w-full rounded-md border appearance-none px-4 py-2.5 text-sm placeholder:text-ink-subtle focus:outline-2 focus:outline-offset-[-1px] focus:outline-focus-ring',
    {
        variants: {
            disabled: { true: '', false: '' },
            error: { true: '', false: '' }
        },
        compoundVariants: [
            { disabled: true, className: 'text-ink-subtle border-border cursor-not-allowed opacity-45' },
            { disabled: false, error: true, className: 'text-danger-ink border-danger-ink' },
            { disabled: false, error: false, className: 'bg-surface text-ink border-border-control' }
        ],
        defaultVariants: { disabled: false, error: false }
    }
);

interface InputProps extends Omit<InputTextProps, 'size' | 'invalid' | 'onChange'> {
    // Redeclared explicitly: InputTextProps derives this from a polymorphic
    // `ExtractProps<T>` (T defaults to the broad `React.ElementType`), which
    // resolves loosely enough that consumers see an implicit-`any` event
    // parameter. Pinning it to the real native input event, same signature
    // the old `components/form/input/InputField` (ADR-038) already had.
    onChange?: React.ChangeEventHandler<HTMLInputElement>;
    error?: boolean;
    hint?: string;
}

/** Same flat prop surface as the old `components/form/input/InputField` it replaces (ADR-038) — most call sites only need the import swapped. */
function Input({ className, disabled, error, hint, ...props }: InputProps) {
    return (
        <div>
            <PRInputText
                invalid={error}
                disabled={disabled}
                className={cn(inputVariants({ disabled: Boolean(disabled), error: Boolean(error) }), className)}
                {...props}
            />
            {hint && <p className={cn('mt-1.5 text-xs', error ? 'text-danger-ink' : 'text-ink-subtle')}>{hint}</p>}
        </div>
    );
}

export { Input, inputVariants };
