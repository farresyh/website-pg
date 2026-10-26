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
            className={cn('flex items-center gap-1 overflow-x-auto border-b border-border', className)}
            {...props}
        />
    );
}

function TabsTab({ className, ...props }: TabsTabProps) {
    return (
        <PRTabs.Tab
            className={cn(
                'relative shrink-0 whitespace-nowrap px-3 py-2.5 text-theme-sm font-medium text-ink-muted transition-colors hover:text-ink data-active:text-cyan-ink',
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
                '-mb-px h-0.5 rounded-full bg-cyan-600 transition-[width,transform] duration-200',
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
