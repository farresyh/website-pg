'use client';

import { cn } from '@/lib/utils';
import {
    TabsListProps,
    TabsPanelProps,
    TabsPanelsProps,
    TabsRootProps,
    TabsTabProps,
    Tabs as PRTabs
} from 'primereact/tabs';
import * as React from 'react';

function Tabs({ ...props }: TabsRootProps) {
    return <PRTabs.Root {...props} />;
}

function TabsList({ className, ...props }: TabsListProps) {
    return (
        <PRTabs.List
            className={cn('flex items-center gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800', className)}
            {...props}
        />
    );
}

function TabsTab({ className, ...props }: TabsTabProps) {
    return (
        <PRTabs.Tab
            className={cn(
                'relative shrink-0 whitespace-nowrap px-3 py-2.5 text-theme-sm font-medium text-gray-500 transition-colors hover:text-gray-800 data-active:text-brand-600 dark:text-gray-400 dark:hover:text-white/90 dark:data-active:text-brand-400',
                className
            )}
            {...props}
            type="button"
        />
    );
}

function TabsIndicator({ className }: { className?: string }) {
    return (
        <PRTabs.Indicator
            className={cn(
                '-mb-px h-0.5 rounded-full bg-brand-500 transition-[width,transform] duration-200 dark:bg-brand-400',
                className
            )}
        />
    );
}

function TabsPanels({ className, ...props }: TabsPanelsProps) {
    return <PRTabs.Panels className={cn('pt-5', className)} {...props} />;
}

function TabsPanel({ className, ...props }: TabsPanelProps) {
    return <PRTabs.Panel className={cn('outline-none', className)} {...props} />;
}

export { Tabs, TabsIndicator, TabsList, TabsPanel, TabsPanels, TabsTab };
