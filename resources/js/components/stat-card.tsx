import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

type StatCardProps = {
    /** Short metric name, e.g. "Stars earned". */
    label: string;
    /** Metric value; rendered large. Ignored while `loading`. */
    value: ReactNode;
    icon?: LucideIcon;
    /** Extra context rendered under the value. */
    description?: string;
    /** Keeps layout stable while the metric loads (deferred props). */
    loading?: boolean;
    className?: string;
};

export function StatCard({
    label,
    value,
    icon: Icon,
    description,
    loading = false,
    className,
}: StatCardProps) {
    return (
        <Card className={className}>
            <CardHeader className="flex-row items-center gap-3 space-y-0">
                {Icon && (
                    <Icon
                        aria-hidden="true"
                        className="size-5 shrink-0 text-muted-foreground"
                    />
                )}
                <CardTitle className="text-base">{label}</CardTitle>
            </CardHeader>
            <CardContent>
                {loading ? (
                    <Skeleton className="h-8 w-16" />
                ) : (
                    <div className="text-2xl font-semibold">{value}</div>
                )}
                {description && (
                    <p className="mt-1 text-xs text-muted-foreground">
                        {description}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}
