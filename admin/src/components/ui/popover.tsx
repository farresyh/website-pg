'use client';

import { cn } from '@/lib/utils';
import {
    PopoverCloseProps,
    PopoverContentProps,
    PopoverFooterProps,
    PopoverHeaderProps,
    PopoverPopupProps,
    PopoverPortalProps,
    PopoverPositionerProps,
    PopoverRootProps,
    PopoverTitleProps,
    PopoverTriggerProps,
    Popover as PRPopover
} from 'primereact/popover';
import * as React from 'react';

function Popover({ ...props }: PopoverRootProps) {
    return <PRPopover.Root {...props} />;
}

function PopoverTrigger({ ...props }: PopoverTriggerProps) {
    return <PRPopover.Trigger {...props} />;
}

function PopoverClose({ ...props }: PopoverCloseProps) {
    return <PRPopover.Close {...props} />;
}

function PopoverPortal({ ...props }: PopoverPortalProps) {
    return <PRPopover.Portal {...props} />;
}

function PopoverPositioner({ className, ...props }: PopoverPositionerProps) {
    return <PRPopover.Positioner className={cn('z-50', className)} {...props} />;
}

function PopoverPopup({ className, ...props }: PopoverPopupProps) {
    return (
        <PRPopover.Popup
            className={cn(
                `relative flex flex-col pointer-events-auto rounded-xl border border-surface-200 dark:border-surface-700
        bg-surface-0 dark:bg-surface-900 text-surface-700 dark:text-surface-0 shadow-lg
        opacity-100 scale-100
        data-enter-from:opacity-0 data-enter-from:scale-[0.93]
        data-leave-to:opacity-0 data-leave-to:scale-[0.93]
        transition-[opacity,scale] duration-150 ease-out`,
                className
            )}
            {...props}
        />
    );
}

function PopoverHeader({ className, ...props }: PopoverHeaderProps) {
    return <PRPopover.Header className={cn('shrink-0 px-3 pb-3 pt-1', className)} {...props} />;
}

function PopoverTitle({ className, ...props }: PopoverTitleProps) {
    return <PRPopover.Title className={cn('font-semibold text-sm', className)} {...props} />;
}

function PopoverContent({ className, ...props }: PopoverContentProps) {
    return <PRPopover.Content className={cn('p-1', className)} {...props} />;
}

function PopoverFooter({ className, ...props }: PopoverFooterProps) {
    return <PRPopover.Footer className={cn('shrink-0 p-1', className)} {...props} />;
}

export { Popover, PopoverClose, PopoverContent, PopoverFooter, PopoverHeader, PopoverPopup, PopoverPortal, PopoverPositioner, PopoverTitle, PopoverTrigger };
