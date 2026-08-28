'use client';

import { cn } from '@/lib/utils';
import { cva } from 'class-variance-authority';
import type { InputTextProps } from '@primereact/types/primitive/inputtext';
import { InputText as PRInputText } from 'primereact/inputtext';
import * as React from 'react';

const inputVariants = cva(
    'h-11 w-full rounded-lg border appearance-none px-4 py-2.5 text-sm shadow-theme-xs placeholder:text-gray-400 focus:outline-hidden focus:ring-3 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800',
    {
        variants: {
            disabled: { true: '', false: '' },
            error: { true: '', false: '' }
        },
        compoundVariants: [
            { disabled: true, className: 'text-gray-500 border-gray-300 cursor-not-allowed dark:bg-gray-800 dark:text-gray-400 dark:border-gray-700' },
            { disabled: false, error: true, className: 'text-error-800 border-error-500 focus:ring-error-500/10 dark:text-error-400 dark:border-error-500' },
            { disabled: false, error: false, className: 'bg-transparent text-gray-800 border-gray-300 focus:border-brand-300 focus:ring-brand-500/10 dark:border-gray-700' }
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
            {hint && <p className={cn('mt-1.5 text-xs', error ? 'text-error-500' : 'text-gray-500')}>{hint}</p>}
        </div>
    );
}

export { Input, inputVariants };
