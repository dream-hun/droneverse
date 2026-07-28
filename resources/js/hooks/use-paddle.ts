import { useCallback, useEffect, useRef, useState } from 'react';
import type { PaddleConfig } from '@/types/billing';

const SCRIPT_SRC = 'https://cdn.paddle.com/paddle/v2/paddle.js';
const SCRIPT_ID = 'paddle-js';

type PaddleCheckoutOptions = Record<string, unknown>;

type PaddleEvent = { name?: string };

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
 */
export function usePaddle(
    { token, sandbox }: PaddleConfig,
    onCompleted?: () => void,
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
     * handler in a ref keeps that registration out of the effect's dependencies
     * — an inline handler would otherwise re-run initialisation on every render
     * that redefined it.
     */
    const completed = useRef(onCompleted);

    useEffect(() => {
        completed.current = onCompleted;
    }, [onCompleted]);

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
                        completed.current?.();
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
