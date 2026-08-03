import { useCallback, useEffect, useRef, useState } from 'react';
import type { LemonSqueezyConfig } from '@/types/billing';

export const SCRIPT_SRC = 'https://app.lemonsqueezy.com/js/lemon.js';
export const SCRIPT_ID = 'lemon-squeezy-js';

/**
 * What Lemon.js hands its event handler.
 *
 * One flat object, always with an `event` name — `Checkout.Success`,
 * `PaymentMethodUpdate.Mounted`, and so on — and a payload whose shape depends
 * on the event. Nothing here reads the payload, so it stays `unknown` rather
 * than being modelled from documentation nobody has verified.
 */
export type LemonSqueezyEvent = {
    event?: string;
    data?: unknown;
};

type LemonSqueezyGlobal = {
    Setup: (options: {
        eventHandler?: (event: LemonSqueezyEvent) => void;
    }) => void;
    Url: { Open: (url: string) => void; Close: () => void };
};

declare global {
    interface Window {
        /** Defined by lemon.js on load; creates `window.LemonSqueezy`. */
        createLemonSqueezy?: () => void;
        LemonSqueezy?: LemonSqueezyGlobal;
    }
}

/**
 * The slice of `window` the pure helpers below need.
 *
 * Narrowed to what is actually read so they can be exercised against a plain
 * object in a test — there is no jsdom in this suite, and the parts worth
 * pinning (the fallback, the script reuse) do not need one.
 */
export type CheckoutTarget = {
    LemonSqueezy?: LemonSqueezyGlobal;
    location: { href: string };
};

/** The only checkout event Lemon Squeezy publishes that this app acts on. */
const SUCCESS_EVENT = 'Checkout.Success';

export function isCheckoutSuccess(event: LemonSqueezyEvent): boolean {
    return event.event === SUCCESS_EVENT;
}

/**
 * Whether a checkout can be opened right now.
 *
 * Both halves matter: an unconfigured environment has no store to check out
 * against, and a configured one still cannot open an overlay until lemon.js
 * has landed and been set up.
 */
export function canOpenOverlay(
    configured: boolean,
    target: CheckoutTarget,
): boolean {
    return configured && Boolean(target.LemonSqueezy);
}

/**
 * Send the buyer to a checkout, overlay for preference.
 *
 * The fallback is not a formality. A Lemon Squeezy checkout URL is a complete,
 * standalone page — the overlay only ever frames it — so a buyer whose browser
 * blocked lemon.js can still pay, in a full-page navigation, rather than
 * clicking a button that does nothing. The option-bag checkout this replaces
 * had no such escape hatch, and could not have had one: it described a
 * purchase to a script rather than naming a page anyone could visit.
 */
export function openCheckoutUrl(url: string, target: CheckoutTarget): void {
    if (target.LemonSqueezy) {
        target.LemonSqueezy.Url.Open(url);

        return;
    }

    target.location.href = url;
}

/**
 * Attach `onLoad` to the lemon.js tag, adding the tag only if it is missing.
 *
 * A second mount — React strict mode, or a client-side visit back to the
 * pricing page — must reuse the tag already in the document rather than append
 * another and set Lemon Squeezy up twice. Returns the cleanup that detaches the
 * listener again.
 */
export function attachLemonSqueezyScript(
    doc: Document,
    onLoad: () => void,
): () => void {
    const existing = doc.getElementById(SCRIPT_ID) as HTMLScriptElement | null;

    if (existing) {
        existing.addEventListener('load', onLoad);

        return () => existing.removeEventListener('load', onLoad);
    }

    const script = doc.createElement('script');
    script.id = SCRIPT_ID;
    script.src = SCRIPT_SRC;
    script.async = true;
    script.addEventListener('load', onLoad);
    doc.head.append(script);

    return () => script.removeEventListener('load', onLoad);
}

/**
 * Load lemon.js on demand and hand back a way to open its overlay.
 *
 * The package would happily have this script in the root template, where every
 * page in the app pays for it so that two can use it. Loading it from the pages
 * that check out keeps the cost where the benefit is, at the price of the
 * `ready` flag below: nothing may open an overlay until the script has landed
 * and `Setup` has run.
 *
 * `configured: false` means Lemon Squeezy has no API key or no store in this
 * environment. Nothing is loaded and `ready` stays false, which is what the
 * buttons read to stay disabled — server-side, ResolveCheckoutPrice refuses
 * those purchases too, so this is presentation rather than protection.
 *
 * `onCompleted` fires when Lemon Squeezy reports the purchase done. It is a cue
 * to go and look for the entitlement, never evidence of one: the plan is
 * granted by the webhook, and this event reaches the browser first.
 *
 * **There is no failure counterpart.** Lemon Squeezy publishes exactly one
 * checkout event, `Checkout.Success`; nothing is emitted when a checkout
 * cannot open or a payment is refused, and the overlay reports those entirely
 * on its own. This is a real regression against Paddle, whose
 * `checkout.error`/`checkout.failed` events carried a `detail` naming the cause
 * — a misconfigured store, an unapproved domain — which the page could put in
 * front of the buyer. Nothing here can do that any more. The only failures this
 * app can still report are the ones the server returns from `POST /checkout`,
 * which is why the `onError` handling on that request now carries the whole
 * weight of telling a buyer that something went wrong.
 */
export function useLemonSqueezy(
    { configured }: LemonSqueezyConfig,
    handlers: { onCompleted?: () => void } = {},
) {
    /*
     * lemon.js survives client-side navigation, so a second visit to the
     * pricing page finds `window.LemonSqueezy` already set up. Reading that in
     * the initialiser rather than in the effect keeps the common case a single
     * render.
     */
    const [ready, setReady] = useState(
        () =>
            typeof window !== 'undefined' && canOpenOverlay(configured, window),
    );

    /*
     * Lemon Squeezy takes its event handler once, at Setup. Holding the
     * caller's handlers in a ref keeps that registration out of the effect's
     * dependencies — an inline handler would otherwise re-run setup on every
     * render that redefined it.
     */
    const registered = useRef(handlers);

    useEffect(() => {
        registered.current = handlers;
    });

    useEffect(() => {
        if (!configured) {
            return;
        }

        const setup = () => {
            /*
             * lemon.js does not install itself; it exposes a factory, and the
             * object every other call goes through only exists once that
             * factory has run. Guarded because the factory replaces
             * `window.LemonSqueezy` wholesale, and a page that ran it twice
             * would leave the first overlay's handlers pointing at an object
             * nothing else refers to any more.
             */
            if (!window.LemonSqueezy) {
                window.createLemonSqueezy?.();
            }

            if (!window.LemonSqueezy) {
                return;
            }

            window.LemonSqueezy.Setup({
                eventHandler: (event) => {
                    if (isCheckoutSuccess(event)) {
                        registered.current.onCompleted?.();
                    }
                },
            });

            setReady(true);
        };

        /*
         * Setup runs on every mount, including the ones that find lemon.js
         * already loaded, because the handler it registers closes over this
         * component's ref. Skipping it when `ready` was already true would
         * leave the previous page instance's handler in place — pointing at a
         * ref that nothing updates any more — and a completed checkout would
         * refresh nothing.
         */
        if (window.LemonSqueezy || window.createLemonSqueezy) {
            setup();

            return;
        }

        return attachLemonSqueezyScript(document, setup);
    }, [configured]);

    const openCheckout = useCallback((url: string) => {
        openCheckoutUrl(url, window);
    }, []);

    return { ready, openCheckout };
}
