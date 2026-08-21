import {
    IdentityBlock,
    LegalPage,
    MailLink,
} from '@/components/marketing/legal-page';
import type { LegalSection } from '@/components/marketing/legal-page';
import { privacy, withdrawalForm } from '@/routes';
import { edit as editBilling } from '@/routes/billing';
import type { LegalIdentity, LegalRevision } from '@/types/legal';

type Props = {
    identity: LegalIdentity;
    updatedAt: LegalRevision;
};

/**
 * The contract a pilot enters into by using DroneVerse.
 *
 * Written for a consumer audience in the EU/EEA, which sets most of what is
 * here and the order it comes in: identify the trader before the sale, state
 * the price and the renewal in the same breath, give the withdrawal right its
 * own section, and never write a term that reads as though it takes back a
 * statutory one.
 *
 * Three things that are conspicuously absent are absent on purpose.
 *
 * There is no link to the EU Online Dispute Resolution platform: Regulation
 * (EU) 2024/3228 repealed its legal basis and the platform was switched off on
 * 20 July 2025, so the link every older policy carries now points at nothing
 * and pointing a consumer at it is itself misleading.
 *
 * There is no clause asking the pilot to waive the right of withdrawal on
 * immediate access — see the withdrawal section for why that waiver would not
 * hold here.
 *
 * And there are no model withdrawal form blanks on the page. Withdrawing and
 * cancelling are handled by a person reading an email, so a field marked
 * "Signature of consumer(s)" in the middle of the terms would be theatre — a
 * hurdle dressed as a service. Article 6(1)(h) does expect the Annex I(B) form
 * to be made available, so it is, as a PDF one link away from the sentence
 * saying nobody has to use it. See App\Actions\BuildWithdrawalForm.
 */
export default function Terms({ identity, updatedAt }: Props) {
    const support = identity.supportEmail;

    const sections: LegalSection[] = [
        {
            id: 'who-we-are',
            title: 'Who you are dealing with',
            body: (
                <>
                    <p>
                        DroneVerse is operated by the company named below. These
                        terms are the agreement between you and us for your use
                        of the DroneVerse website, flight simulator, courses and
                        related services (together, <strong>the service</strong>
                        ). By creating an account you accept them.
                    </p>
                    <IdentityBlock identity={identity} email={support} />
                    <p>
                        We write to you in English and these terms are concluded
                        in English. If you are a consumer, nothing in this
                        document takes away rights you have under the law of the
                        country you live in — where the two disagree, your local
                        consumer law wins.
                    </p>
                </>
            ),
        },
        {
            id: 'what-droneverse-is',
            title: 'What the service is, and what it is not',
            body: (
                <>
                    <p>
                        DroneVerse teaches drone programming through a simulator
                        that runs in your browser. You write JavaScript, a
                        simulated aircraft flies it against a physics model, and
                        missions are scored on the result. Courses, knowledge
                        checks, the leaderboard and your photo log are all part
                        of that.
                    </p>
                    <p>
                        <strong>
                            Everything you fly here is a simulation.
                        </strong>{' '}
                        DroneVerse is not flight training, it is not a course of
                        instruction recognised by any aviation authority, and
                        completing it grants no licence, certificate,
                        qualification or competency of any kind. In the European
                        Union, flying a real drone is governed by Regulation
                        (EU) 2019/947 and the national rules made under it,
                        which may require you to register as an operator, hold
                        proof of competency for the category you fly in, and
                        keep to the operational limits that apply where you are.
                        None of that is satisfied by anything on this site. Code
                        that flies cleanly in the simulator says nothing about
                        how the same code would behave on real hardware, and you
                        must never treat it as though it did.
                    </p>
                    <p>
                        We publish new courses, missions and drone models over
                        time and occasionally retire old ones. The catalogue at
                        any moment is what the service is; we do not promise
                        that a particular mission or model stays available
                        forever.
                    </p>
                </>
            ),
        },
        {
            id: 'your-account',
            title: 'Your account',
            body: (
                <>
                    <p>
                        You need an account to fly. You must give us a real name
                        and a working email address, keep them up to date, and
                        keep your credentials to yourself. Accounts are personal
                        — do not share one, and do not let anyone else use
                        yours.
                    </p>
                    <p>
                        You must be at least 16 years old to open an account. If
                        you are younger and the law where you live allows a
                        parent or guardian to consent on your behalf, they must
                        agree to these terms and be responsible for your use of
                        the service.
                    </p>
                    <p>
                        We offer passkeys and two-factor authentication and we
                        recommend turning one of them on. Anything done through
                        your account is treated as done by you, so tell us at{' '}
                        <MailLink email={support} /> as soon as you think
                        someone else has access to it.
                    </p>
                </>
            ),
        },
        {
            id: 'plans-and-payment',
            title: 'Plans, prices and payment',
            body: (
                <>
                    <p>
                        Starter is free and stays free. Pro and Team are paid
                        subscriptions billed monthly or yearly. What each plan
                        includes, and what it costs, is set out on the pricing
                        page — that page is part of these terms, and the price
                        shown there before you buy is the price you pay.
                    </p>
                    <p>
                        <strong>
                            Payments are handled by Creem, which is the merchant
                            of record for every subscription sold here.
                        </strong>{' '}
                        That means the purchase itself is a contract between you
                        and Creem: they take the payment, they appear on your
                        card statement and on your invoice, and they calculate,
                        collect and remit any VAT or sales tax owed on the sale.
                        Their terms and privacy notice apply to that part of the
                        transaction alongside these terms. We never see or store
                        your card details. You can still raise anything about a
                        payment with us at <MailLink email={support} /> and we
                        will take it up with them.
                    </p>
                    <p>
                        Subscriptions renew automatically at the end of each
                        billing period, at the price then in effect, until you
                        cancel. If we change the price of a plan you are already
                        on, we will tell you by email at least 30 days before it
                        applies to you, and you are free to cancel before then;
                        a price rise never takes effect on a period you have
                        already paid for.
                    </p>
                    <p>
                        Changing plan — upgrading, downgrading, or moving
                        between monthly and yearly — adjusts the subscription
                        you already hold rather than starting a second one, and
                        the difference is settled on your next renewal. You can
                        see and manage all of this from your{' '}
                        <a href={editBilling.url()}>billing settings</a>.
                    </p>
                </>
            ),
        },
        {
            id: 'right-of-withdrawal',
            title: 'Your right to withdraw within 14 days',
            body: (
                <>
                    <p>
                        If you are a consumer in the EU or EEA, you have the
                        right to withdraw from a paid subscription within{' '}
                        <strong>14 days</strong>, without giving any reason. The
                        period runs from the day the contract is concluded —
                        which is the day you subscribe, including where the
                        subscription opens with a free or discounted period.
                    </p>
                    <p>
                        <strong>We do not ask you to waive this right.</strong>{' '}
                        DroneVerse is a digital service rather than a fixed
                        piece of digital content: it adapts to you, tracking
                        your progress, ranking you against other pilots and
                        putting different missions in front of you as you go. In
                        Sky Österreich Fernsehen (C-234/25, 9 July 2026) the
                        Court of Justice held that an offering of that dynamic
                        kind cannot rely on the digital-content exception in the
                        Consumer Rights Directive, so starting to use the
                        service immediately does not cost you the right to
                        withdraw from it. You get access straight away and you
                        keep the 14 days.
                    </p>
                    <p>
                        <strong>How to withdraw.</strong> Write to{' '}
                        <MailLink email={support} /> and say you want to
                        withdraw.{' '}
                        <strong>
                            There is no form to fill in and no template to
                            follow
                        </strong>{' '}
                        — say it in your own words, in any way that is clear,
                        and a person will read it and deal with it. We do not
                        ask you for a reason, we do not put you through a
                        retention flow, and we will not send you back to a
                        screen to do it yourself. Sending your notice before the
                        14 days are up is all that is required; we acknowledge
                        it without delay and confirm when it is done.
                    </p>
                    <p>
                        <strong>What you get back.</strong> We refund every
                        payment received from you within 14 days of being told,
                        using the same means of payment you used, at no charge
                        to you. Where you asked us to begin during the
                        withdrawal period and you have used the service in the
                        meantime, we may keep an amount in proportion to what
                        you used before withdrawing, measured against the full
                        price of the subscription. Refunds are issued through
                        Creem as merchant of record.
                    </p>
                    <div className="border border-primary bg-primary/5 p-6">
                        <h3>What we need from you</h3>
                        <p className="mt-4">
                            Your name or the email address on the account, and
                            the fact that you are withdrawing. That is the whole
                            list. You do not need an order number, a date, a
                            signature or a reason, and nothing is refused for
                            being written the wrong way.
                        </p>
                        <p className="mt-4">
                            If you would rather use a form, the standard EU
                            model withdrawal form is here as a{' '}
                            <a href={withdrawalForm.url()}>
                                one-page PDF to download
                            </a>
                            . It is offered because the law expects us to offer
                            it — you are never obliged to use it, and sending it
                            gets your withdrawal handled no faster than an email
                            would.
                        </p>
                    </div>
                </>
            ),
        },
        {
            id: 'cancelling',
            title: 'Cancelling after that',
            body: (
                <>
                    <p>
                        You can cancel a subscription at any time, with no
                        notice period and no cancellation fee. There is one
                        button for it in your{' '}
                        <a href={editBilling.url()}>billing settings</a>, and if
                        you would rather not hunt for it,{' '}
                        <strong>
                            write to <MailLink email={support} /> and a person
                            will cancel it for you
                        </strong>{' '}
                        — no form, no questionnaire, no reason required, and
                        nobody trying to talk you out of it.
                    </p>
                    <p>
                        Cancelling stops the next renewal; you keep everything
                        your plan includes until the end of the period you have
                        already paid for, and then the account drops to Starter.
                    </p>
                    <p>
                        Dropping to Starter changes which missions you can fly.
                        It does not delete your progress, your scores or your
                        photo log — those stay on your account, and come back
                        with you if you subscribe again. Deleting the account
                        itself is a separate action and is described in our{' '}
                        <a href={privacy.url()}>privacy policy</a>.
                    </p>
                </>
            ),
        },
        {
            id: 'acceptable-use',
            title: 'How you may use the service',
            body: (
                <>
                    <p>
                        Fly, write code, break your own missions, and learn.
                        What you may not do:
                    </p>
                    <ul>
                        <li>
                            Attack the service or the sandbox your code runs in
                            — probing for vulnerabilities, escaping the sandbox,
                            attempting to reach other pilots' data, or
                            interfering with anyone else's use of it.
                        </li>
                        <li>
                            Automate access at a scale we have not agreed to:
                            scraping the catalogue, hammering endpoints, or
                            working around the rate limits.
                        </li>
                        <li>
                            Resell, redistribute or publish our course material,
                            mission definitions, reference solutions or drone
                            models, or use them to build a competing product.
                        </li>
                        <li>
                            Upload or label anything unlawful, infringing,
                            abusive or that you have no right to share, or use a
                            display name that would be offensive on the
                            leaderboard.
                        </li>
                        <li>
                            Misrepresent what DroneVerse is — in particular,
                            presenting anything from here as aviation training,
                            certification or evidence of competence to fly a
                            real aircraft.
                        </li>
                    </ul>
                </>
            ),
        },
        {
            id: 'your-work-and-ours',
            title: 'Your work and our work',
            body: (
                <>
                    <p>
                        <strong>Yours stays yours.</strong> The code you write,
                        the flights you record and the photos your simulated
                        drone takes belong to you. You give us only the
                        permission we need to run the service — to store your
                        work, execute and score it, show it back to you, and
                        show your display name and score on the leaderboard to
                        other signed-in pilots. That permission ends when you
                        delete the content or your account, except for backups
                        still working their way out of our systems.
                    </p>
                    <p>
                        <strong>Ours stays ours.</strong> The simulator, the
                        physics model, the courses, missions, quizzes, drone
                        models, and the DroneVerse name and marks are ours or
                        our licensors'. Your subscription is a personal,
                        non-transferable licence to use them for your own
                        learning for as long as it lasts — it transfers nothing
                        else.
                    </p>
                </>
            ),
        },
        {
            id: 'availability',
            title: 'Availability and changes to the service',
            body: (
                <>
                    <p>
                        We work to keep DroneVerse running and reasonably fast,
                        but we do not promise uninterrupted availability.
                        Maintenance, third-party outages and faults happen. When
                        an interruption is planned and significant we will give
                        notice where we reasonably can.
                    </p>
                    <p>
                        We keep developing the service, and features change. If
                        we make a change that significantly and negatively
                        affects your access to or use of what you are paying
                        for, we will tell you at least 30 days ahead and you may
                        end your subscription and receive a proportionate refund
                        of the unused part of what you have paid.
                    </p>
                </>
            ),
        },
        {
            id: 'if-something-is-wrong',
            title: 'If the service is faulty',
            body: (
                <>
                    <p>
                        As a consumer you are entitled to a service that
                        conforms to what was described and promised. Under
                        Directive (EU) 2019/770 on digital content and digital
                        services, if what we supply does not conform, you can
                        require us to bring it into conformity free of charge
                        and within a reasonable time. If we cannot, or do not,
                        you may be entitled to a price reduction or to end the
                        contract and be refunded for the part you did not
                        receive.
                    </p>
                    <p>
                        Because this is a subscription supplied continuously, we
                        are answerable for conformity throughout the period it
                        runs. Tell us at <MailLink email={support} /> and
                        describe what went wrong — none of this costs you
                        anything, and none of it depends on you having bought
                        anything extra.
                    </p>
                </>
            ),
        },
        {
            id: 'liability',
            title: 'Our liability',
            body: (
                <>
                    <p>
                        We are liable to you for loss we cause by breaking this
                        contract or by failing to use reasonable care and skill,
                        where that loss was a foreseeable result of what we did.
                    </p>
                    <p>
                        <strong>We never exclude or limit</strong> liability for
                        death or personal injury caused by our negligence, for
                        fraud or fraudulent misrepresentation, or for anything
                        else the law does not permit us to limit — including
                        your rights as a consumer under the Consumer Rights
                        Directive, the Digital Content Directive and your
                        national law implementing them.
                    </p>
                    <p>
                        Otherwise, and to the extent the law allows, we are not
                        liable for loss that was not foreseeable, for loss of
                        profit or business opportunity, or for anything arising
                        out of you flying a real drone. Where a limit is
                        permitted, our total liability for claims arising in any
                        twelve-month period is limited to what you paid us in
                        that period — and to nothing where you are on the free
                        plan and have paid us nothing.
                    </p>
                </>
            ),
        },
        {
            id: 'suspension',
            title: 'Suspension and termination',
            body: (
                <>
                    <p>
                        We may suspend or close an account that breaks these
                        terms, that we are legally required to act against, or
                        that is being used in a way that endangers the service
                        or other pilots. Except where the breach is serious or
                        we are prevented by law, we will warn you first and give
                        you a chance to put it right, and we will tell you the
                        reason.
                    </p>
                    <p>
                        If we close a paid account for a reason that is not your
                        fault, we refund the unused part of what you have paid.
                        You can close your own account at any time from your
                        settings.
                    </p>
                </>
            ),
        },
        {
            id: 'changes-to-terms',
            title: 'Changes to these terms',
            body: (
                <p>
                    We may update these terms — for new features, or because the
                    law changes. For any change that materially affects your
                    rights or obligations we will email you at least 30 days
                    before it takes effect, and you may end your subscription
                    before then without penalty and be refunded for the unused
                    part of what you have paid. Minor corrections take effect
                    when published. The date at the top of this page always
                    tells you which version you are reading.
                </p>
            ),
        },
        {
            id: 'complaints',
            title: 'Complaints and disputes',
            body: (
                <>
                    <p>
                        Start by writing to <MailLink email={support} />. Most
                        things are settled that way, and we would rather settle
                        them that way. We aim to reply within five working days.
                    </p>
                    <p>
                        If we cannot resolve it between us, you may be able to
                        take the matter to an alternative dispute resolution
                        body in your country under Directive 2013/11/EU. We are
                        not obliged to use, and do not commit in advance to
                        using, any particular ADR body. The European Commission
                        closed its Online Dispute Resolution platform on 20 July
                        2025, so there is no EU-wide portal to point you to;
                        consumers in the EU can instead get free help and be
                        directed to the right national body through the European
                        Consumer Centres Network (ECC-Net).
                    </p>
                    <p>
                        None of this affects your right to go to court, and
                        nothing here requires you to try anything else first.
                    </p>
                </>
            ),
        },
        {
            id: 'severability',
            title: 'If part of these terms does not hold',
            body: (
                <p>
                    If a court finds any part of these terms unenforceable, the
                    rest continues to apply.
                </p>
            ),
        },
    ];

    return (
        <LegalPage
            title="Terms and conditions"
            lede="The agreement between you and DroneVerse: what the service is, what a subscription costs, how to withdraw or cancel, and the rights you keep whatever this document says."
            updatedAt={updatedAt}
            sections={sections}
        />
    );
}
