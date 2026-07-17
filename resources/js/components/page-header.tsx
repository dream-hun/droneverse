import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type PageHeaderProps = {
    title: string;
    description?: string;
    /** Rendered beside the title, e.g. a difficulty Badge. */
    badge?: ReactNode;
    /** Right-aligned actions; stack below the text on small screens. */
    actions?: ReactNode;
    className?: string;
};

export function PageHeader({
    title,
    description,
    badge,
    actions,
    className,
}: PageHeaderProps) {
    return (
        <header
            className={cn(
                'flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between',
                className,
            )}
        >
            <div className="space-y-1">
                <div className="flex flex-wrap items-center gap-2">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {title}
                    </h1>
                    {badge}
                </div>
                {description && (
                    <p className="max-w-2xl text-sm text-pretty text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {actions && (
                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    {actions}
                </div>
            )}
        </header>
    );
}
