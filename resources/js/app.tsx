import { createInertiaApp } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import { useFlashToast } from '@/hooks/use-flash-toast';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { bringsOwnChrome } from '@/lib/page-chrome';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

/**
 * The providers every page renders inside, and the one listener that turns a
 * controller's flashed toast into a visible one.
 *
 * A component rather than the bare tree `withApp` used to return, because
 * `useFlashToast` subscribes to Inertia's flash event and a hook needs somewhere
 * to live. It sits here rather than in a layout so that pages with no layout —
 * the marketing pages, which is where a plan change starts — report what the
 * server said as readily as the settings screens do.
 */
function Providers({ children }: { children: ReactNode }) {
    useFlashToast();

    return (
        <TooltipProvider delayDuration={0}>
            {children}
            <Toaster />
        </TooltipProvider>
    );
}

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
