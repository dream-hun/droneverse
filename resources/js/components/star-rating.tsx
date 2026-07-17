import { Star } from 'lucide-react';
import { cn } from '@/lib/utils';

const SIZE_CLASSES = {
    sm: 'size-3.5',
    default: 'size-4',
    lg: 'size-6',
} as const;

type StarRatingProps = {
    /** Stars earned. Rounded and clamped to [0, max]. */
    value: number;
    /** Total achievable stars. */
    max?: number;
    size?: keyof typeof SIZE_CLASSES;
    /** Overrides the generated "x of y stars" label. */
    label?: string;
    className?: string;
};

export function StarRating({
    value,
    max = 3,
    size = 'default',
    label,
    className,
}: StarRatingProps) {
    const safeMax = Number.isFinite(max) ? Math.max(1, Math.floor(max)) : 3;
    const earned = Number.isFinite(value)
        ? Math.min(Math.max(Math.round(value), 0), safeMax)
        : 0;

    return (
        <span
            role="img"
            aria-label={label ?? `${earned} of ${safeMax} stars`}
            className={cn('inline-flex items-center gap-0.5', className)}
        >
            {Array.from({ length: safeMax }, (_, index) => (
                <Star
                    key={index}
                    aria-hidden="true"
                    className={cn(
                        SIZE_CLASSES[size],
                        index < earned
                            ? 'fill-amber-400 text-amber-400'
                            : 'text-muted-foreground/40',
                    )}
                />
            ))}
        </span>
    );
}
