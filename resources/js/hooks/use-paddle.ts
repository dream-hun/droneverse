import { useCallback, useEffect, useRef, useState } from 'react';
import type { PaddleConfig } from '@/types/billing';

const SCRIPT_SRC = 'https://cdn.paddle.com/paddle/v2/paddle.js';
const SCRIPT_ID = 'paddle-js';

type PaddleCheckoutOptions = Record<string, unknown>;

/**
 * Paddle's failure payload.
 *
 * `detail` is the only field worth showing anyone — it names the cause, e.g.
 * `transaction_default_checkout_url_not_set` when the account has no default
 * payment link, or "Something went wrong. error code: E-403" for a domain
 * Paddle has not approved for checkout.
 *
 * It arrives in two shapes, and both are real. The checkout app posts
 * `checkout.error` with the fields on the event itself; the static error frame
 * posts `checkout.failed` with them nested under `error`. Reading only one of
 * them is how a failure ends up silent.
 */
type PaddleEventError = {
    type?: string;
    code?: string;
    detail?: string;
};

type PaddleEvent = PaddleEventError & {
    name?: string;
    error?: PaddleEventError;
};

type PaddleGlobal = {
    Environment: { set: (environment: string) => void };
    Initialize: (options: {
        token: string;
        eventCallback?: (event: PaddleEvent) => void;
    }) => void;
    Checkout: { open: (options: PaddleCheckoutOptions) => void };
};

declare global {
    interface Window {
        Paddle?: PaddleGlobal;
    }
}

const GENERIC_FAILURE = 'Checkout could not be opened. Please try again.';

/** The events Paddle sends when a checkout never opens. */
const FAILURE_EVENTS = ['checkout.error', 'checkout.failed'];

export function isCheckoutFailure(event: PaddleEvent): boolean {
    return (
        typeof event.name === 'string' && FAILURE_EVENTS.includes(event.name)
    );
}

/**
 * What to tell a buyer whose checkout Paddle refused to open.
 *
 * Paddle's own frame says "Something went wrong" and nothing else, so a page
 * that stays silent leaves the buyer reading an iframe that names neither the
 * cause nor anyone who could fix it. Where Paddle sends a `detail` it is passed
 * through verbatim, error code and all: the codes are the diagnosis —
 * `transaction_default_checkout_url_not_set` is an account with no default
 * payment link, which is our configuration rather than the buyer's card — and
 * paraphrasing one loses the only part of the message that leads anywhere.
 */
export function checkoutFailureMessage(event: PaddleEvent): string {
    const detail = event.error?.detail ?? event.detail;

    if (typeof detail !== 'string' || detail.trim() === '') {
        return GENERIC_FAILURE;
    }

    return detail.trim();
}

/**
 * Load Paddle.js on demand and hand back a way to open its overlay.
 *
 * Cashier's `@paddleJS` directive would put this script in the root template,
 * where every page in the app pays for it so that two can use it. Loading it
 * from the pages that check out keeps the cost where the benefit is, at the
 * price of the `ready` flag below: nothing may open a checkout until the script
 * has landed and initialised.
 *
 * A missing token means Paddle is unconfigured in this environment. Nothing is
 * loaded and `ready` stays false, which is what the buttons read to stay
 * disabled — server-side, ResolveCheckoutPrice refuses those purchases too, so
 * this is presentation rather than protection.
 *
 * `onCompleted` fires when Paddle reports the purchase done. It is a cue to go
 * and look for the entitlement, never evidence of one: the plan is granted by
 * the webhook, and this event reaches the browser first.
 *
 * `onFailed` fires when Paddle refuses to open the checkout at all, carrying
 * the message from checkoutFailureMessage(). Without it the overlay renders
 * Paddle's own "Something went wrong" frame and the app learns nothing, which
 * makes a misconfigured account look to everyone like a declined card.
 */
export function usePaddle(
    { token, sandbox }: PaddleConfig,
    handlers: {
        onCompleted?: () => void;
        onFailed?: (message: string) => void;
    } = {},
) {
    /*
     * Paddle.js survives client-side navigation, so a second visit to the
     * pricing page finds it already on `window`. Reading that in the initialiser
     * rather than in the effect keeps the common case a single render.
     */
    const [ready, setReady] = useState(
        () => typeof window !== 'undefined' && Boolean(token && window.Paddle),
    );

    /*
     * Paddle takes its event callback once, at Initialize. Holding the caller's
     * handlers in a ref keeps that registration out of the effect's dependencies
     * — an inline handler would otherwise re-run initialisation on every render
     * that redefined it.
     */
    const registered = useRef(handlers);

    useEffect(() => {
        registered.current = handlers;
    });

    useEffect(() => {
        if (!token || ready) {
            return;
        }

        const initialize = () => {
            if (!window.Paddle) {
                return;
            }

            if (sandbox) {
                window.Paddle.Environment.set('sandbox');
            }

            window.Paddle.Initialize({
                token,
                eventCallback: (event) => {
                    if (event.name === 'checkout.completed') {
                        registered.current.onCompleted?.();
                    }

                    if (isCheckoutFailure(event)) {
                        /*
                         * Logged as well as reported, because in local and
                         * staging this is the line Boost forwards into
                         * storage/logs/browser.log — the toast is gone by the
                         * time anyone is asked what it said.
                         */
                        console.error('Paddle checkout failed', event);

                        registered.current.onFailed?.(
                            checkoutFailureMessage(event),
                        );
                    }
                },
            });
            setReady(true);
        };

        /*
         * A second mount — React strict mode, or a client-side visit back to
         * the page — must reuse the tag already in the document rather than
         * append another and initialise Paddle twice.
         */
        const existing = document.getElementById(
            SCRIPT_ID,
        ) as HTMLScriptElement | null;

        if (existing) {
            existing.addEventListener('load', initialize);

            return () => existing.removeEventListener('load', initialize);
        }

        const script = document.createElement('script');
        script.id = SCRIPT_ID;
        script.src = SCRIPT_SRC;
        script.async = true;
        script.addEventListener('load', initialize);
        document.head.append(script);

        return () => script.removeEventListener('load', initialize);
    }, [token, sandbox, ready]);

    const openCheckout = useCallback((options: PaddleCheckoutOptions) => {
        window.Paddle?.Checkout.open(options);
    }, []);

    return { ready, openCheckout };
}
