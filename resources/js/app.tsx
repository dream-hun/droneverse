import { createInertiaApp } from '@inertiajs/react';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { bringsOwnChrome } from '@/lib/page-chrome';
import { pageTitle } from '@/lib/page-title';
import { Providers } from '@/providers';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

/*
 * Nothing component-shaped belongs in this file — see the note on
 * `Providers`. React Refresh boundaries self-import at a timestamped URL,
 * which this entry, loaded bare by Blade, would evaluate as a second module:
 * two Inertia apps, two roots, one page.
 */

createInertiaApp({
    title: (title) => pageTitle(title, appName),
    /*
     * The `head` prop: the title, description, canonical link and social card
     * the server builds for each page (App\Actions\BuildPageHead), which the
     * root template has already printed into the HTML. Handing it to Inertia
     * as well keeps it current across client-side visits instead of leaving
     * the first page's canonical link behind on every page after it.
     */
    serverHead: true,
    layout: (name) => {
        switch (true) {
            // Public pages bring their own chrome; the app shell assumes a
            // signed-in pilot and would break for a guest.
            case bringsOwnChrome(name):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return <Providers>{app}</Providers>;
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
