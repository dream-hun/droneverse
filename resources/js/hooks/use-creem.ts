import { useCallback, useEffect, useRef, useSyncExternalStore } from 'react';
import type { CreemConfig } from '@/types/billing';

export const SCRIPT_SRC = 'https://www.creem.io/embed.js';
export const SCRIPT_ID = 'creem-embed-js';

/**
 * What Creem's embed hands `onComplete`.
 *
 * `redirectUrl` is the checkout's own success URL, which the embed navigates
 * the top window to a few seconds after payment unless it is told not to. This
 * page always tells it not to — see `openCheckoutUrl` — so nothing here reads
 * the field; it stays declared because knowing it exists is the whole reason
 * that call is made.
 */
export type CreemCheckoutCompletion = {
    checkoutId?: string;
    orderId?: string;
    orderNo?: string;
    redirect?: boolean;
    redirectUrl?: string;
};

type CreemOverlayHandle = { close: () => void };

type CreemGlobal = {
    openCheckout: (options: {
        checkoutUrl: string;
        theme?: 'light' | 'dark';
        locale?: string;
        onReady?: () => void;
        onComplete?: (detail: CreemCheckoutCompletion) => void;
        onClose?: () => void;
    }) => CreemOverlayHandle;
    close: () => void;
};

declare global {
    interface Window {
        /** Defined by embed.js once it loads. */
        Creem?: CreemGlobal;
    }
}

/**
 * The slice of `window` the pure helpers below need.
 *
 * Narrowed to what is actually read so they can be exercised against a plain
 * object in a test — there is no jsdom in this suite, and the parts worth
 * pinning (the fallback, the script reuse, closing the overlay) do not need
 * one.
 */
export type CheckoutTarget = {
    Creem?: CreemGlobal;
    location: { href: string };
};

/**
 * Whether a checkout can be opened right now.
 *
 * Both halves matter: an unconfigured environment has no Creem account to check
 * out against, and a configured one still cannot open an overlay until embed.js
 * has landed.
 */
export function canOpenOverlay(
    configured: boolean,
    target: CheckoutTarget,
): boolean {
    return configured && Boolean(target.Creem);
}

/**
 * Send the buyer to a checkout, overlay for preference.
 *
 * Two things happen on completion, and the order matters. `close()` dismisses
 * the overlay *and cancels the pending navigation* to the checkout's success
 * URL — without it the embed would take the whole document to the thank-you
 * page about three seconds later, tearing down the page underneath. Cancelling
 * it leaves `onCompleted` free to make the same trip as an Inertia visit, which
 * keeps the app mounted and the overlay closable on the buyer's own terms.
 *
 * The fallback is not a formality. A Creem checkout URL is a complete,
 * standalone page — the overlay only ever frames it — so a buyer whose browser
 * blocked embed.js can still pay, in a full-page navigation, and the
 * `success_url` on the session brings them back to the same confirmation. The
 * option-bag checkout Paddle used had no such escape hatch, and could not have
 * had one: it described a purchase to a script rather than naming a page anyone
 * could visit.
 */
export function openCheckoutUrl(
    url: string,
    target: CheckoutTarget,
    onCompleted?: (detail: CreemCheckoutCompletion) => void,
): void {
    if (target.Creem) {
        const overlay = target.Creem.openCheckout({
            checkoutUrl: url,
            /*
             * The marketing pages have no light variant — see
             * resources/views/app.blade.php — so a light checkout would be the
             * only white rectangle a buyer had seen all session.
             */
            theme: 'dark',
            onComplete: (detail) => {
                overlay.close();
                onCompleted?.(detail);
            },
        });

        return;
    }

    target.location.href = url;
}

/**
 * Attach `onLoad` to the embed.js tag, adding the tag only if it is missing.
 *
 * A second mount — React strict mode, or a client-side visit back to the
 * pricing page — must reuse the tag already in the document rather than append
 * another. Returns the cleanup that detaches the listener again.
 */
export function attachCreemScript(
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
 * Load embed.js on demand and hand back a way to open its overlay.
 *
 * Creem's own advice is to put the script on every page, because it captures
 * affiliate attribution wherever it loads. This application runs no affiliate
 * programme, so loading it from the two pages that check out keeps the cost
 * where the benefit is — at the price of the `ready` flag below, which is what
 * stops a button being pressed before the script has landed.
 *
 * `configured: false` means Creem has no API key in this environment. Nothing
 * is loaded and `ready` stays false, which is what the buttons read to stay
 * disabled — server-side, ResolveCheckoutPrice refuses those purchases too, so
 * this is presentation rather than protection.
 *
 * `onCompleted` fires when Creem reports the payment done. It is a cue to go
 * and look for the entitlement, never evidence of one: it fires in the browser
 * and can be spoofed, the plan is granted by a webhook, and Creem's own
 * documentation is blunt about the difference.
 *
 * **There is no failure counterpart.** The embed publishes `ready`, `complete`
 * and `close`, and nothing at all when a payment is refused — the overlay
 * reports that entirely on its own, inside the iframe. So every failure this
 * application can put in front of a buyer is one the server returned from
 * `POST /checkout`, which is why the `onError` handling on that request carries
 * the whole weight of saying that something went wrong.
 */
export function useCreem(
    { configured }: CreemConfig,
    handlers: { onCompleted?: () => void } = {},
) {
    /*
     * Held in a ref so that an inline handler — redefined on every render —
     * does not have to be a dependency of the callback that opens checkout.
     */
    const registered = useRef(handlers);

    useEffect(() => {
        registered.current = handlers;
    });

    /*
     * Subscribing to the script rather than tracking it in state, because
     * "has embed.js loaded?" is a fact about the document rather than about
     * this component — and the two disagree in the one case that matters. The
     * page is server-rendered, so the first client render has to match a server
     * where there is no `window` at all; a second visit to this page then
     * hydrates into a document that already has the script, and the answer has
     * to change on that render rather than on a later one.
     *
     * `useSyncExternalStore` is built for exactly that shape: the server
     * snapshot is always false, the client snapshot reads the global, and the
     * subscription only has to say when it changes. The script tag is appended
     * from `subscribe` because loading it *is* the subscription — nothing else
     * would ever make the snapshot change.
     */
    const subscribe = useCallback(
        (onChange: () => void) => {
            if (!configured || window.Creem) {
                return () => {};
            }

            return attachCreemScript(document, onChange);
        },
        [configured],
    );

    const loaded = useSyncExternalStore(
        subscribe,
        () => Boolean(window.Creem),
        () => false,
    );

    const openCheckout = useCallback((url: string) => {
        openCheckoutUrl(url, window, () => registered.current.onCompleted?.());
    }, []);

    return { ready: configured && loaded, openCheckout };
}
