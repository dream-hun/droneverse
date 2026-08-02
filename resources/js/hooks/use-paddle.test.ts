import { describe, expect, it } from 'vitest';
import { checkoutFailureMessage, isCheckoutFailure } from '@/hooks/use-paddle';

/**
 * What the page makes of a checkout Paddle would not open.
 *
 * The hook around these is untestable without a DOM and a Paddle script, so
 * what is pinned here is the pair that decides whether anybody hears about a
 * failure and what they read when they do. Both payloads below were captured
 * from Paddle's sandbox rather than written from the docs: the two event names
 * carry their detail at different depths, and the shape that is easy to guess
 * is the one that never fires.
 */
describe('isCheckoutFailure', () => {
    it('recognises checkout.error, sent by the checkout app', () => {
        expect(isCheckoutFailure({ name: 'checkout.error' })).toBe(true);
    });

    it('recognises checkout.failed, sent by the static error frame', () => {
        expect(isCheckoutFailure({ name: 'checkout.failed' })).toBe(true);
    });

    it('ignores the events of a checkout that is working', () => {
        expect(isCheckoutFailure({ name: 'checkout.completed' })).toBe(false);
        expect(isCheckoutFailure({ name: 'checkout.loaded' })).toBe(false);
        expect(isCheckoutFailure({ name: 'checkout.closed' })).toBe(false);
        expect(isCheckoutFailure({})).toBe(false);
    });
});

describe('checkoutFailureMessage', () => {
    it('reads the detail off a checkout.error, where it sits on the event', () => {
        expect(
            checkoutFailureMessage({
                name: 'checkout.error',
                type: 'api_error',
                code: 'validation',
                detail: 'transaction_default_checkout_url_not_set',
            }),
        ).toBe('transaction_default_checkout_url_not_set');
    });

    it('reads the detail off a checkout.failed, where it sits under error', () => {
        expect(
            checkoutFailureMessage({
                name: 'checkout.failed',
                error: {
                    type: 'front-end_error',
                    code: 'validation',
                    detail: 'Something went wrong. error code: E-403',
                },
            }),
        ).toBe('Something went wrong. error code: E-403');
    });

    it('prefers the nested detail when an event somehow carries both', () => {
        expect(
            checkoutFailureMessage({
                name: 'checkout.failed',
                detail: 'outer',
                error: { detail: 'inner' },
            }),
        ).toBe('inner');
    });

    it('trims the detail rather than reporting its whitespace', () => {
        expect(
            checkoutFailureMessage({
                name: 'checkout.error',
                detail: '  Checkouts are not enabled.  ',
            }),
        ).toBe('Checkouts are not enabled.');
    });

    it('falls back when Paddle sends no detail at all', () => {
        expect(checkoutFailureMessage({ name: 'checkout.error' })).toBe(
            'Checkout could not be opened. Please try again.',
        );
    });

    it('falls back when the nested error carries no detail', () => {
        expect(
            checkoutFailureMessage({
                name: 'checkout.failed',
                error: { type: 'front-end_error' },
            }),
        ).toBe('Checkout could not be opened. Please try again.');
    });

    it('falls back on a blank detail rather than showing an empty toast', () => {
        expect(
            checkoutFailureMessage({ name: 'checkout.error', detail: '   ' }),
        ).toBe('Checkout could not be opened. Please try again.');
    });
});
