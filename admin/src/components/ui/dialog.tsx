'use client';

import { cn } from '@/lib/utils';
import {
    DialogBackdropProps,
    DialogCloseProps,
    DialogContentProps,
    DialogFooterProps,
    DialogHeaderActionsProps,
    DialogHeaderProps,
    DialogMaximizableProps,
    DialogPopupProps,
    DialogPortalProps,
    DialogPositionerProps,
    DialogRootProps,
    DialogTitleProps,
    DialogTriggerProps,
    Dialog as PRDialog
} from 'primereact/dialog';
import * as React from 'react';

function Dialog({ ...props }: DialogRootProps) {
    return <PRDialog.Root {...props} />;
}

function DialogTrigger({ ...props }: DialogTriggerProps) {
    return <PRDialog.Trigger {...props} />;
}

function DialogClose({ ...props }: DialogCloseProps) {
    return <PRDialog.Close {...props} />;
}

function DialogMaximizable({ ...props }: DialogMaximizableProps) {
    return <PRDialog.Maximizable {...props} />;
}

function DialogBackdrop({ className, ...props }: DialogBackdropProps) {
    return <PRDialog.Backdrop className={cn('fixed z-50 inset-0 bg-black/50 opacity-100 data-enter-from:opacity-0 data-leave-to:opacity-0 transition-opacity duration-150 ease-out', className)} {...props} />;
}

function DialogPortal({ ...props }: DialogPortalProps) {
    return <PRDialog.Portal {...props} />;
}

function DialogPositioner({ className, ...props }: DialogPositionerProps) {
    return (
        <PRDialog.Positioner
            className={cn(
                `fixed inset-0 z-50 flex items-center justify-center p-8 pointer-events-none
        data-modal:pointer-events-auto data-maximized:p-0
        data-[scroll-behavior=outside]:overflow-auto data-[scroll-behavior=outside]:items-start
        data-[position=top]:items-start
        data-[position=bottom]:items-end
        data-[position=left]:justify-start
        data-[position=right]:justify-end
        data-[position=topleft]:items-start data-[position=topleft]:justify-start
        data-[position=topright]:items-start data-[position=topright]:justify-end
        data-[position=bottomleft]:items-end data-[position=bottomleft]:justify-start
        data-[position=bottomright]:items-end data-[position=bottomright]:justify-end`,
                className
            )}
            {...props}
        />
    );
}

function DialogPopup({ className, ...props }: DialogPopupProps) {
    return (
        <PRDialog.Popup
            className={cn(
                `relative flex flex-col max-h-full pointer-events-auto rounded-xl border border-surface-200 dark:border-surface-700
        bg-surface-0 dark:bg-surface-900 text-surface-700 dark:text-surface-0 shadow-lg
        [translate:var(--px-drag-x)_var(--px-drag-y)]
        data-maximized:w-screen! data-maximized:h-screen data-maximized:max-h-full data-maximized:rounded-none data-maximized:translate-none
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

function DialogContent({ className, ...props }: DialogContentProps) {
    return <PRDialog.Content className={cn('overflow-y-auto pt-0 px-4.5 pb-4.5 data-maximized:grow', className)} {...props} />;
}

function DialogHeader({ className, ...props }: DialogHeaderProps) {
    return <PRDialog.Header className={cn('flex items-center justify-between shrink-0 p-4.5', className)} {...props} />;
}

function DialogHeaderActions({ className, ...props }: DialogHeaderActionsProps) {
    return <PRDialog.HeaderActions className={cn('flex items-center gap-2', className)} {...props} />;
}

function DialogTitle({ className, ...props }: DialogTitleProps) {
    return <PRDialog.Title className={cn('font-semibold text-lg', className)} {...props} />;
}

function DialogFooter({ className, ...props }: DialogFooterProps) {
    return <PRDialog.Footer className={cn('shrink-0 pt-0 px-4.5 pb-4.5 flex justify-end gap-1.5', className)} {...props} />;
}

export { Dialog, DialogBackdrop, DialogClose, DialogContent, DialogFooter, DialogHeader, DialogHeaderActions, DialogMaximizable, DialogPopup, DialogPortal, DialogPositioner, DialogTitle, DialogTrigger };
