import {
    IdentityBlock,
    LegalPage,
    MailLink,
    Missing,
} from '@/components/marketing/legal-page';
import type { LegalSection } from '@/components/marketing/legal-page';
import { terms } from '@/routes';
import { edit as editProfile } from '@/routes/profile';
import type { LegalIdentity, LegalRevision } from '@/types/legal';

type Props = {
    identity: LegalIdentity;
    updatedAt: LegalRevision;
};

/**
 * What we collect, why, and what a pilot can do about it.
 *
 * Structured against GDPR Articles 13 and 14 rather than against a template:
 * identity and contact details, purposes paired with the lawful basis for
 * each, recipients, transfers out of the EEA and the safeguard used, retention
 * periods, the full list of rights, and the right to complain to a supervisory
 * authority — that last one being, along with lawful basis and the transfer
 * mechanism, what enforcement action reliably checks first.
 *
 * We are established outside the EU and sell to pilots in it, so Article 3(2)
 * applies to us and Article 27 requires an EU representative; that section
 * renders from configuration and only once one is actually appointed.
 *
 * The claims here are meant to stay true as the code changes. Everything
 * described is something this application actually does — the columns it
 * stores, the disks it writes to, the third parties it calls — so a change to
 * any of those is a change to this page.
 */
export default function Privacy({ identity, updatedAt }: Props) {
    const privacyEmail = identity.privacyEmail;

    const sections: LegalSection[] = [
        {
            id: 'who-we-are',
            title: 'Who is responsible for your data',
            body: (
                <>
                    <p>
                        DroneVerse is operated by the company below, which is
                        the <strong>data controller</strong> for the personal
                        data described here — we decide what is collected and
                        why. This policy explains what that means in practice
                        and what you can require of us.
                    </p>
                    <IdentityBlock identity={identity} email={privacyEmail} />
                    <p>
                        We are established outside the European Union. Because
                        we offer this service to people in the EU, the General
                        Data Protection Regulation applies to us under its
                        Article 3(2), and everything in this policy is written
                        on that basis.
                    </p>
                    {identity.euRepresentative !== null ? (
                        <>
                            <h3>Our representative in the EU</h3>
                            <p>
                                Under Article 27 GDPR we have appointed the
                                following representative in the Union. You may
                                contact them on any matter relating to your
                                personal data, instead of or as well as us:
                            </p>
                            <p>
                                <strong>
                                    {identity.euRepresentative.name}
                                </strong>
                                {identity.euRepresentative.address !== null ? (
                                    <>
                                        <br />
                                        {identity.euRepresentative.address}
                                    </>
                                ) : null}
                                {identity.euRepresentative.email !== null ? (
                                    <>
                                        <br />
                                        <MailLink
                                            email={
                                                identity.euRepresentative.email
                                            }
                                        />
                                    </>
                                ) : null}
                            </p>
                        </>
                    ) : (
                        <p>
                            <Missing label="EU representative" /> — Article 27
                            requires a controller in our position to designate
                            one and to publish who they are.
                        </p>
                    )}
                </>
            ),
        },
        {
            id: 'what-we-collect',
            title: 'What we collect',
            body: (
                <>
                    <h3>What you give us</h3>
                    <ul>
                        <li>
                            <strong>Account:</strong> your name, your email
                            address, and your password — stored only as a hash,
                            never in a form we can read.
                        </li>
                        <li>
                            <strong>Sign-in security:</strong> if you enable
                            them, the public key of each passkey you register
                            and the name of the device it lives on, or your
                            two-factor secret and recovery codes.
                        </li>
                        <li>
                            <strong>Anything you write to us</strong>, including
                            support email.
                        </li>
                    </ul>
                    <h3>What you create by flying</h3>
                    <ul>
                        <li>
                            <strong>Your code:</strong> the most recent
                            JavaScript you wrote for each mission, kept so the
                            editor still has it when you come back.
                        </li>
                        <li>
                            <strong>Flight results:</strong> one record per run
                            — score, stars, objectives hit, collisions, elapsed
                            time, whether you landed or timed out, and which
                            drone you flew — plus your best result and attempt
                            count per mission.
                        </li>
                        <li>
                            <strong>Knowledge checks:</strong> your quiz
                            attempts, the answers you chose and the marks they
                            scored.
                        </li>
                        <li>
                            <strong>Photo log:</strong> images captured by the
                            simulated drone's camera, with any label you give
                            them and the position and heading the shot was taken
                            from. These are renders of a simulated scene, not
                            photographs of the real world.
                        </li>
                    </ul>
                    <h3>What we record automatically</h3>
                    <ul>
                        <li>
                            <strong>Session data:</strong> a session record
                            holding your IP address, your browser's user agent
                            string and when you were last active.
                        </li>
                        <li>
                            <strong>Server logs:</strong> requests and errors,
                            which may include an IP address and the page or
                            endpoint involved.
                        </li>
                    </ul>
                    <h3>What we get from payment</h3>
                    <p>
                        When you subscribe, we store the identifiers Lemon
                        Squeezy gives us — your customer and subscription
                        reference, the plan and billing period, its status and
                        renewal date, and the last four digits and brand of the
                        card so your billing page can show them.{' '}
                        <strong>
                            We never receive or store your full card number.
                        </strong>
                    </p>
                    <h3>What is visible to other pilots</h3>
                    <p>
                        The leaderboard shows your display name alongside your
                        points, stars and completed missions to other signed-in
                        pilots. Nothing else on your account — not your email,
                        your code, your photos or your billing details — is
                        shown to anyone else. If you would rather not be
                        identifiable there, change your display name in your{' '}
                        <a href={editProfile.url()}>profile settings</a>.
                    </p>
                </>
            ),
        },
        {
            id: 'why-and-legal-basis',
            title: 'Why we use it, and on what legal basis',
            body: (
                <>
                    <p>
                        Each purpose below is paired with the lawful basis under
                        Article 6 GDPR that we rely on for it. We do not collect
                        special category data, and we ask you for none.
                    </p>
                    <h3>Running your account and the service</h3>
                    <p>
                        Creating your account, authenticating you, saving your
                        code, scoring your flights, tracking progress, storing
                        your photo log and sending you service email such as
                        email verification, password resets and billing notices.{' '}
                        <strong>
                            Basis: performance of a contract, Article 6(1)(b).
                        </strong>
                    </p>
                    <h3>Taking payment and managing subscriptions</h3>
                    <p>
                        Opening a checkout, recording what you are subscribed
                        to, applying it to what you can access, and handling
                        upgrades, cancellations and refunds.{' '}
                        <strong>
                            Basis: performance of a contract, Article 6(1)(b),
                        </strong>{' '}
                        with tax and accounting records kept under{' '}
                        <strong>legal obligation, Article 6(1)(c)</strong> —
                        mostly by Lemon Squeezy, which is the merchant of record
                        for the sale.
                    </p>
                    <h3>Security and preventing abuse</h3>
                    <p>
                        Session records, server logs, rate limits and the checks
                        that stop one account filling the disk or hammering an
                        endpoint.{' '}
                        <strong>
                            Basis: legitimate interests, Article 6(1)(f)
                        </strong>{' '}
                        — keeping the service standing up and other pilots'
                        accounts safe, which we consider does not override your
                        interests given how little is retained and for how short
                        a time.
                    </p>
                    <h3>The leaderboard</h3>
                    <p>
                        Ranking pilots against each other is a described part of
                        what you signed up for.{' '}
                        <strong>
                            Basis: performance of a contract, Article 6(1)(b).
                        </strong>{' '}
                        You control what name appears there.
                    </p>
                    <h3>Improving the courses</h3>
                    <p>
                        Looking at which missions are failed, retried or
                        abandoned so we can fix the ones that teach badly. We do
                        this in aggregate.{' '}
                        <strong>
                            Basis: legitimate interests, Article 6(1)(f).
                        </strong>
                    </p>
                    <h3>Marketing</h3>
                    <p>
                        We do not send marketing email unless you have asked for
                        it, and if you have, you can stop it at any time from
                        the message itself or by writing to us.{' '}
                        <strong>Basis: consent, Article 6(1)(a),</strong>{' '}
                        withdrawable at any time without affecting what was sent
                        before.
                    </p>
                </>
            ),
        },
        {
            id: 'cookies',
            title: 'Cookies',
            body: (
                <>
                    <p>
                        <strong>
                            We set no advertising cookies and run no third-party
                            analytics or tracking scripts.
                        </strong>{' '}
                        There is nothing here that follows you between sites,
                        which is why you are not being asked to dismiss a
                        consent banner.
                    </p>
                    <p>The cookies we do set are the ones the site needs:</p>
                    <ul>
                        <li>
                            A <strong>session cookie</strong> that keeps you
                            signed in.
                        </li>
                        <li>
                            A <strong>CSRF token cookie</strong> that stops
                            other sites submitting forms as you.
                        </li>
                        <li>
                            A <strong>remember-me cookie</strong>, only if you
                            ask to stay signed in.
                        </li>
                        <li>
                            Your <strong>light or dark appearance</strong>{' '}
                            preference, kept in your own browser.
                        </li>
                    </ul>
                    <p>
                        These are strictly necessary to provide a service you
                        have expressly requested, and are exempt from the
                        consent requirement in Article 5(3) of the ePrivacy
                        Directive. When you open a checkout, Lemon Squeezy loads
                        its own script and may set its own cookies as merchant
                        of record; that is covered by their policy.
                    </p>
                </>
            ),
        },
        {
            id: 'who-we-share-with',
            title: 'Who else sees it',
            body: (
                <>
                    <p>
                        <strong>We do not sell your personal data</strong> and
                        we do not share it for anyone else's marketing. It is
                        disclosed only to the providers that make the service
                        work, each under a contract that binds them to process
                        it only on our instructions:
                    </p>
                    <ul>
                        <li>
                            <strong>Hosting and database</strong> — the
                            infrastructure the application and its database run
                            on.
                        </li>
                        <li>
                            <strong>Object storage</strong> — where your photo
                            log is written. Those files are private and are
                            served only through links that expire after 30
                            minutes.
                        </li>
                        <li>
                            <strong>Email delivery</strong> — for verification,
                            password reset and billing messages.
                        </li>
                        <li>
                            <strong>Lemon Squeezy</strong> — which processes
                            payments and, as merchant of record, is the seller
                            for your subscription. For that sale it acts as its
                            own controller and applies its own privacy notice,
                            not this one.
                        </li>
                    </ul>
                    <p>
                        We may also disclose data where the law requires it, or
                        to establish or defend a legal claim. If our business is
                        ever transferred, your data may move with it — you would
                        be told first, and this policy would continue to apply
                        until you were told otherwise.
                    </p>
                </>
            ),
        },
        {
            id: 'international-transfers',
            title: 'Where your data goes',
            body: (
                <>
                    <p>
                        Our systems, and those of the providers listed above,
                        are located in{' '}
                        {identity.hostingRegion ?? (
                            <Missing label="hosting region" />
                        )}
                        . If you are in the EU or EEA, that means your personal
                        data is transferred outside it.
                    </p>
                    <p>
                        We make those transfers under Chapter V GDPR: to
                        countries the European Commission has decided offer
                        adequate protection where one applies, and otherwise
                        under the Commission's{' '}
                        <strong>Standard Contractual Clauses</strong>, together
                        with the technical measures that back them up —
                        encryption in transit, encryption at rest, access
                        limited to the people who need it. You can ask us for a
                        copy of the safeguards used for any particular transfer
                        by writing to <MailLink email={privacyEmail} />.
                    </p>
                </>
            ),
        },
        {
            id: 'retention',
            title: 'How long we keep it',
            body: (
                <>
                    <ul>
                        <li>
                            <strong>Your account and everything on it</strong> —
                            your code, runs, quiz attempts and photo log — for
                            as long as the account exists. Deleting the account
                            deletes them.
                        </li>
                        <li>
                            <strong>Session records</strong> — expired after two
                            hours of inactivity and cleared on sign-out.
                        </li>
                        <li>
                            <strong>Server logs</strong> — 14 days, then
                            deleted.
                        </li>
                        <li>
                            <strong>Billing and tax records</strong> — kept for
                            as long as tax law requires, which is typically
                            between six and ten years depending on the country.
                            Most of this is held by Lemon Squeezy as merchant of
                            record.
                        </li>
                        <li>
                            <strong>Support email</strong> — up to two years
                            after the matter is closed.
                        </li>
                    </ul>
                    <p>
                        Backups are kept on a rolling cycle and are overwritten
                        in the ordinary course, so deleted data can persist in a
                        backup for a short period after it has gone from the
                        live systems. It is not restored into use.
                    </p>
                </>
            ),
        },
        {
            id: 'your-rights',
            title: 'Your rights',
            body: (
                <>
                    <p>Under the GDPR you have the right to:</p>
                    <ul>
                        <li>
                            <strong>Access</strong> the personal data we hold
                            about you, and get a copy of it.
                        </li>
                        <li>
                            <strong>Rectify</strong> anything inaccurate or
                            incomplete — your name and email you can change
                            yourself in your{' '}
                            <a href={editProfile.url()}>profile settings</a>.
                        </li>
                        <li>
                            <strong>Erase</strong> your data. Deleting your
                            account from the same settings page removes it and
                            everything attached to it.
                        </li>
                        <li>
                            <strong>Restrict</strong> our processing while a
                            dispute about accuracy or legitimate interests is
                            worked out.
                        </li>
                        <li>
                            <strong>Port</strong> the data you gave us and the
                            data you generated, in a structured,
                            machine-readable format, and have it sent to another
                            controller where technically feasible.
                        </li>
                        <li>
                            <strong>Object</strong> to processing we base on
                            legitimate interests, on grounds relating to your
                            situation.
                        </li>
                        <li>
                            <strong>Withdraw consent</strong> at any time where
                            we rely on it, without affecting what was done
                            before you withdrew it.
                        </li>
                    </ul>
                    <p>
                        Write to <MailLink email={privacyEmail} /> to exercise
                        any of these. We answer within one month, and we will
                        tell you if we need to extend that by up to two further
                        months because the request is complex. It costs you
                        nothing. We may need to confirm who you are before
                        acting, which is a protection for you rather than an
                        obstacle.
                    </p>
                </>
            ),
        },
        {
            id: 'automated-decisions',
            title: 'Automated processing',
            body: (
                <p>
                    Your flights and quizzes are scored automatically — that is
                    what the simulator does, and the result decides your stars,
                    your progress and your place on the leaderboard. It does not
                    produce legal effects concerning you or similarly
                    significantly affect you, so it is not automated
                    decision-making of the kind Article 22 GDPR restricts. We do
                    not profile you for advertising, and we make no automated
                    decisions about your access to the service beyond applying
                    the plan you are on. If you think a score is wrong, write to
                    us and a person will look at it.
                </p>
            ),
        },
        {
            id: 'children',
            title: 'Children',
            body: (
                <p>
                    DroneVerse is not intended for children under 16, and you
                    must be at least 16 to hold an account — see our{' '}
                    <a href={terms.url()}>terms and conditions</a>. We do not
                    knowingly collect personal data from anyone under that age.
                    If you believe a child has given us their data, write to{' '}
                    <MailLink email={privacyEmail} /> and we will delete it.
                </p>
            ),
        },
        {
            id: 'security',
            title: 'How we protect it',
            body: (
                <>
                    <p>
                        Passwords are stored hashed and are never recoverable in
                        readable form. Traffic is encrypted in transit. Passkeys
                        and two-factor authentication are available on every
                        account. Your photo log is written to private storage
                        and served only through signed links that stop working
                        after 30 minutes. Access to production data is limited
                        to the people who need it to run the service.
                    </p>
                    <p>
                        No system is perfect. If a breach ever puts your rights
                        at risk, we will notify the competent supervisory
                        authority within 72 hours as Article 33 requires, and
                        tell you directly where Article 34 requires that too.
                    </p>
                </>
            ),
        },
        {
            id: 'complaints',
            title: 'Complaining',
            body: (
                <>
                    <p>
                        Come to us first at <MailLink email={privacyEmail} /> —
                        it is usually the quickest way to fix something.
                    </p>
                    <p>
                        You also have the right to lodge a complaint with a data
                        protection supervisory authority, and you do not have to
                        come to us first to do it. If you are in the EU or EEA,
                        that is the authority in the country where you live,
                        where you work, or where you think the problem happened.
                        The European Data Protection Board publishes the list of
                        national authorities and their contact details at{' '}
                        <a
                            href="https://www.edpb.europa.eu/about-edpb/about-edpb/members_en"
                            rel="noreferrer"
                            target="_blank"
                        >
                            edpb.europa.eu
                        </a>
                        .
                    </p>
                </>
            ),
        },
        {
            id: 'changes',
            title: 'Changes to this policy',
            body: (
                <p>
                    We update this policy when what we do with data changes. The
                    date at the top always tells you which version you are
                    reading. If a change materially affects your rights, we will
                    tell you by email before it takes effect rather than leaving
                    you to notice a new date.
                </p>
            ),
        },
    ];

    return (
        <LegalPage
            title="Privacy policy"
            lede="What DroneVerse collects, why we are allowed to, who else sees it, how long we keep it, and the rights you can exercise over it under the GDPR."
            updatedAt={updatedAt}
            sections={sections}
        />
    );
}
