import { Link } from '@inertiajs/react';
import { Cookie } from 'lucide-react';
import { useState, useSyncExternalStore } from 'react';
import { Button } from '@/components/ui/button';
import { privacy } from '@/routes';

/**
 * Where the acknowledgement is remembered.
 *
 * `localStorage` rather than a cookie, which is not a joke at its own
 * expense: a cookie would be sent to the server on every request that has no
 * use for it, and it would make the banner explaining our cookies the reason
 * for one more of them. Nothing about the notice needs to be readable
 * server-side.
 */
const STORAGE_KEY = 'cookie-notice-acknowledged';

const listeners = new Set<() => void>();

function subscribe(onStoreChange: () => void): () => void {
    listeners.add(onStoreChange);

    return () => {
        listeners.delete(onStoreChange);
    };
}

/**
 * Read fresh on every call rather than memoised in a module variable.
 *
 * `useSyncExternalStore` only needs the value to be stable between changes,
 * and a boolean off `localStorage` is. A cached one would outlive the
 * component that read it.
 */
function acknowledged(): boolean {
    try {
        return localStorage.getItem(STORAGE_KEY) === 'true';
    } catch {
        /*
         * Storage can throw outright — Safari's private mode, a browser
         * configured to block site data. Fail towards showing the notice: an
         * information notice shown twice is a nuisance, one never shown is a
         * disclosure we did not make.
         */
        return false;
    }
}

/**
 * During server render and hydration the notice counts as acknowledged, so
 * it is absent from both passes of markup.
 *
 * SSR is enabled and the server has no `localStorage` to consult. Reporting
 * "hidden" for both is what keeps the two renders identical; React swaps to
 * the real snapshot as soon as hydration finishes, which is when the notice
 * appears.
 */
function serverSnapshot(): boolean {
    return true;
}

/**
 * A cookie notice, not a consent banner. The difference is the whole design.
 *
 * This application sets a session cookie, a CSRF token, a remember-me token
 * if it was asked for, and two interface preferences. Every one of them is
 * strictly necessary to provide a service the visitor expressly requested,
 * which is the exemption in Article 5(3) of the ePrivacy Directive — they are
 * set whether or not anybody clicks anything here. So there is nothing to
 * accept and nothing to reject, and putting an Accept button on it would be
 * asking for a permission we neither need nor would honour a refusal of.
 * That is a dark pattern with a friendly face, and regulators have said so.
 *
 * What is left is worth doing: saying plainly what is stored and pointing at
 * the page that lists it. If a third-party analytics script is ever added,
 * this component is not what changes — it stops being adequate, and a real
 * consent mechanism with a real refusal path replaces it, along with the
 * cookies section of the privacy policy and its effective date.
 *
 * Bottom-left on purpose: the toaster occupies the bottom-right corner, and a
 * flash message landing underneath the notice is a message nobody reads.
 */
export function CookieNotice() {
    const dismissed = useSyncExternalStore(
        subscribe,
        acknowledged,
        serverSnapshot,
    );

    /*
     * Belt and braces for the case where the write below throws: the store
     * would keep reporting "not acknowledged" and the notice would refuse to
     * go away. This holds the dismissal for the life of the page, which is
     * the most that can be promised when the browser will not store anything.
     */
    const [dismissedHere, setDismissedHere] = useState(false);

    function dismiss() {
        try {
            localStorage.setItem(STORAGE_KEY, 'true');
        } catch {
            // Nothing to do — `dismissedHere` below covers it.
        }

        setDismissedHere(true);

        for (const listener of listeners) {
            listener();
        }
    }

    if (dismissed || dismissedHere) {
        return null;
    }

    return (
        <section
            aria-label="Cookie notice"
            className="fixed inset-x-4 bottom-4 z-50 max-w-md animate-entry border border-border bg-popover p-4 text-popover-foreground shadow-lg sm:inset-x-auto sm:left-4"
        >
            <div className="flex gap-3">
                <Cookie
                    className="mt-0.5 size-4 shrink-0 text-primary"
                    aria-hidden="true"
                />

                <div className="space-y-3">
                    <div className="space-y-1.5">
                        <h2 className="text-sm font-medium">
                            About the cookies here
                        </h2>

                        <p className="text-sm text-pretty text-muted-foreground">
                            We only set what the site needs to work: keeping you
                            signed in, protecting forms against other sites, and
                            remembering your interface preferences. No
                            advertising, no third-party analytics, nothing that
                            follows you elsewhere.
                        </p>

                        <p className="text-sm text-pretty text-muted-foreground">
                            That means there is nothing here to consent to —
                            this is a notice, not a request. The{' '}
                            <Link
                                href={`${privacy.url()}#cookies`}
                                className="text-foreground underline underline-offset-4 hover:text-primary"
                            >
                                privacy policy
                            </Link>{' '}
                            lists every one of them.
                        </p>
                    </div>

                    <Button type="button" size="sm" onClick={dismiss}>
                        Got it
                    </Button>
                </div>
            </div>
        </section>
    );
}
