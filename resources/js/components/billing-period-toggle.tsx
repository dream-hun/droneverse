import { cn } from '@/lib/utils';
import type { PlanVariant } from '@/types/billing';

const OPTIONS: { value: PlanVariant; label: string }[] = [
    { value: 'monthly', label: 'Monthly' },
    { value: 'yearly', label: 'Yearly' },
    { value: 'lifetime', label: 'Lifetime' },
];

const SUFFIXES: Record<PlanVariant, string> = {
    monthly: ' / month',
    yearly: ' / year',
    lifetime: ' once',
};

/** What follows a price quoted for `variant`: "$19 / month", "$350 once". */
export function periodSuffix(variant: PlanVariant): string {
    return SUFFIXES[variant];
}

/**
 * The monthly / yearly / lifetime switch every upgrade button is priced under.
 *
 * Shared by the pricing page and billing settings, so the two offer the same
 * choice in the same words. `saving` is the yearly discount as a whole
 * percentage, shown beside the yearly option when there is one.
 */
export function BillingPeriodToggle({
    variant,
    onChange,
    saving,
    className,
}: {
    variant: PlanVariant;
    onChange: (variant: PlanVariant) => void;
    saving: number | null;
    className?: string;
}) {
    return (
        <div
            role="group"
            aria-label="Billing period"
            className={cn('inline-flex border border-border', className)}
        >
            {OPTIONS.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    aria-pressed={variant === option.value}
                    onClick={() => onChange(option.value)}
                    className={cn(
                        'px-5 py-2.5 font-mono text-xs tracking-widest uppercase transition-colors',
                        variant === option.value
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:text-primary',
                    )}
                >
                    {option.label}
                    {option.value === 'yearly' && saving !== null && (
                        <span
                            className={cn(
                                'ml-2',
                                variant === 'yearly'
                                    ? 'text-primary-foreground/70'
                                    : 'text-primary',
                            )}
                        >
                            save {saving}%
                        </span>
                    )}
                </button>
            ))}
        </div>
    );
}
