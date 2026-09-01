'use client';

import { cn } from '@/lib/utils';
import type { LabelProps } from '@primereact/types/primitive/label';
import { Label as PRLabel } from 'primereact/label';
import * as React from 'react';

function Label({ className, ...props }: LabelProps) {
    return <PRLabel className={cn('mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400', className)} {...props} />;
}

export { Label };
