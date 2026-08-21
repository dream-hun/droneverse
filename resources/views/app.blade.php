@php
    /*
     * The public pages wrap themselves in `.theme-droneverse dark`, a brand
     * palette with no light variant — see resources/css/app.css and
     * bringsOwnChrome() in resources/js/lib/page-chrome.ts, which lists the
     * same components for the same reason. They are dark whatever the visitor
     * prefers, so the shell behind them has to be too: paint it light and a
     * light-preference visitor gets a white flash before the bundle boots and
     * white gutters around the page whenever they overscroll.
     *
     * Resolved here rather than in the script below because the `dark` class
     * has to be on `<html>` in the markup itself. By the time a script can add
     * it, the first paint has already happened.
     */
    $alwaysDark = in_array($page['component'] ?? '', ['welcome', 'pricing', 'docs', 'subscription/thank-you'], true)
        || str_starts_with($page['component'] ?? '', 'legal/')
        || str_starts_with($page['component'] ?? '', 'courses/');

    $resolvedAppearance = $alwaysDark ? 'dark' : ($appearance ?? 'system');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => $resolvedAppearance === 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $resolvedAppearance }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }

                // Tell the browser which palette its own widgets should use.
                // The class above only reaches what Tailwind paints, so
                // without this the scrollbar, the form controls and the
                // Monaco editor's chrome render light over a dark page until
                // the bundle boots and initializeTheme() catches up. On a
                // marketing page, which is heavier than it is interactive,
                // that flash is most of the first impression.
                document.documentElement.style.colorScheme =
                    document.documentElement.classList.contains('dark') ? 'dark' : 'light';
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        @if ($alwaysDark)
            {{--
                The shell behind an always-dark page, pinned to the brand
                background from .theme-droneverse so the gutters match the page
                rather than framing it in white.

                `!important` because this has to outrank an inline style, not a
                stylesheet: initializeTheme() writes colorScheme straight onto
                the element on boot, and for a visitor whose stored preference
                is light it would write "light" over a page that has no light
                variant. An author !important beats an inline declaration, so
                this survives that write instead of racing it.
            --}}
            <style>
                html {
                    background-color: oklch(14.5% 0.002 286) !important;
                    color-scheme: dark !important;
                }
            </style>
        @endif

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
