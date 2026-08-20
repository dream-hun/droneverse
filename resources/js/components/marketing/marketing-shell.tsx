import type { ReactNode } from 'react';
import { SiteFooter } from '@/components/marketing/site-footer';
import { SiteHeader } from '@/components/marketing/site-header';

/**
 * The two button treatments the public pages use, as class strings.
 *
 * Constants rather than a `Button` variant because none of these are buttons:
 * every one of them is an Inertia `Link`, and the marketing palette wants a
 * square block of colour rather than the rounded control the app shell uses.
 */
export const PRIMARY_ACTION =
    'bg-primary px-6 py-4 font-bold tracking-widest text-primary-foreground uppercase transition-all hover:brightness-110';

export const SECONDARY_ACTION =
    'border border-border px-6 py-4 font-mono text-xs tracking-widest text-foreground uppercase transition-colors hover:border-primary hover:text-primary';

/**
 * The square small-caps chip the public pages label things with — a tier, a
 * status, a count. Compose it with the border and text colour that says which:
 * `cn(PILL, 'border-primary text-primary')`.
 */
export const PILL =
    'flex shrink-0 items-center gap-1.5 border px-2 py-0.5 font-mono text-[10px] tracking-widest uppercase';

/** Small caps label that opens each section, matching the header rhythm. */
export function SectionLabel({ children }: { children: string }) {
    return (
        <h2 className="mb-12 font-mono text-xs tracking-widest text-primary uppercase">
            {children}
        </h2>
    );
}

/**
 * The chrome every public page wears.
 *
 * `theme-droneverse` is the brand palette — orange accent on near-black — and
 * it is dark-only by design, which is why `dark` is hardcoded beside it rather
 * than left to the visitor's preference. The shell behind it is pinned to
 * match in resources/views/app.blade.php, and which components get that
 * treatment is listed once in `bringsOwnChrome()`; a page wrapped in this
 * without being named there renders inside the signed-in app shell as well,
 * which is two headers and a sidebar that assumes a user.
 *
 * The footer takes no courses here. It names real courses when the page it
 * sits on already carries the catalogue — only the landing page does, and it
 * builds its own root because of the full-bleed hero.
 */
export function MarketingShell({
    current,
    children,
}: {
    /** Marks the header link for the section this page belongs to. */
    current?: 'courses' | 'docs' | 'pricing';
    children: ReactNode;
}) {
    return (
        <div className="theme-droneverse dark flex min-h-screen flex-col bg-background font-sans text-foreground selection:bg-primary selection:text-primary-foreground">
            <SiteHeader current={current} />

            <main className="flex-1">{children}</main>

            <SiteFooter />
        </div>
    );
}

/**
 * The banded page title every public sub-page opens on.
 *
 * One band, ruled off from the body below it, so /courses, /pricing and
 * /terms all begin the same way — the eyebrow names the section, the title is
 * the page, and everything under it is optional.
 */
export function MarketingPageHeader({
    eyebrow,
    title,
    lede,
    meta,
    actions,
}: {
    eyebrow: string;
    title: string;
    lede?: string;
    /** A line of small caps facts under the lede: difficulty, counts, dates. */
    meta?: ReactNode;
    actions?: ReactNode;
}) {
    return (
        <header className="border-b border-border">
            <div className="mx-auto max-w-7xl px-6 py-16">
                <p className="font-mono text-xs tracking-widest text-primary uppercase">
                    {eyebrow}
                </p>

                <h1 className="mt-4 max-w-3xl text-4xl font-bold tracking-tighter text-balance text-foreground uppercase sm:text-5xl">
                    {title}
                </h1>

                {lede && (
                    <p className="mt-6 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                        {lede}
                    </p>
                )}

                {meta && (
                    <div className="mt-8 flex flex-wrap items-center gap-x-6 gap-y-2 font-mono text-xs tracking-widest text-muted-foreground uppercase">
                        {meta}
                    </div>
                )}

                {actions && (
                    <div className="mt-8 flex flex-wrap items-center gap-4">
                        {actions}
                    </div>
                )}
            </div>
        </header>
    );
}
