# The Models, and What Each One Stores

A plain description of every model in the application and the data it holds. The reasoning
behind the overall shape of the schema is in project-report.md, section 7, and the diagram
is in erd.svg.

There are fifteen models in the application's own model directory, three of which mirror
the payment provider's records locally. One more comes from a package — the passkey model
from Laravel Fortify — but its table is created by a migration kept in this repository, so
it is described here as well.

## Some things that are true everywhere

No model declares fillable or guarded properties, because the application unguards models
globally; mass-assignable columns are named with an attribute on the class instead, and a
second attribute hides a column from serialization.

Nothing is addressed by its database id in a URL. Authored content — courses, missions,
quizzes — is reached by slug, because those URLs are the public, shareable, indexable
surface of the product and a readable name is worth more there than an opaque one. Anything
a pilot owns is reached by a UUID instead, so that a pilot's own URL is not a running count
of every row the system holds. Those models keep their ordinary auto-incrementing key as
the primary key, because that is what all the foreign keys point at; the UUID is a second,
public identifier alongside it.

Every table records when its rows were created and last changed, with two exceptions. The
two append-only tables — one for graded flights, one for graded quiz submissions — record
only a creation time, because nothing ever revises a graded result. The absence of the
column is the statement.

Deleting a pilot or a piece of content cascades to everything hanging off it. There are
exactly two exceptions, both concerning which drone was flown, explained under the fleet
below.

---

## Identity

**User: this stores account information for the pilot.**

The name, which is what appears throughout the app and on the leaderboard, and the email
address, which is both how they log in and where notifications go. Alongside the address is
the moment it was verified, which is blank for an unverified account and is deliberately
cleared again whenever a pilot changes their email — costing them access to verified-only
routes until they confirm the new one.

The password is stored hashed and never serialized. Next to it sits a plan override: a plan
name written by hand for comped accounts, staff, academic discounts, and the pre-launch
users who were backfilled to Pro when gating was introduced. It outranks billing entirely
when the application works out what a pilot is entitled to, because an override exists
precisely to say something billing does not know. It is hidden from serialization and is
not mass-assignable — it is set deliberately or not at all.

Two-factor authentication contributes three values: an encrypted secret, an encrypted set
of recovery codes, and the moment the setup was actually confirmed. That last one matters
on its own, because a secret without a confirmation is a setup somebody started and walked
away from. All three are hidden, as is the remember-me token. Only name, email and password
are mass-assignable.

What a user row notably does not store is the pilot's plan. That is resolved fresh on every
request — first from the override, then from any still-valid subscription, and finally
falling back to Starter, since everyone with an account has a plan. The answer is
remembered on the model instance for the life of the request, so there is no cached copy of
an entitlement anywhere that can go stale.

Two tables are created alongside users and have no model of their own: one holding password
reset tokens keyed by email address, and one holding session payloads with the originating
IP address, user agent and last activity time.

**Passkey: this stores one registered sign-in device for a pilot.**

The name the pilot gave the device, the credential handle the authenticator issued, the
serialized public-key credential itself, and the last time that key was successfully used —
which is what lets a pilot tell one of their keys apart from another when they come to
remove one.

---

## The catalogue

This is the authored, read-mostly half of the application. Nothing in this group knows what
a plan actually is beyond the plan name it happens to store; entitlement points into the
catalogue and never out of it.

**Course: this stores one unit of learning — a set of missions and, usually, a knowledge
check.**

A title, a slug that serves as its URL, a description used as catalogue-card copy, a
free-form difficulty label shown as a badge, an ordering number that places it in the
catalogue, and a published flag that decides whether it appears at all.

It also stores the tier its content belongs to. That value is the default its missions and
quizzes inherit and the badge shown on its card, but it is emphatically not page access:
the course page stays open to everyone, because a Starter pilot reading the briefings for
missions they cannot yet fly is the whole conversion argument. A value that no longer names
a real plan degrades to Starter rather than throwing — the catalogue should fail open.

A course exposes its missions and its quizzes, both in author order. Quizzes are a has-many
rather than a has-one, so a course can grow a mid-course check alongside a final one
without widening the relation and every caller of it.

**Challenge: this stores one mission — the briefing, the code a pilot starts from, the
world they fly in, and the rules it is graded by.**

It belongs to a course and holds a title, a slug, the briefing the pilot reads, its
position within the course, a difficulty badge and a published flag. The slug is unique
only within its course, so two courses may both have a mission called first-flight without
colliding.

Three pieces of content make the mission flyable. The starter code is what the editor opens
with. The environment is the world itself — geometry, obstacles, waypoints — stored as a
single JSON blob because the simulator reads the whole thing at once and nothing ever
queries across it. The success criteria, also JSON, are what the grader checks the
resulting flight against. A maximum score rounds it out.

There is also an optional reference solution. It is hidden from serialization and released
only through a single method that requires either that the pilot has completed the mission
or that they have made at least three attempts — the escape hatch for someone who is stuck
rather than done. A mission with no authored solution never unlocks one, so callers can key
off that one answer.

The tier a mission requires is stored, but it is usually blank, and blank means "whatever
my course says". That is the common case by a wide margin and it is what keeps a course and
its missions from quietly drifting apart. Storing a value is the deliberate exception: it
is how a browsable Starter course can hold missions that are all Pro.

Two behaviours are worth knowing. Every route carrying a course-and-mission pair asks one
question — that both ends are published and that the mission genuinely belongs to the
course named in the URL — so a mismatched pair is indistinguishable from content that does
not exist. And the method that works out the required tier takes the course as an argument
rather than reading it back off the relation, because every caller already has the course
to hand and reaching for it here would fire a query per row on a course page.

**Quiz: this stores one knowledge check attached to a course.**

A mission asks whether a pilot can make the drone do something; a quiz asks whether they
understood why. The row holds a title, a slug unique within its course, a description, an
ordering number, a published flag, and the same optional tier a mission stores, meaning the
same thing.

The one field with no counterpart elsewhere is the pass mark. It is stored per quiz rather
than in configuration, so a harder quiz may demand more without re-grading every other one.
And it is a percentage rather than a raw count of questions, so adding a question to a
published quiz does not move the bar underneath the pilots who already passed it. The
comparison against that bar is made in exactly one place, so the grader, the progress merge
and any future report all round the same way.

**Quiz question: this stores one question and the authority on what answers it.**

The prompt, its position in the quiz, and its type — either a question with one correct
answer or one with several. The type is stored per question rather than per quiz, so a
single quiz can mix a straight recall question with one that has two right answers. It
decides both how the answer set is graded and which input widget renders, which is not a
cosmetic distinction: a pilot shown radio buttons on a two-answer question cannot pass it.

There is also an optional explanation. This is the teaching half of the feature, and it
travels to the browser only after a submission has been graded — the resource that builds
the quiz page leaves it out, because an explanation of why an answer is right gives the
answer away as surely as the answer key does.

What a question does not store is a weight. Every question on a quiz is worth the same, and
the pass mark being a percentage of questions answered correctly means no weighting is
needed to be fair and no migration is needed to stay honest when a question is added.

Grading is all-or-nothing on both types: the set of options a pilot chose must match the
set marked correct exactly, ignoring the order they were ticked in and any duplicates,
since neither says anything about what the pilot knows. There is no partial credit for
finding one of two right answers, because partial credit rewards ticking everything — the
one answering strategy a knowledge check exists to rule out. A question where nobody marked
an answer correct can never be earned rather than being free to everyone, so an authoring
mistake costs no one a point.

**Quiz option: this stores one selectable answer, and whether it is a correct one.**

The answer text, its position, and the correctness flag.

That flag is the answer key, and it gets the same belt-and-braces treatment the mission
solution does. The resource that builds the quiz page ships only an option's id and its
label, and the flag is additionally hidden at the model level so no other path — a stray
array conversion, a debug dump, an eager-loaded relation serialized by accident — can leak
it either. The browser is never trusted with a result, so it must not be handed the means
to compute one. A question with more than one option marked correct is what makes that
question a multiple-answer question.

---

## The fleet

**Drone model: this stores one airframe a pilot can choose to fly.**

These rows are seeded catalogue content rather than user data: nothing in the running
application creates one, and the seeder matches on slug so re-running it revises the fleet
in place instead of duplicating it.

The row holds a name, a class, and a one-line summary — the single sentence a pilot reads
before committing to an airframe. The class is a shorthand for the trade the drone makes:
trainer, inspection, racing, cargo or endurance. It exists so the picker can group and
badge a fleet that will grow, and it is explicitly not something the catalogue gates on —
every drone is flyable by any pilot who can reach the picker at all.

Two JSON blobs carry the substance, and the split between them is not cosmetic. The flight
spec is the performance envelope the physics control loop reads: cruise, minimum and
maximum speeds, climb and descent rates, horizontal, vertical and braking acceleration, yaw
rate and yaw acceleration, maximum tilt, spool-up time, resting height, and two battery
drain figures. This is what makes one drone fly differently from another. The airframe spec
is the geometry the visible mesh is built from: rotor count, body radius, motor reach,
propeller and blade dimensions, leg length and reach, maximum rotor spin speed, and the two
colours it is painted in.

They are JSON for the same reason the mission environment is. Each is read whole by exactly
one consumer, nothing filters or sorts on an individual constant, and splitting twenty-four
numbers into twenty-four columns would buy a query shape no caller has while making the
addition of a single constant a migration instead of a seeder edit. Their exact shapes are
pinned in the model's docblock and mirrored in the TypeScript types, and that shape is the
contract between the two halves.

One number belongs to both worlds: the resting height. The physics parks the drone's body
centre at it when landed, and the landing legs have to reach exactly that far down, or the
drone appears to hover above its pad or sink through it. It lives in the flight spec, and
the geometry derives from it.

A flag marks exactly one row as the fleet default — the airframe every pilot flies unless
they have chosen otherwise, which is most pilots, since the picker is a Pro capability. If
no row carries it, the application fails loudly rather than guessing: a mission page that
quietly picked the lowest id would fly a pilot in something nobody chose. There is also an
ordering number, and the fleet is always listed by that order with the id breaking ties, so
two drones sharing a position do not swap places between requests and leave the pilot with
a picker they cannot learn.

---

## Progress

Progress is stored as two pairs of models with the same shape, for the same reason in both
cases. The progress row is a pilot's standing, and it only ever improves. The attempt row
is what actually happened on one submission, including the ones that went badly, and none
of that survives the merge into the standing.

**User challenge progress: this stores a pilot's standing on one mission.**

One row per pilot per mission, and the pair is unique. That uniqueness constraint does
three jobs at once: it is the correctness rule, it is the index the hot path reads by, and
it is what makes recording safe under a double-submitted form — two racing submissions
collide on the constraint and then serialize on the same row.

The row holds the status (not started, in progress, or completed), the best score, the best
star rating, the number of attempts made, and when the mission was completed. Score and
stars only ever rise; the completion time is set once. The attempt count does double duty
as what unlocks the reference solution at three.

It also holds the pilot's most recent code, as a draft buffer restored when they come back
to the mission. This is the only copy of pilot-written code the schema keeps anywhere.

Finally it holds the airframe the pilot has chosen for this mission. This is their standing
choice: it is what the cockpit restores, and it is the only place the server will read a
drone from when grading, so a submission cannot name its own airframe. It is blank until
they choose one, which is the permanent state for every pilot whose plan does not reach the
picker, and blank is read as the fleet default rather than as an error.

The mission reference carries an index of its own, separate from the unique constraint,
because that constraint leads with the pilot and therefore cannot serve the leaderboard's
lookup by mission.

**Challenge run: this stores one graded flight, exactly as it was scored.**

Kept forever, never revised. Every question analytics exists to answer is a question about
these rows: how a score moved between the first attempt and the tenth, how many tries a
mission actually takes, whether the collisions are coming down. None of that survives the
merge into the progress row, which is why the runs are kept separately.

A run holds its score and stars, whether it counted as completed, how many objectives were
hit out of how many there were, how many collisions occurred, how long the flight took in
seconds, whether the drone landed, and whether it timed out. It also records which airframe
flew it, which is blank for runs flown before the fleet existed and for runs whose drone
has since been retired. Both cases mean the same thing to a reader — the airframe of the
day — and neither is worth losing the run over.

That is why the airframe reference nulls out rather than cascading, both here and on the
progress row. Retiring a drone from the fleet must not delete a pilot's progress or erase
flights from the analytics record.

The pilot's code is deliberately not stored per run. It runs to tens of kilobytes, it is
already kept once on the progress row, and this is the fastest-growing table in the schema.
The numbers a run is worth fit in a narrow row.

Two indexes serve it. One covers a single pilot's attempt curve on a single mission, and
the read never has to leave the index. The other leads with the mission alone, for the
cohort aggregates that compare one pilot's best against everyone's and therefore name no
pilot at all. Both order by the primary key rather than the creation time, which keeps the
sort inside the index and still separates two runs submitted in the same second.

**User quiz progress: this stores a pilot's standing on one quiz.**

One row per pilot per quiz, unique on the pair. It holds the best score, the number of
attempts, and when the quiz was passed.

The score is a percentage, matching the quiz's pass mark, so the two are comparable without
knowing how many questions the quiz held when the score was set. Like mission progress it
is monotonic: the best score only rises and the pass time is set once and never cleared.
Retakes are unlimited, which is what makes that guarantee necessary rather than merely kind
— a pilot reopening a quiz to review the questions after passing must not be punished for
it.

The status is not stored. It is worked out from the two values already present: a recorded
pass time means passed, otherwise any attempts at all mean attempted, otherwise not
started. Deriving it avoids keeping a copy that can disagree with what it came from. Note
which value is the authority on a pass — the recorded pass time, not the best score
measured against the current pass mark, because measuring it that way would silently revoke
a pass the moment an author raised the bar.

**Quiz attempt: this stores one graded submission, exactly as it was scored.**

Kept forever, never revised, standing to quiz progress exactly as a run stands to mission
progress. It holds the score as a percentage, the raw counts behind it — how many questions
were answered correctly and how many the quiz held at the time of submission — and whether
the attempt passed.

The answers the pilot actually chose are deliberately not stored. Per-option answer history
is a far wider and faster-growing table than this one, it is only worth building once
somebody is genuinely asking which wrong answer pilots fall for, and it would carry the
same eventual pruning problem the flight runs already owe. The counts here are what a
pilot's history reads.

It carries the same two indexes as the flight runs, for the same reasons: one pilot's
submission history on one quiz, and pass rates across every pilot on one quiz.

---

## Media

**Drone photo: this stores one image the drone's camera captured mid-flight.**

It is the one pilot-owned resource the application addresses directly by URL. The row
records who took it and on which mission, an optional caption, where the file lives on the
photo disk, and where the drone was when it was taken — three coordinates and a heading,
stored as JSON.

It is addressed by a UUID. Ownership was always enforced by a policy, so nobody could ever
reach a photo that was not theirs, but the sequential id in a pilot's own URL still told
them how many photos every pilot before them had taken. That was volume being handed out
for nothing in return.

What the row does not store is a URL. One is minted on demand, signed and valid for thirty
minutes — long enough to outlast browsing a page of the log, short enough that a link which
escapes into a shared screenshot, a referrer header or a synced browser history stops
working while it is still an inconvenience rather than a leak. A permanent link would be an
unguarded copy of a private photo that keeps working long after the pilot deleted it from
their log or their access to the mission lapsed. Which disk the file lives on comes from
configuration, resolved in one place, so the code writing a file, the code deleting one and
the code building the link can never disagree about where the log is.

---

## Billing

These three models mirror what Creem holds, kept in step by the webhook and read by every
screen that shows a pilot their billing. Creem is the record and these are the copy, so
nothing in the application writes one except the actions the webhook drives — which is what
lets the whole billing page render without a network call, in an environment with no
credentials at all. They are reached through the billable trait on the user, and they are
polymorphic rather than keyed to users so that a future classroom can be billed too.

**Customer: this stores the link between an account and its customer record at the payment
provider.**

The polymorphic owner — always a user here — the provider's customer identifier and the
email it was created with. Written by the webhook rather than by checkout, because Creem
names the customer when the payment succeeds. It outlives every subscription the pilot ever
holds, which is what makes it the right thing to mint a billing-portal link against:
somebody whose subscription ended last month still has invoices to download.

**Subscription: this stores what a pilot is currently paying for.**

The owner, a name allowing more than one subscription per account, the provider's
identifier, its customer, and a status — active, trialing, scheduled to cancel, past due,
unpaid, incomplete, paused, expired or cancelled.

The important value is the product, which identifies the exact thing being sold. A Creem
product carries its own price and billing period, so one product is one purchasable price:
there is no separate price object and, unlike the Paddle integration two providers ago, no
subscription-items table. The question "which price is this pilot subscribed to?" is one
column and no join, and entitlement resolution maps that value back to a plan through
configuration.

A unit count sits beside it for the classroom seats that are not built yet, since Creem
bills seats as a quantity against one product rather than as a second subscription.

The rest is dates, and they are stored as the provider reports them rather than reduced to
a single "ends" column, because which one ends a subscription depends on how it is ending:
when a trial ends, when the next charge falls, when the current period started and ends,
and when somebody cancelled. The model derives the one date a page actually shows from
those. There is no card brand and no last four, because Creem publishes neither — the
billing portal is the only place a pilot sees the card being charged.

**Order: this stores one completed purchase, and together they are the billing history.**

Each row holds who paid, the provider's identifiers for the order, the checkout, the
customer and the subscription it belongs to, what was bought as a product, and the money:
an amount in minor units and its currency. It also records the order's status, whether and
when it was refunded and for how much — Creem allows partial refunds, so the amount matters
as well as the flag — and when the order was placed, which is distinct from when the row
was written.

There is no receipt URL, because Creem publishes none: invoices live behind the customer
portal, reached by a magic link minted per request, so the billing page links to the portal
once rather than carrying a document link per row.

---

## A note on the stored strings

Several fields store a plain string that the application reads back as a typed value, and
in each case the enum is the authority on what the string may be. Mission progress stores a
status of not started, in progress or completed. A quiz question stores a type of single or
multiple. A drone stores one of the five classes. And four fields — the tier on a course, a
mission and a quiz, plus the override on a user — store a plan name of starter, pro, team
or enterprise.

The plan fields are read leniently: a value that no longer names a real plan falls through
rather than throwing, because plan resolution runs on every request and a stale override
should cost a pilot their upgrade, not the whole page.

Two enums back no stored value at all. One describes the capabilities a plan unlocks, each
registered as a permission rather than stored anywhere. The other describes the three
possible outcomes of asking to move a subscription onto another plan, and exists only as a
return value.
