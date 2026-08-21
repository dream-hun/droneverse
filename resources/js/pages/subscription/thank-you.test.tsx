// @vitest-environment jsdom

import { act, cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ThankYou from '@/pages/subscription/thank-you';
import type { SubscriptionConfirmation } from '@/types/billing';

/**
 * The poll, held open so a test can drive it.
 *
 * `usePoll` is Inertia's, and what is worth pinning here is not that it ticks
 * — it is what this page asks it to do: run only while the server says the
 * entitlement is still coming, and give up rather than spin forever. So the
 * hook is replaced by a handle onto its controls and its `onFinish`, and the
 * ticks are delivered by hand.
 */
const poll = {
    start: vi.fn(),
    stop: vi.fn(),
    finish: () => {},
};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string | { url: string };
        children: ReactNode;
    }) => (
        <a href={typeof href === 'string' ? href : href.url} {...rest}>
            {children}
        </a>
    ),
    usePage: () => ({ props: { auth: { user: null } } }),
    usePoll: (_interval: number, options: { onFinish?: () => void } = {}) => {
        poll.finish = () => options.onFinish?.();

        return { start: poll.start, stop: poll.stop, polling: true };
    },
}));

const SUBSCRIPTION: SubscriptionConfirmation = {
    planLabel: 'Pro',
    variant: 'yearly',
    onTrial: false,
    trialEndsAt: null,
    renewsAt: '2027-08-21T00:00:00+00:00',
};

beforeEach(() => {
    poll.start.mockClear();
    poll.stop.mockClear();
});

afterEach(cleanup);

describe('ThankYou', () => {
    it('waits, and says so, while the webhook is still in flight', () => {
        render(
            <ThankYou
                plan={{ value: 'starter', label: 'Starter', isPaid: false }}
                subscription={null}
                highlights={[]}
                pending
            />,
        );

        expect(screen.getByRole('heading', { level: 1 }).textContent).toBe(
            'Activating your plan',
        );
        expect(poll.start).toHaveBeenCalled();
    });

    /**
     * The buyer paid before they got here, so nothing on the waiting page may
     * read as a failure or as a bill still to settle.
     */
    it('confirms the payment even before the plan lands', () => {
        render(
            <ThankYou
                plan={{ value: 'starter', label: 'Starter', isPaid: false }}
                subscription={null}
                highlights={[]}
                pending
            />,
        );

        expect(screen.getByRole('status').textContent).toBe('Payment received');
    });

    it('names the plan and stops polling once the webhook has landed', () => {
        render(
            <ThankYou
                plan={{ value: 'pro', label: 'Pro', isPaid: true }}
                subscription={SUBSCRIPTION}
                highlights={['Every course and every mission']}
                pending={false}
            />,
        );

        expect(screen.getByRole('heading', { level: 1 }).textContent).toBe(
            'Welcome to Pro',
        );
        expect(screen.getByText('Billed yearly')).toBeDefined();
        /*
         * No "paid with" line to assert: Creem publishes no card brand or last
         * four on any payload, so the page has nothing to say about the card
         * and the billing portal is where a buyer sees it.
         */
        expect(
            screen.getByText('Every course and every mission'),
        ).toBeDefined();
        expect(poll.start).not.toHaveBeenCalled();
        expect(poll.stop).toHaveBeenCalled();
    });

    /**
     * A webhook that has not arrived in a minute is not going to be waited out
     * by a browser. The page stops asking and says where the plan will show up
     * instead, rather than spinning at the buyer indefinitely.
     *
     * What it must not do is fall through to the confirmed state: that state
     * names a tier off the resolved plan, and the resolved plan of somebody
     * whose webhook is still in flight is Starter. Greeting a pilot who has
     * just paid for Pro with "Welcome to Starter" would be worse than the
     * spinner.
     */
    it('gives up without congratulating the buyer on the plan they did not buy', () => {
        render(
            <ThankYou
                plan={{ value: 'starter', label: 'Starter', isPaid: false }}
                subscription={null}
                highlights={[]}
                pending
            />,
        );

        act(() => {
            for (let tick = 0; tick < 24; tick += 1) {
                poll.finish();
            }
        });

        expect(screen.getByRole('heading', { level: 1 }).textContent).toBe(
            'Still activating your plan',
        );
        expect(screen.getByRole('status').textContent).toBe('Payment received');
        expect(
            screen.getByText(/has not reached us yet/, { exact: false }),
        ).toBeDefined();
        expect(poll.stop).toHaveBeenCalled();
    });
});
