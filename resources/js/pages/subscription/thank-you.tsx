import { Head, Link, usePoll } from '@inertiajs/react';
import { Check, Clock, LoaderCircle } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Wordmark } from '@/components/marketing/site-header';
import { cn } from '@/lib/utils';
import { dashboard, home } from '@/routes';
import { edit as editBilling } from '@/routes/billing';
import { index as coursesIndex } from '@/routes/courses';
import type { PlanValue } from '@/types/auth';
import type { PlanVariant, SubscriptionConfirmation } from '@/types/billing';

type Props = {
    plan: { value: PlanValue; label: string; isPaid: boolean };
    subscription: SubscriptionConfirmation | null;
    highlights: string[];
    /**
     * Paid, and not granted yet. The page polls on this and on nothing else —
     * see App\Actions\BuildSubscriptionConfirmation for why it is the server's
     * answer rather than a flag the checkout handler passes along.
     */
    pending: boolean;
};

/**
 * What the page is saying, in the order a buyer meets them: waiting on the
 * webhook, waiting on it for longer than the poll will, and done.
 */
type ConfirmationState = 'waiting' | 'stalled' | 'confirmed';

const VARIANT_LABELS: Record<PlanVariant, string> = {
    monthly: 'Billed monthly',
    yearly: 'Billed yearly',
};

/**
 * The billing period, named, or nothing.
 *
 * The server sends whatever period the subscription's variant maps to, which
 * is a string rather than one of ours: a subscription sold on a launch variant
 * or on a period we no longer list resolves to a name this page has no words
 * for. Better to leave the row out than to print a raw config key at somebody
 * who has just paid.
 */
function variantLabel(variant: string | null | undefined): string | null {
    return variant !== null &&
        variant !== undefined &&
        variant in VARIANT_LABELS
        ? VARIANT_LABELS[variant as PlanVariant]
        : null;
}

const DATE_FORMAT: Intl.DateTimeFormatOptions = {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
};

function formatDate(value: string | null): string | null {
    return value
        ? new Date(value).toLocaleDateString(undefined, DATE_FORMAT)
        : null;
}

/** How often the page asks whether the webhook has landed, and for how long. */
const POLL_INTERVAL = 2500;
const POLL_LIMIT = 24;

const PRIMARY_ACTION =
    'bg-primary px-6 py-4 text-center font-bold tracking-widest text-primary-foreground uppercase transition-all hover:brightness-110';

const SECONDARY_ACTION =
    'border border-border px-6 py-4 text-center font-mono text-xs tracking-widest text-foreground uppercase transition-colors hover:border-primary hover:text-primary';

/**
 * Watch for the entitlement the buyer has already paid for.
 *
 * Polling rather than pushing, because the event this page is waiting for
 * arrives at the server from Creem and there is no channel between the two. It stops the moment the plan lands and gives up after POLL_LIMIT ticks:
 * a webhook that has not arrived in a minute is not going to be waited out by
 * a browser, and a spinner that never resolves is a worse answer than a page
 * that admits it is still waiting and says where to look.
 *
 * `only` keeps each tick to the props that can change. The plan is one of them
 * — it is what the whole page is waiting on — and `auth` travels with it so the
 * shared plan badge the rest of the app reads does not go stale behind this
 * page.
 */
function useEntitlementWatch(pending: boolean) {
    const [gaveUp, setGaveUp] = useState(false);
    const ticks = useRef(0);

    const { start, stop } = usePoll(
        POLL_INTERVAL,
        {
            only: ['plan', 'subscription', 'highlights', 'pending', 'auth'],
            onFinish: () => {
                ticks.current += 1;

                if (ticks.current >= POLL_LIMIT) {
                    setGaveUp(true);
                }
            },
        },
        { autoStart: false },
    );

    useEffect(() => {
        if (!pending || gaveUp) {
            stop();

            return;
        }

        start();

        return stop;
    }, [pending, gaveUp, start, stop]);

    return gaveUp;
}

/** One labelled fact in the receipt block. */
function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div className="border border-border p-5">
            <dt className="font-mono text-[10px] tracking-widest text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className="mt-2 text-sm font-bold tracking-tight text-foreground">
                {value}
            </dd>
        </div>
    );
}

/**
 * The confirmation a buyer lands on the moment Creem reports the checkout done.
 *
 * It wears neither the app shell nor the auth layout, and that is the point:
 * this is the end of a purchase rather than a screen in the product, so it
 * carries the brand mark, the confirmation and two ways onward and nothing
 * else. No sidebar to wander into, no marketing nav offering the plan they
 * have just bought.
 *
 * Which means it draws its own chrome, so it is named in `bringsOwnChrome()`
 * in resources/js/lib/page-chrome.ts and in the `$alwaysDark` list in
 * resources/views/app.blade.php — `.theme-droneverse` has no light variant, and
 * a page missing from the second one is white until React boots. It is the one
 * signed-in page on both lists.
 *
 * There are three states, and the first two both begin with a buyer who has
 * paid. `waiting` is the gap between the money leaving and the webhook
 * landing, which is where most buyers arrive. `stalled` is that gap outlasting
 * the poll. Neither may claim a plan, because no plan is known yet — and
 * neither may imply the payment did not go through, because it did. Only
 * `confirmed` names a tier, and only once there is a subscription row saying
 * so.
 */
export default function ThankYou({
    plan,
    subscription,
    highlights,
    pending,
}: Props) {
    const gaveUp = useEntitlementWatch(pending);
    const state: ConfirmationState = pending
        ? gaveUp
            ? 'stalled'
            : 'waiting'
        : 'confirmed';

    const renewsAt = formatDate(subscription?.renewsAt ?? null);
    const trialEndsAt = formatDate(subscription?.trialEndsAt ?? null);
    const billing = variantLabel(subscription?.variant);

    return (
        <div className="theme-droneverse dark flex min-h-screen flex-col bg-background font-sans text-foreground selection:bg-primary selection:text-primary-foreground">
            <Head
                title={
                    state === 'confirmed' ? 'Thank you' : 'Activating your plan'
                }
            />

            <header className="border-b border-border">
                <div className="mx-auto flex h-16 max-w-3xl items-center px-6">
                    <Link href={home()} aria-label="DroneVerse home">
                        <Wordmark />
                    </Link>
                </div>
            </header>

            <main className="flex flex-1 items-center justify-center px-6 py-16">
                <div className="w-full max-w-3xl">
                    <div
                        aria-hidden
                        className={cn(
                            'flex size-14 items-center justify-center',
                            state === 'confirmed'
                                ? 'bg-primary text-primary-foreground'
                                : 'border border-border text-primary',
                        )}
                    >
                        {state === 'waiting' && (
                            <LoaderCircle className="size-6 animate-spin" />
                        )}
                        {state === 'stalled' && <Clock className="size-6" />}
                        {state === 'confirmed' && (
                            <Check className="size-7" strokeWidth={3} />
                        )}
                    </div>

                    {/*
                     * The eyebrow leads on the payment rather than on the plan,
                     * because the payment is the part that is certain in all
                     * three states. Somebody who has just been charged should
                     * never read a page that sounds unsure about it.
                     */}
                    <p
                        className="mt-8 font-mono text-xs tracking-widest text-primary uppercase"
                        role="status"
                    >
                        {state === 'confirmed'
                            ? 'Subscription active'
                            : 'Payment received'}
                    </p>

                    <h1 className="mt-4 text-4xl font-bold tracking-tighter text-balance text-foreground uppercase sm:text-5xl">
                        {state === 'confirmed' &&
                            `Welcome to ${subscription?.planLabel ?? plan.label}`}
                        {state === 'stalled' && 'Still activating your plan'}
                        {state === 'waiting' && 'Activating your plan'}
                    </h1>

                    <p className="mt-6 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                        {state === 'waiting' && (
                            <>
                                Your payment went through and we are waiting on
                                the confirmation from our payment provider. This
                                usually takes a few seconds — this page updates
                                itself, so there is nothing to reload.
                            </>
                        )}

                        {state === 'stalled' && (
                            <>
                                Your payment went through, but the confirmation
                                from our payment provider has not reached us
                                yet. Nothing is lost: your plan is applied to
                                your account the moment it arrives, and your
                                billing settings will show it as soon as it
                                does.
                            </>
                        )}

                        {state === 'confirmed' && (
                            <>
                                Thank you — your subscription is live and every
                                mission it covers is unlocked on this account
                                right now. A receipt is on its way to your
                                inbox.
                            </>
                        )}
                    </p>

                    {state === 'confirmed' && subscription !== null && (
                        <dl className="mt-12 grid gap-px sm:grid-cols-2 lg:grid-cols-3">
                            <Detail
                                label="Plan"
                                value={subscription.planLabel ?? plan.label}
                            />

                            {billing !== null && (
                                <Detail label="Billing" value={billing} />
                            )}

                            {subscription.onTrial && trialEndsAt !== null && (
                                <Detail
                                    label="Trial ends"
                                    value={trialEndsAt}
                                />
                            )}

                            {/*
                             * No "paid with" line: Creem publishes no card
                             * brand or last four on any payload, so the card
                             * a buyer just used is something only the billing
                             * portal can show them.
                             */}
                            {renewsAt !== null && (
                                <Detail label="Renews" value={renewsAt} />
                            )}
                        </dl>
                    )}

                    {highlights.length > 0 && (
                        <section className="mt-12">
                            <h2 className="font-mono text-xs tracking-widest text-primary uppercase">
                                What you just unlocked
                            </h2>

                            <ul className="mt-6 grid gap-3 sm:grid-cols-2">
                                {highlights.map((highlight) => (
                                    <li
                                        key={highlight}
                                        className="flex items-start gap-3 text-sm leading-relaxed text-muted-foreground"
                                    >
                                        <Check
                                            aria-hidden
                                            className="mt-0.5 size-4 shrink-0 text-primary"
                                        />
                                        {highlight}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    <div className="mt-12 flex flex-wrap gap-4">
                        <Link href={dashboard()} className={PRIMARY_ACTION}>
                            Go to the cockpit
                        </Link>

                        <Link
                            href={coursesIndex()}
                            className={SECONDARY_ACTION}
                        >
                            Browse courses
                        </Link>

                        <Link href={editBilling()} className={SECONDARY_ACTION}>
                            Billing settings
                        </Link>
                    </div>
                </div>
            </main>

            <footer className="border-t border-border">
                <div className="mx-auto max-w-3xl px-6 py-8 font-mono text-[10px] leading-relaxed tracking-widest text-muted-foreground uppercase">
                    Cancel any time from billing settings. Cancelling ends the
                    plan when the period you have paid for runs out.
                </div>
            </footer>
        </div>
    );
}
