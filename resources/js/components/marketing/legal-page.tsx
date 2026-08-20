import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import {
    MarketingPageHeader,
    MarketingShell,
} from '@/components/marketing/marketing-shell';
import type { LegalIdentity, LegalRevision } from '@/types/legal';

export type LegalSection = {
    /** Doubles as the anchor a link from elsewhere can point at. */
    id: string;
    title: string;
    body: ReactNode;
};

/**
 * An email address as a link, or a visible admission that we did not publish
 * one.
 *
 * The gap is deliberate and it is the safer of the two failures. A page that
 * quietly drops the sentence reads as complete while leaving a pilot with a
 * stated right and no way to exercise it; this reads as unfinished to the
 * pilot and to whoever deploys it, which is what it is.
 */
export function MailLink({ email }: { email: string | null }) {
    if (email === null) {
        return <Missing label="contact address" />;
    }

    return (
        <a href={`mailto:${email}`} className="text-primary underline">
            {email}
        </a>
    );
}

/**
 * A value the environment never supplied, marked rather than invented.
 *
 * Set in the same accent the rest of the marketing pages label things in,
 * rather than in the destructive red no other public page uses. The words are
 * what carry the alarm — an operator reading "[contact address not
 * configured]" on their own terms page does not need it in red to act on it,
 * and a visitor should not be met with an error colour on a legal document.
 */
export function Missing({ label }: { label: string }) {
    return (
        <span className="font-mono text-xs tracking-widest text-primary uppercase">
            [{label} not configured]
        </span>
    );
}

/**
 * The trader and controller identity block, repeated at the top of both
 * documents.
 *
 * Rows appear only when they are set, so an unconfigured deployment publishes
 * a short block rather than a fabricated address — with the exception of the
 * contact line, which always renders because a document that names no way to
 * reach us fails the disclosure it exists to satisfy.
 */
export function IdentityBlock({
    identity,
    email,
}: {
    identity: LegalIdentity;
    email: string | null;
}) {
    const rows = [
        { label: 'Operator', value: identity.name },
        { label: 'Registered address', value: identity.address },
        { label: 'Country of establishment', value: identity.country },
        { label: 'Registration', value: identity.registration },
    ].filter((row) => row.value !== null);

    return (
        <dl className="grid gap-x-8 gap-y-3 border border-border bg-white/[0.02] p-6 sm:grid-cols-[max-content_1fr]">
            {rows.map((row) => (
                <div key={row.label} className="contents">
                    <dt className="font-mono text-xs tracking-widest text-primary uppercase">
                        {row.label}
                    </dt>
                    <dd className="text-sm text-foreground">{row.value}</dd>
                </div>
            ))}
            <div className="contents">
                <dt className="font-mono text-xs tracking-widest text-primary uppercase">
                    Email
                </dt>
                <dd className="text-sm text-foreground">
                    <MailLink email={email} />
                </dd>
            </div>
        </dl>
    );
}

/**
 * The shared frame for /terms and /privacy.
 *
 * Sections are passed as data rather than as children so the contents list and
 * the document itself are generated from one array: a numbered list of links
 * that has fallen out of step with the headings it points at is a footgun on
 * a page whose sections get cited by number.
 *
 * The prose styling is applied through descendant selectors on the wrapper
 * instead of a class on every element, which keeps the page files readable as
 * what they mostly are — long-form copy that a lawyer may need to amend
 * without touching Tailwind.
 */
export function LegalPage({
    title,
    lede,
    updatedAt,
    sections,
}: {
    title: string;
    lede: string;
    updatedAt: LegalRevision;
    sections: LegalSection[];
}) {
    return (
        <>
            <Head title={title}>
                <meta name="description" content={lede} />
            </Head>

            <MarketingShell>
                <MarketingPageHeader
                    eyebrow="Legal"
                    title={title}
                    lede={lede}
                    meta={
                        <span>
                            In effect since{' '}
                            <time dateTime={updatedAt.iso}>
                                {updatedAt.label}
                            </time>
                        </span>
                    }
                />

                <div className="mx-auto grid max-w-7xl gap-12 px-6 py-16 lg:grid-cols-[16rem_1fr] lg:gap-16">
                    <nav
                        aria-label="On this page"
                        className="lg:sticky lg:top-24 lg:self-start"
                    >
                        <h2 className="font-mono text-xs tracking-widest text-primary uppercase">
                            On this page
                        </h2>
                        <ol className="mt-4 space-y-2">
                            {sections.map((section, index) => (
                                <li
                                    key={section.id}
                                    className="flex gap-3 text-sm"
                                >
                                    <span className="font-mono text-xs text-muted-foreground tabular-nums">
                                        {String(index + 1).padStart(2, '0')}
                                    </span>
                                    <a
                                        href={`#${section.id}`}
                                        className="text-muted-foreground transition-colors hover:text-primary"
                                    >
                                        {section.title}
                                    </a>
                                </li>
                            ))}
                        </ol>
                    </nav>

                    <article className="max-w-3xl space-y-14">
                        {sections.map((section, index) => (
                            <section
                                key={section.id}
                                id={section.id}
                                className="scroll-mt-24"
                            >
                                <h2 className="flex gap-4 text-lg font-bold tracking-tighter text-foreground uppercase">
                                    <span className="font-mono text-sm text-primary tabular-nums">
                                        {String(index + 1).padStart(2, '0')}
                                    </span>
                                    {section.title}
                                </h2>
                                <div className="mt-5 space-y-4 border-l border-border pl-4 text-sm leading-relaxed text-muted-foreground sm:pl-6 [&_a]:text-primary [&_a]:underline [&_dt]:font-mono [&_dt]:text-xs [&_dt]:tracking-widest [&_dt]:text-primary [&_dt]:uppercase [&_h3]:mt-6 [&_h3]:font-mono [&_h3]:text-xs [&_h3]:tracking-widest [&_h3]:text-foreground [&_h3]:uppercase [&_li]:mt-2 [&_strong]:font-semibold [&_strong]:text-foreground [&_ul]:list-disc [&_ul]:pl-5">
                                    {section.body}
                                </div>
                            </section>
                        ))}
                    </article>
                </div>
            </MarketingShell>
        </>
    );
}
