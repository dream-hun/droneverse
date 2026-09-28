import { Link } from '@inertiajs/react';
import { Cookie } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState, useSyncExternalStore } from 'react';
import { Button } from '@/components/ui/button';
import {
    closeCookieSettings,
    cookieSettingsOpen,
    forgetGoogleCookies,
    loadTagManager,
    openCookieSettings,
    reloadPage,
    rememberConsent,
    subscribeToCookieSettings,
} from '@/lib/analytics-consent';
import { privacy } from '@/routes';
import type { ConsentChoice, TagManager } from '@/types/consent';

/**
 * The consent banner for analytics.
 *
 * It replaced a cookie notice that deliberately had nothing to ask: every
 * cookie the site set was strictly necessary, so an Accept button would have
 * asked for a permission nobody needed. Google Tag Manager changed that. What
 * it loads can set cookies and does send Google the visitor's IP address and
 * the pages they read, so under Article 5(3) of the ePrivacy Directive it
 * waits for a yes. That is why this is a real choice with a real refusal.
 *
 * Accept and Reject are the same size, the same weight and one click each,
 * because regulators treat a refusal that is harder to give than consent as
 * no refusal at all. Until a visitor chooses, nothing from Google loads, and
 * after they choose the banner goes away until they reopen it from "Cookie
 * settings", which the public footer, the account menu and the privacy policy
 * all carry. Withdrawing is as easy as consenting, which Article 7(3) GDPR
 * requires.
 *
 * Withdrawing after accepting reloads the page. A script that has run cannot
 * be unloaded, so its cookies are deleted and the page starts again without
 * it; the root template leaves the snippet out for a visitor whose cookie
 * says no.
 *
 * Bottom-left on purpose: the toaster occupies the bottom-right corner, and a
 * flash message landing underneath the banner is a message nobody reads.
 */
export function CookieConsent({
    containerId,
    consent: servedConsent,
}: TagManager) {
    const [consent, setConsent] = useState(servedConsent);
    const reopened = useSyncExternalStore(
        subscribeToCookieSettings,
        cookieSettingsOpen,
        () => false,
    );

    if (consent !== null && !reopened) {
        return null;
    }

    function choose(choice: ConsentChoice) {
        rememberConsent(choice);
        closeCookieSettings();

        if (choice === 'denied' && consent === 'granted' && containerId) {
            forgetGoogleCookies();
            reloadPage();

            return;
        }

        if (choice === 'granted' && containerId) {
            loadTagManager(containerId);
        }

        setConsent(choice);
    }

    return (
        <section
            aria-label="Cookie consent"
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
                            Cookies and analytics
                        </h2>

                        <p className="text-sm text-pretty text-muted-foreground">
                            We set the cookies the site needs to work, like the
                            one that keeps you signed in. With your permission
                            we would also load Google Tag Manager to see how the
                            site is used, which lets Google set analytics
                            cookies and see your IP address and the pages you
                            visit.
                        </p>

                        <p className="text-sm text-pretty text-muted-foreground">
                            Nothing from Google loads unless you accept, and you
                            can change your mind at any time from Cookie
                            settings, in the site footer or your account menu.
                            The{' '}
                            <Link
                                href={`${privacy.url()}#cookies`}
                                className="text-foreground underline underline-offset-4 hover:text-primary"
                            >
                                privacy policy
                            </Link>{' '}
                            has the details.
                        </p>

                        {consent !== null && (
                            <p className="text-sm text-pretty text-muted-foreground">
                                Right now you have{' '}
                                {consent === 'granted'
                                    ? 'accepted'
                                    : 'rejected'}{' '}
                                analytics.
                            </p>
                        )}
                    </div>

                    <div className="flex gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => choose('denied')}
                        >
                            Reject
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => choose('granted')}
                        >
                            Accept
                        </Button>
                    </div>
                </div>
            </div>
        </section>
    );
}

/**
 * Reopens the consent banner, so a visitor can change their answer from
 * wherever they are. Styled by the caller, since it sits in the footer's
 * link row and in the running text of the privacy policy.
 */
export function CookieSettingsButton({
    className,
    children = 'Cookie settings',
}: {
    className?: string;
    children?: ReactNode;
}) {
    return (
        <button
            type="button"
            className={className}
            onClick={openCookieSettings}
        >
            {children}
        </button>
    );
}
