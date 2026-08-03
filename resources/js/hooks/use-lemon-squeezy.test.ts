import { describe, expect, it, vi } from 'vitest';
import {
    attachLemonSqueezyScript,
    canOpenOverlay,
    isCheckoutSuccess,
    openCheckoutUrl,
    SCRIPT_ID,
    SCRIPT_SRC,
} from '@/hooks/use-lemon-squeezy';
import type { CheckoutTarget } from '@/hooks/use-lemon-squeezy';

/**
 * What the pricing page makes of Lemon Squeezy's overlay.
 *
 * The hook itself is untestable without a DOM and a live lemon.js, so what is
 * pinned here is the handful of decisions that can actually be wrong: which
 * event counts as a sale, whether a button may be pressed at all, what happens
 * when the script never arrives, and whether a second mount adds a second copy
 * of it. Each of those lives in an exported function taking its `window` or
 * `document` as an argument, which is the only reason they can be exercised in
 * a suite that runs on plain Node.
 *
 * There is deliberately nothing here about failed checkouts: Lemon Squeezy
 * publishes no such event. The hook this replaces recognised two of them, and
 * the tests that covered them have no counterpart — see the hook's doc-block
 * for what that costs.
 */

function fakeOverlay() {
    return {
        Setup: vi.fn(),
        Url: { Open: vi.fn(), Close: vi.fn() },
    };
}

function fakeWindow(overlay?: ReturnType<typeof fakeOverlay>): CheckoutTarget {
    return { LemonSqueezy: overlay, location: { href: '' } };
}

describe('isCheckoutSuccess', () => {
    it('recognises the one checkout event Lemon Squeezy publishes', () => {
        expect(isCheckoutSuccess({ event: 'Checkout.Success' })).toBe(true);
    });

    it('ignores every other event on the bus', () => {
        expect(
            isCheckoutSuccess({ event: 'PaymentMethodUpdate.Mounted' }),
        ).toBe(false);
        expect(
            isCheckoutSuccess({ event: 'PaymentMethodUpdate.Updated' }),
        ).toBe(false);
        expect(isCheckoutSuccess({})).toBe(false);
    });

    it('matches on the exact name, not a lowercased near-miss', () => {
        expect(isCheckoutSuccess({ event: 'checkout.success' })).toBe(false);
        expect(isCheckoutSuccess({ event: 'checkout.completed' })).toBe(false);
    });
});

describe('canOpenOverlay', () => {
    it('is true once the store is configured and lemon.js has set itself up', () => {
        expect(canOpenOverlay(true, fakeWindow(fakeOverlay()))).toBe(true);
    });

    it('is false when this environment has no store behind it', () => {
        expect(canOpenOverlay(false, fakeWindow(fakeOverlay()))).toBe(false);
    });

    it('is false while lemon.js is still in flight', () => {
        expect(canOpenOverlay(true, fakeWindow())).toBe(false);
    });
});

describe('openCheckoutUrl', () => {
    it('opens the overlay when lemon.js is on the page', () => {
        const overlay = fakeOverlay();
        const target = fakeWindow(overlay);

        openCheckoutUrl('https://store.lemonsqueezy.com/checkout/abc', target);

        expect(overlay.Url.Open).toHaveBeenCalledWith(
            'https://store.lemonsqueezy.com/checkout/abc',
        );
        expect(target.location.href).toBe('');
    });

    it('navigates to the checkout when the script was blocked', () => {
        const target = fakeWindow();

        openCheckoutUrl('https://store.lemonsqueezy.com/checkout/abc', target);

        expect(target.location.href).toBe(
            'https://store.lemonsqueezy.com/checkout/abc',
        );
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

describe('attachLemonSqueezyScript', () => {
    it('appends lemon.js when the document has none', () => {
        const { doc, appended } = fakeDocument();

        attachLemonSqueezyScript(doc, () => {});

        expect(appended).toEqual([
            { id: SCRIPT_ID, src: SCRIPT_SRC, async: true },
        ]);
    });

    it('reuses the tag a first mount left behind', () => {
        const { doc, appended, listeners } = fakeDocument();

        attachLemonSqueezyScript(doc, () => {});
        attachLemonSqueezyScript(doc, () => {});

        expect(appended).toHaveLength(1);
        expect(listeners).toHaveLength(2);
    });

    it('runs the caller back when the script lands', () => {
        const { doc, fire } = fakeDocument();
        const onLoad = vi.fn();

        attachLemonSqueezyScript(doc, onLoad);
        fire();

        expect(onLoad).toHaveBeenCalledTimes(1);
    });

    it('detaches on cleanup, so an unmounted page sets nothing up', () => {
        const { doc, fire } = fakeDocument();
        const onLoad = vi.fn();

        attachLemonSqueezyScript(doc, onLoad)();
        fire();

        expect(onLoad).not.toHaveBeenCalled();
    });
});
