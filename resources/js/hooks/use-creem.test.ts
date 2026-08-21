import { describe, expect, it, vi } from 'vitest';
import {
    attachCreemScript,
    canOpenOverlay,
    openCheckoutUrl,
    SCRIPT_ID,
    SCRIPT_SRC,
} from '@/hooks/use-creem';
import type { CheckoutTarget } from '@/hooks/use-creem';

/**
 * What the pricing page makes of Creem's embedded checkout.
 *
 * The hook itself is untestable without a DOM and a live embed.js, so what is
 * pinned here is the handful of decisions that can actually be wrong: whether a
 * button may be pressed at all, what happens when the script never arrives,
 * whether a completed payment dismisses the overlay before this app navigates,
 * and whether a second mount adds a second copy of the script. Each of those
 * lives in an exported function taking its `window` or `document` as an
 * argument, which is the only reason they can be exercised in a suite that runs
 * on plain Node.
 *
 * There is deliberately nothing here about failed checkouts: the embed
 * publishes no such event, and the server's response to `POST /checkout` is the
 * only failure this page can report. See the hook's doc-block.
 */

const CHECKOUT_URL = 'https://www.creem.io/payment/ch_4l0N34kxo16AhRKUHFUuXr';

function fakeEmbed() {
    const close = vi.fn();

    return {
        close,
        openCheckout: vi.fn(
            (options: {
                checkoutUrl: string;
                theme?: 'light' | 'dark';
                onComplete?: (detail: { orderId?: string }) => void;
            }) => {
                const handle = { close };

                return Object.assign(handle, { options });
            },
        ),
    };
}

function fakeWindow(embed?: ReturnType<typeof fakeEmbed>): CheckoutTarget {
    return {
        Creem: embed as unknown as CheckoutTarget['Creem'],
        location: { href: '' },
    };
}

describe('canOpenOverlay', () => {
    it('is true once Creem is configured and embed.js has landed', () => {
        expect(canOpenOverlay(true, fakeWindow(fakeEmbed()))).toBe(true);
    });

    it('is false when this environment has no Creem account behind it', () => {
        expect(canOpenOverlay(false, fakeWindow(fakeEmbed()))).toBe(false);
    });

    it('is false while embed.js is still in flight', () => {
        expect(canOpenOverlay(true, fakeWindow())).toBe(false);
    });
});

describe('openCheckoutUrl', () => {
    it('opens the overlay when embed.js is on the page', () => {
        const embed = fakeEmbed();
        const target = fakeWindow(embed);

        openCheckoutUrl(CHECKOUT_URL, target);

        expect(embed.openCheckout).toHaveBeenCalledTimes(1);
        expect(embed.openCheckout.mock.calls[0][0]).toMatchObject({
            checkoutUrl: CHECKOUT_URL,
            theme: 'dark',
        });
        expect(target.location.href).toBe('');
    });

    it('navigates to the checkout when the script was blocked', () => {
        const target = fakeWindow();

        openCheckoutUrl(CHECKOUT_URL, target);

        expect(target.location.href).toBe(CHECKOUT_URL);
    });

    it('closes the overlay before handing the buyer on', () => {
        const embed = fakeEmbed();
        const onCompleted = vi.fn();

        openCheckoutUrl(CHECKOUT_URL, fakeWindow(embed), onCompleted);

        embed.openCheckout.mock.calls[0][0].onComplete?.({ orderId: 'ord_1' });

        /*
         * close() is what cancels the embed's own pending navigation to the
         * checkout's success_url. Skipping it would let a full page load land
         * on the thank-you page three seconds after this app already went
         * there, tearing down the overlay and the page under it.
         */
        expect(embed.close).toHaveBeenCalledTimes(1);
        expect(onCompleted).toHaveBeenCalledWith({ orderId: 'ord_1' });
    });
});

/**
 * A stand-in for the two document methods the loader touches, plus a record of
 * what it appended, so a second call can be shown to append nothing.
 */
function fakeDocument() {
    const appended: { id: string; src: string; async: boolean }[] = [];
    const listeners: { type: string; handler: () => void }[] = [];

    const element = () => ({
        id: '',
        src: '',
        async: false,
        addEventListener: (type: string, handler: () => void) => {
            listeners.push({ type, handler });
        },
        removeEventListener: (type: string, handler: () => void) => {
            const index = listeners.findIndex(
                (entry) => entry.type === type && entry.handler === handler,
            );

            if (index >= 0) {
                listeners.splice(index, 1);
            }
        },
    });

    let existing: ReturnType<typeof element> | null = null;

    const doc = {
        getElementById: (id: string) => (id === SCRIPT_ID ? existing : null),
        createElement: () => element(),
        head: {
            append: (script: ReturnType<typeof element>) => {
                existing = script;
                appended.push({
                    id: script.id,
                    src: script.src,
                    async: script.async,
                });
            },
        },
    };

    return {
        doc: doc as unknown as Document,
        appended,
        listeners,
        fire: () => listeners.forEach((entry) => entry.handler()),
    };
}

describe('attachCreemScript', () => {
    it('appends embed.js when the document has none', () => {
        const { doc, appended } = fakeDocument();

        attachCreemScript(doc, () => {});

        expect(appended).toEqual([
            { id: SCRIPT_ID, src: SCRIPT_SRC, async: true },
        ]);
    });

    it('reuses the tag a first mount left behind', () => {
        const { doc, appended, listeners } = fakeDocument();

        attachCreemScript(doc, () => {});
        attachCreemScript(doc, () => {});

        expect(appended).toHaveLength(1);
        expect(listeners).toHaveLength(2);
    });

    it('runs the caller back when the script lands', () => {
        const { doc, fire } = fakeDocument();
        const onLoad = vi.fn();

        attachCreemScript(doc, onLoad);
        fire();

        expect(onLoad).toHaveBeenCalledTimes(1);
    });

    it('detaches on cleanup, so an unmounted page loads nothing', () => {
        const { doc, fire } = fakeDocument();
        const onLoad = vi.fn();

        attachCreemScript(doc, onLoad)();
        fire();

        expect(onLoad).not.toHaveBeenCalled();
    });
});
