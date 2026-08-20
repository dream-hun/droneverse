import type { ReactNode } from 'react';
import { CookieNotice } from '@/components/cookie-notice';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { useFlashToast } from '@/hooks/use-flash-toast';

/**
 * The providers every page renders inside, and the one listener that turns a
 * controller's flashed toast into a visible one.
 *
 * A component rather than the bare tree `withApp` used to return, because
 * `useFlashToast` subscribes to Inertia's flash event and a hook needs somewhere
 * to live. It sits here rather than in a layout so that pages with no layout —
 * the marketing pages, which is where a plan change starts — report what the
 * server said as readily as the settings screens do.
 *
 * In its own module rather than in app.tsx, because a component declaration
 * there makes React Refresh treat the entry as a refresh boundary — and its
 * transform gives a boundary a self-import at a timestamped URL. Blade's
 * `@vite` tag loads the entry without that timestamp, so the two URLs are two
 * modules, and `createInertiaApp()` ran a second time on every load once
 * anything had been edited: a second React root over the first, the first
 * left mounted and unreachable, and — on the simulator — a discarded canvas
 * that took its WebGL context down half a second later.
 */
export function Providers({ children }: { children: ReactNode }) {
    useFlashToast();

    return (
        <TooltipProvider delayDuration={0}>
            {children}
            <Toaster />
            {/*
             * Beside the toaster rather than in a layout, for the same reason
             * the toaster is: the marketing and legal pages render with no
             * layout at all, and those are exactly the pages a first-time
             * visitor lands on — which is the visit the notice exists for.
             */}
            <CookieNotice />
        </TooltipProvider>
    );
}
