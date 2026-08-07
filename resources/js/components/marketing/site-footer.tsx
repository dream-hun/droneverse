import { Link, usePage } from '@inertiajs/react';
import { Wordmark } from '@/components/marketing/site-header';
import { dashboard, leaderboard, login, pricing, register } from '@/routes';
import { index as coursesIndex, show as showCourse } from '@/routes/courses';
import { edit as editProfile } from '@/routes/profile';
import type { MarketingCourse } from '@/types/simulator';

/**
 * The public site footer.
 *
 * The catalog column names real courses, so it follows whatever is published
 * rather than a hand-kept list; pages that do not carry the catalog fall back
 * to the index link alone. The account column follows the header, showing
 * sign-up prompts to a guest and the pilot's own settings to everyone else.
 */
export function SiteFooter({ courses = [] }: { courses?: MarketingCourse[] }) {
    const { auth } = usePage().props;

    const groups = [
        {
            heading: 'Flight school',
            links: [
                { label: 'All courses', href: coursesIndex() },
                ...courses.slice(0, 3).map((course) => ({
                    label: course.title,
                    href: showCourse(course.slug),
                })),
                { label: 'Pricing', href: pricing() },
            ],
        },
        {
            heading: 'Cockpit',
            links: [
                { label: 'Dashboard', href: dashboard() },
                { label: 'Leaderboard', href: leaderboard() },
            ],
        },
        {
            heading: 'Account',
            links: auth.user
                ? [{ label: 'Profile settings', href: editProfile() }]
                : [
                      { label: 'Log in', href: login() },
                      { label: 'Create an account', href: register() },
                  ],
        },
    ];

    return (
        <footer className="border-t border-border bg-background">
            <div className="mx-auto grid max-w-7xl gap-10 px-6 py-14 md:grid-cols-4">
                <div>
                    <Wordmark />
                    <p className="mt-4 max-w-xs text-sm leading-relaxed text-muted-foreground">
                        Drone programming you can actually fly. Written in
                        JavaScript, graded on real physics.
                    </p>
                </div>

                {groups.map((group) => (
                    <div key={group.heading}>
                        <h4 className="font-mono text-xs tracking-widest text-primary uppercase">
                            {group.heading}
                        </h4>
                        <ul className="mt-4 space-y-2 text-sm text-muted-foreground">
                            {group.links.map((link) => (
                                <li key={link.label}>
                                    <Link
                                        href={link.href}
                                        className="transition-colors hover:text-primary"
                                    >
                                        {link.label}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>

            <div className="border-t border-border">
                <div className="mx-auto max-w-7xl px-6 py-8 font-mono text-xs tracking-widest text-muted-foreground uppercase">
                    <p>© {new Date().getFullYear()} DroneVerse.</p>
                </div>
            </div>
        </footer>
    );
}
