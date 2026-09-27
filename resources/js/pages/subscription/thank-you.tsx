import { Head, Link, usePoll } from '@inertiajs/react';
import { Check, Clock, LoaderCircle } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Wordmark } from '@/components/marketing/site-header';
import { cn } from '@/lib/utils';
import { dashboard, home } from '@/routes';
import { edit as editBilling } from '@/routes/billing';
import { index as coursesIndex } from '@/routes/courses';
import type { PlanValue } from '@/types/auth';

type Props = {
    plan: { value: PlanValue; label: string; isPaid: boolean };
    highlights: string[];
    /**
     * Paid, and not granted yet. The page polls on this and on nothing else —
     * see App\Actions\BuildSubscriptionConfirmation for why it is the server's
     * answer rather than a flag the checkout handler passes along.
     */
    pending: boolean;
};

/**
 * What the page is saying, in the order a buyer meets them: waiting on Kelviq
 * to grant the plan, waiting on it for longer than the poll will, and done.
 */
type ConfirmationState = 'waiting' | 'stalled' | 'confirmed';

/** How often the page asks whether the plan has landed, and for how long. */
const POLL_INTERVAL = 2500;
const POLL_LIMIT = 24;

const PRIMARY_ACTION =
    'bg-primary px-6 py-4 text-center font-bold tracking-widest text-primary-foreground uppercase transition-all hover:brightness-110';

const SECONDARY_ACTION =
    'border border-border px-6 py-4 text-center font-mono text-xs tracking-widest text-foreground uppercase transition-colors hover:border-primary hover:text-primary';

/**
 * Watch for the entitlement the buyer has already paid for.
 *
 * Polling rather than pushing: each tick asks Kelviq afresh whether the
 * entitlements have been granted, and there is no channel from Kelviq to the
 * browser. It stops the moment the plan lands and gives up after POLL_LIMIT
 * ticks: a grant that has not arrived in a minute is not going to be waited
 * out by a browser, and a spinner that never resolves is a worse answer than a
 * page that admits it is still waiting and says where to look.
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
            only: ['plan', 'highlights', 'pending', 'auth'],
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

/**
 * The confirmation a buyer lands on when Kelviq's checkout sends them back.
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
 * paid. `waiting` is the gap between the money leaving and Kelviq granting
 * the plan, which is where most buyers arrive. `stalled` is that gap outlasting
 * the poll. Neither may claim a plan, because no plan is known yet — and
 * neither may imply the payment did not go through, because it did. Only
 * `confirmed` names a tier, and only once Kelviq's entitlements say so.
 */
export default function ThankYou({ plan, highlights, pending }: Props) {
    const gaveUp = useEntitlementWatch(pending);
    const state: ConfirmationState = pending
        ? gaveUp
            ? 'stalled'
            : 'waiting'
        : 'confirmed';

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
                            ? 'Plan active'
                            : 'Payment received'}
                    </p>

                    <h1 className="mt-4 text-4xl font-bold tracking-tighter text-balance text-foreground uppercase sm:text-5xl">
                        {state === 'confirmed' && `Welcome to ${plan.label}`}
                        {state === 'stalled' && 'Still activating your plan'}
                        {state === 'waiting' && 'Activating your plan'}
                    </h1>

                    <p className="mt-6 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                        {state === 'waiting' && (
                            <>
                                Your payment went through and we are waiting on
                                the confirmation from our payment provider. This
                                usually takes a few seconds. This page updates
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
                                Thank you. Your plan is live and every mission
                                it covers is unlocked on this account right now.
                                A receipt is on its way to your inbox.
                            </>
                        )}
                    </p>

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
                    A subscription can be cancelled any time from Manage billing
                    in your settings, and ends when the period you have paid for
                    runs out.
                </div>
            </footer>
        </div>
    );
}
