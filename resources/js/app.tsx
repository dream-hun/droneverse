import { createInertiaApp } from '@inertiajs/react';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { bringsOwnChrome } from '@/lib/page-chrome';
import { Providers } from '@/providers';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

/*
 * Nothing component-shaped belongs in this file — see the note on
 * `Providers`. React Refresh boundaries self-import at a timestamped URL,
 * which this entry, loaded bare by Blade, would evaluate as a second module:
 * two Inertia apps, two roots, one page.
 */

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
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
