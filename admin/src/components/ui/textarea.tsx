'use client';

import { cn } from '@/lib/utils';
import { inputVariants } from './input';
import * as React from 'react';

interface TextareaProps extends React.TextareaHTMLAttributes<HTMLTextAreaElement> {
    error?: boolean;
}

/**
 * The same look as `Input`, for a multi-line `<textarea>` — the one form
 * primitive PrimeReact's primitive line doesn't wrap, so it is a native
 * element styled with `inputVariants` (ADR-128 shared kit).
 */
function Textarea({ className, disabled, error, ...props }: TextareaProps) {
    return <textarea disabled={disabled} className={cn(inputVariants({ disabled: Boolean(disabled), error: Boolean(error) }), 'min-h-[100px] resize-y py-2.5', className)} {...props} />;
}

export { Textarea };
