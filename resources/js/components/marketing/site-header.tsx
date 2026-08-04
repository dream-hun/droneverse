import { Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { dashboard, home, login, pricing, register } from '@/routes';
import { index as coursesIndex } from '@/routes/courses';

/**
 * The app's quadcopter mark on its tile, matching how the dashboard sidebar
 * badges it — the mark alone loses its rotors at wordmark size.
 */
export function Wordmark() {
    return (
        <span className="flex items-center gap-3">
            <span className="flex size-8 items-center justify-center rounded-sm bg-primary">
                <AppLogoIcon
                    aria-hidden
                    className="size-4.5 fill-current text-primary-foreground"
                />
            </span>
            <span className="font-mono text-lg font-bold tracking-tighter uppercase">
                DroneVerse
            </span>
        </span>
    );
}

/**
 * The public site header, shared by every page a signed-out visitor lands on.
 *
 * The two section links are plain anchors rather than Inertia visits: they
 * point at anchors on the landing page, so from pricing they need to navigate
 * and then scroll, and from the landing page they should only scroll.
 */
export function SiteHeader({ current }: { current?: 'courses' | 'pricing' }) {
    const { auth } = usePage().props;

    const links = [
        { key: 'why', label: 'Why DroneVerse', href: `${home.url()}#why` },
        { key: 'cockpit', label: 'The cockpit', href: `${home.url()}#cockpit` },
        { key: 'courses', label: 'Courses', href: coursesIndex.url() },
        { key: 'pricing', label: 'Pricing', href: pricing.url() },
    ] as const;

    return (
        <header className="sticky top-0 z-50 border-b border-border bg-background/80 backdrop-blur-md">
            <nav className="mx-auto flex h-16 max-w-7xl items-center justify-between px-6">
                <Link href={home()} aria-label="DroneVerse home">
                    <Wordmark />
                </Link>

                <div className="hidden items-center gap-8 font-mono text-xs tracking-widest text-muted-foreground uppercase md:flex">
                    {links.map((link) => (
                        <a
                            key={link.key}
                            href={link.href}
                            aria-current={
                                current === link.key ? 'page' : undefined
                            }
                            className={
                                current === link.key
                                    ? 'text-primary'
                                    : 'transition-colors hover:text-primary'
                            }
                        >
                            {link.label}
                        </a>
                    ))}
                </div>

                <div className="flex items-center gap-6">
                    {auth.user ? (
                        <Link
                            href={dashboard()}
                            className="bg-foreground px-4 py-2 text-xs font-bold tracking-tighter text-background uppercase transition-colors hover:bg-primary hover:text-primary-foreground"
                        >
                            Dashboard
                        </Link>
                    ) : (
                        <>
                            <Link
                                href={login()}
                                className="hidden font-mono text-xs tracking-widest text-muted-foreground uppercase transition-colors hover:text-primary sm:inline"
                            >
                                Log in
                            </Link>
                            <Link
                                href={register()}
                                className="bg-foreground px-4 py-2 text-xs font-bold tracking-tighter text-background uppercase transition-colors hover:bg-primary hover:text-primary-foreground"
                            >
                                Start free
                            </Link>
                        </>
                    )}
                </div>
            </nav>
        </header>
    );
}
