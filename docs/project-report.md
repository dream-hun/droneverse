# DroneVerse: A Browser-Based Simulation Environment for Learning Programmatic Drone Control

**A technical report on system design, assessment integrity, and scalability**

---

## Abstract

DroneVerse is a web application in which learners write programs that fly a simulated
multirotor drone through authored missions and are graded on the resulting flight. The
system occupies an unusual position in the design space of educational software: the
artefact being assessed — a physical trajectory through a three-dimensional world — is
produced by a rigid-body physics simulation that, for reasons of cost and interactivity,
must execute on the learner's own machine, inside the very environment the learner
controls. The central design problem is therefore one of *assessment integrity under
client-side execution*: how a server may issue a trustworthy grade for work it did not
witness.

This report documents the resulting architecture. It describes a five-context domain
model implemented as a single Laravel application with a React client bound by Inertia;
a simulation subsystem that executes learner code in an isolated Web Worker against a
Rapier physics body; and a two-stage assessment pipeline in which the client's own grade
is treated as a disposable preview while the authoritative result is reconstructed
server-side from the submitted trajectory and the mission's own geometry. It further
documents the entitlement model that separates catalogue depth from capability, the
caching strategy behind the ranked leaderboard together with an analytical account of its
scaling limit, and the verification strategy — 365 automated test methods across 34 files
— that holds the two independent implementations of the scoring policy in agreement.

The report concludes that the architecture's principal virtue is economic: because
simulation is displaced to the client, the marginal server cost of a flight is one graded
HTTP request, and the system's scaling limit is set not by simulation but by the
invalidation policy of a single cached read model.

**Keywords:** educational technology, physics simulation, client-side execution,
assessment integrity, server-authoritative grading, sandboxing, cache invalidation

---

## Table of Contents

1. [Introduction](#1-introduction)
2. [Requirements](#2-requirements)
3. [Architectural Overview](#3-architectural-overview)
4. [The Simulation Subsystem](#4-the-simulation-subsystem)
5. [The Assessment Model](#5-the-assessment-model)
6. [Knowledge Assessment](#6-knowledge-assessment)
7. [The Data Model](#7-the-data-model)
8. [Entitlement and the Commercial Model](#8-entitlement-and-the-commercial-model)
9. [Performance and Scalability](#9-performance-and-scalability)
10. [Security Considerations](#10-security-considerations)
11. [Verification and Validation](#11-verification-and-validation)
12. [Limitations and Threats to Validity](#12-limitations-and-threats-to-validity)
13. [Future Work](#13-future-work)
14. [Conclusion](#14-conclusion)
- [Glossary](#glossary)
- [Appendix A: Repository Map](#appendix-a-repository-map)
- [Appendix B: HTTP Surface](#appendix-b-http-surface)
- [Appendix C: Source References](#appendix-c-source-references)

---

## 1. Introduction

### 1.1 Motivation

Introductory programming instruction has long relied on *microworlds* — constrained,
visually legible environments in which the effect of a program is immediately observable.
The pedagogical argument is well established: a learner who can see a program's
consequence forms a mental model of execution faster than one reasoning about abstract
state.

Drone control is an attractive contemporary microworld. It is intrinsically spatial, so
program errors manifest as visible physical failures rather than as stack traces; it is
sequential and imperative in a way that maps cleanly onto introductory language
constructs; and it carries obvious vocational relevance. The obstacle is apparatus. A
physical drone is expensive, fragile, hazardous indoors, and impossible to distribute at
the scale at which introductory courses operate.

Simulation removes the apparatus constraint but introduces a new one. If the simulation
runs on a server, every learner's flight consumes server compute for its entire duration,
and a platform's cost scales with the number of *seconds flown* rather than the number of
learners enrolled. If the simulation runs in the browser, cost collapses — but so does
the server's evidentiary basis for grading, because the machine that produced the result
is under the learner's control.

DroneVerse resolves this tension by separating *simulation* from *adjudication*. The
browser simulates; the server adjudicates. The learner's machine is trusted to report
where the drone went, and is trusted with nothing else.

### 1.2 Aims and Objectives

The system was built to satisfy five objectives:

1. **Interactive fidelity.** A learner's program must drive a drone whose behaviour is
   recognisably that of a real GPS multirotor — acceleration limits, braking curves,
   position hold, wind disturbance — rather than a kinematic point moving along a line.
2. **Assessment integrity.** A grade recorded against a learner must reflect flying that
   the submitted evidence can account for, notwithstanding that the flight itself occurred
   in an environment the learner controls.
3. **Isolation of learner code.** Arbitrary learner-authored JavaScript must execute
   without access to the page, the session, or the network.
4. **Economic scalability.** The marginal server cost of a flight must be bounded and
   small, independent of flight duration.
5. **Commercial viability.** Access must be sellable as a subscription without the
   entitlement logic contaminating the content model.

### 1.3 Scope and Delimitations

This report documents the system as implemented. It describes the browser client, the
application server, the persistence layer, and the third-party billing integration. It
does not address the pedagogical *content* of the courses (their sequencing, difficulty
calibration, or learning outcomes), nor does it report empirical evaluation with human
learners; no such study has been conducted, and §12 treats the absence of one as a
threat to validity rather than as an omission from scope.

The account given here is drawn from the implementation itself rather than from prior design
documentation. Where the system falls short of its own stated intentions, §12 records the
shortfall rather than resolving it in the system's favour.

### 1.4 Structure of This Document

§2 states requirements. §3 gives the architectural overview. §4 and §5 treat the two
technically substantive subsystems — simulation and assessment — in depth; a reader with
limited time should read §5, which contains the system's principal contribution. §6–§8
cover knowledge assessment, persistence, and entitlement. §9–§11 address scalability,
security, and verification. §12–§13 state limitations and future work.

---

## 2. Requirements

### 2.1 Functional Requirements

| ID | Requirement |
| --- | --- |
| F1 | A visitor may browse the course catalogue and read every mission briefing without an account. |
| F2 | An authenticated learner may author a program in the browser and execute it against a simulated drone. |
| F3 | The system shall report a score, a star rating, and a completion determination for each submitted flight. |
| F4 | Progress shall accumulate per learner per mission, and shall never regress. |
| F5 | The system shall maintain a global and per-course ranking of learners. |
| F6 | A learner may capture images from the drone's camera during flight and review them subsequently. |
| F7 | A learner on an eligible tier may select among airframes with differing flight envelopes. |
| F8 | The system shall administer multiple-choice knowledge assessments per course and grade them server-side. |
| F9 | The system shall sell tiered subscriptions and grant access accordingly. |
| F10 | The system shall support password, two-factor (TOTP), and passkey (WebAuthn) authentication. |

### 2.2 Non-Functional Requirements

| ID | Requirement | Realisation |
| --- | --- | --- |
| N1 | Learner code shall not reach the DOM, the session, or the network. | Dedicated Web Worker with network APIs made non-configurable and undefined (§4.1). |
| N2 | A grade shall not be assertable by the client. | Server-side reconstruction from trajectory (§5). |
| N3 | Marginal server cost per flight shall be O(1) in flight duration. | Client-side simulation; one graded POST per run (§9.1). |
| N4 | Assessment answer keys shall not reach the client before grading. | Hand-built resources plus model-level serialisation hiding (§6.2). |
| N5 | Concurrent submissions shall not lose an update. | `firstOrCreate` outside the transaction, pessimistic row lock within (§7.4). |
| N6 | Learner-uploaded media shall not be publicly addressable. | Private disk, expiring URLs (§10.4). |
| N7 | The billing webhook shall fail closed on misconfiguration. | Unconditional signature verification (§10.3). |
| N8 | Every change shall be covered by an automated test. | 365 test methods; enforced by project convention (§11). |

---

## 3. Architectural Overview

### 3.1 Architectural Style

The system is a **modular monolith** with a **server-driven single-page client**. There
is no separate API tier. Server state reaches the browser as *page properties* delivered
through Inertia.js, which allows React page components to be rendered client-side while
routing, authorisation, and data fetching remain conventional server-side concerns.

This choice has three consequences worth stating explicitly, since each is a trade
accepted rather than a benefit obtained for free:

1. **There is no serialisation contract to version.** Page property shapes may change
   freely with the pages that consume them, because no third party depends on them. The
   corollary is that opening a public API later must not reuse these shapes; §13 records
   the required separation.
2. **Authorisation occurs where data is fetched**, not in a separate gateway. A controller
   that loads a mission is the same controller that checks entitlement to it.
3. **There is no client-side data store to keep synchronised.** State that would otherwise
   require client-side caching and invalidation is simply re-fetched with the page.

A second, deliberately small **JSON surface** exists for interactions that occur *within*
a page without navigation — principally flight submission and photograph upload — because
the learner must remain in the simulator while these occur.

### 3.2 Bounded Contexts

The domain separates into five contexts. These are not deployed independently; they are
the seams along which the code is organised, and the seams along which it would be split
were that ever necessary.

| Context | Responsibility | Owns | Principal entry points |
| --- | --- | --- | --- |
| **Identity** | Authentication, credentials, second factors | `users`, `passkeys`, two-factor columns | Fortify routes, `routes/settings.php` |
| **Catalogue** | Authored content: courses, missions, quizzes | `courses`, `challenges`, `quizzes`, `quiz_questions`, `quiz_options` | `routes/courses.php` (read) |
| **Flight** | Reconstruction and grading of a run | *nothing* — stateless | `POST …/attempts` |
| **Progress** | What a learner has earned | `user_challenge_progress`, `challenge_runs`, `user_quiz_progress`, `quiz_attempts` | `RecordChallengeAttempt`, `RecordQuizAttempt`, `Queries\Leaderboard`, `Queries\FlightLog` |
| **Billing** | Subscriptions and entitlement | `lemon_squeezy_*`, `users.plan_override` | `routes/billing.php`, webhook |

The **Flight** context is notable for owning no persistent state at all. Grading is a pure
function of a submitted trajectory and an authored mission; it writes nothing and reads
nothing beyond its arguments. This is what makes the assessment logic exhaustively
testable without fixtures, and what would make it trivially extractable as a service.

### 3.3 The Dependency Rule

One directional constraint governs the whole design:

> **Entitlement points *into* the catalogue; the catalogue never points out.**

A `Plan` knows nothing about a `Course`. A `Course` declares which plan is required to
reach it, in a plain string column, and knows nothing about what a plan *is*. Consequently
a new commercial tier may ship without touching any content, and new content may ship
without touching billing. The inverse arrangement — plans enumerating the content they
unlock — would couple every content addition to the pricing model.

### 3.4 Layering and the Action Pattern

Within the server application, logic is distributed according to an explicit rule set
rather than by convention alone:

| If the logic… | It belongs in | Example |
| --- | --- | --- |
| changes state and could be invoked from HTTP, a queue, a command, or a test | `app/Actions` | `RecordChallengeAttempt` |
| reads and aggregates across many rows under its own caching policy | `app/Queries` | `Leaderboard` |
| answers a question about a single row from that row's own columns | the model | `Challenge::isPlayableIn()` |
| answers a question about a user's entitlements | `app/Concerns/HasPlan` | `$user->hasFeature()` |
| shapes or validates an inbound payload | `app/Http/Requests` | `StoreChallengeAttemptRequest` |
| shapes an outbound payload | `app/Http/Resources` | `ChallengeDetailResource` |

**Actions** are single-method, constructor-injected, immutable classes. The pattern's
value here is not stylistic: it makes the write-side logic independent of its invocation
context, so that grading a run may be exercised in a unit test with no HTTP request, no
session, and no authenticated user. Controllers are correspondingly thin — permitted only
to resolve route bindings, assert playability and entitlement, invoke actions, and render.

**Query objects** are separated from models for a reason worth stating, because the
separation superficially resembles duplication. `UserChallengeProgress` is a row a learner
owns and writes. `Queries\Leaderboard` is a cached, ranked projection over *all* such
rows, with its own caching policy, lock tuning, and hand-written SQL. The two change for
different reasons and at different rates. The generalised rule is: *an aggregate read over
many rows that requires its own caching policy is a query object, not a static method on
the entity.*

### 3.5 Client Structure

The React client is organised by role rather than by feature:

- `pages/` — one component per route, receiving server-rendered properties;
- `layouts/` — application, authentication, and settings shells;
- `components/` — primitives, simulator components, marketing components;
- `lib/simulator/` — the flight engine: physics, telemetry, grading, worker protocol;
- `actions/`, `routes/` — **generated**, typed callers for every named server route.

The generated route callers are load-bearing rather than convenient. Client code never
writes a URL by hand, with the consequence that renaming a server route produces a
TypeScript compilation error rather than a runtime 404 discovered in production.

---

## 4. The Simulation Subsystem

The simulator is the one genuinely heavy client subsystem, and the only part of the
application whose cost is borne entirely by the learner's machine.

### 4.1 Execution Model and Isolation

Learner-authored code is arbitrary JavaScript. It executes in a **dedicated Web Worker**,
which yields three properties by construction:

1. **No DOM and no `window`.** The worker global scope exposes neither, so learner code
   cannot read the page, the session cookie, or any credential rendered into it.
2. **No main-thread blocking.** A learner program containing an unterminated loop degrades
   only its own worker; the interface, the render loop, and the stop control remain
   responsive.
3. **No network egress.** Workers natively expose `fetch`, `XMLHttpRequest`, `WebSocket`,
   and `EventSource`. Each is redefined as `undefined` with `configurable: false` and
   `writable: false` *before* any learner code is evaluated.

The third property warrants a caveat that the implementation itself states: this is a
**guardrail, not a security boundary**. Learner programs run only in their own author's
browser, so the threat model is accidental misuse and casual experimentation rather than
a determined attacker attacking themselves. No claim of sandbox escape resistance is made
or required.

### 4.2 The Command Protocol

The worker does not manipulate the drone. It *requests*, and the request is satisfied only
when the physics loop has actually carried it out. Every `drone.*` call is:

1. assigned a monotonically increasing identifier;
2. posted to the main thread as a `{ kind: 'command', id, command }` message;
3. held as an unresolved promise in a pending-command map;
4. resolved only when the main thread returns `{ kind: 'commandResult', id, result }`.

The consequence is that a learner writing `await drone.moveTo(x, y, z)` is genuinely
suspended until the airframe has arrived, settled, and stabilised. The program's control
flow and the drone's physical state cannot diverge.

The command vocabulary comprises fifteen operations spanning actuation (`takeoff`, `land`,
`moveForward`, `moveTo`, `turn`, `hover`, `setAltitude`, `setSpeed`), sensing
(`getPosition`, `getHeading`, `getAltitude`, `getBattery`, `getDistanceAhead`, `scan`),
and capture (`takePhoto`). The `scan` operation returns contacts sorted nearest-first,
each carrying a kind, a position, a three-dimensional distance, and a bearing relative to
the drone's own heading — sufficient for reactive obstacle-avoidance exercises without
exposing the scene graph.

### 4.3 The Flight Model

The drone is a genuine dynamic rigid body in the Rapier physics engine: it has mass and
damping, and its contacts with obstacles and the ground are physically resolved. It is
commanded through the abstraction a real flight controller offers a pilot — *velocity
setpoints* — and the fidelity of the result derives from how those setpoints are produced
each frame:

- **Slew-limited commanded velocity.** Horizontal and vertical acceleration caps convert
  every movement into a trapezoidal accelerate–cruise–brake profile.
- **Braking-curve approach.** Approach speed follows `v = √(2ad)`, the profile real
  controllers use to arrive at a waypoint without overshoot.
- **Procedural takeoff and landing.** Takeoff spools the motors before lift; landing
  flares to a slow final descent below a fixed altitude before touchdown.
- **Independent yaw dynamics.** Yaw has its own rate and acceleration limits, and the
  airframe position-holds while turning.
- **Gust disturbance.** A zero-mean gust field perturbs the body continuously while the
  position loop corrects, reproducing the micro-wander characteristic of a real drone in
  position hold. The *mean* wind component is modelled as already cancelled by the flight
  controller's wind estimator, which is why only gusts are applied — a modelling decision,
  not an omission.

A command completes only when the drone is both inside a position tolerance *and* has bled
off its speed, so it settles rather than passing through a target at cruise.

### 4.4 Airframe Parameterisation

The performance envelope is not in the physics module. Every cap and acceleration is a
field of a `DroneFlightSpec` transmitted by the server for the airframe the learner
selected, carried on the control state for the duration of a run. What remains in the
module is the **control law** — the shape of an approach, of a landing flare, of a
position hold — which is invariant across airframes.

The design intent is precise: two drones fly differently *because they are given different
numbers*, not because they are flown by different code. Five airframes are seeded, grouped
into five classes (trainer, inspection, racing, cargo, endurance), each trading some
property for another. Airframe class is descriptive, not gated: every drone is flyable by
any learner to whom the drone-configuration capability is open.

Critically, the airframe used for a run is **not read from the submission**. It is
resolved server-side from the learner's persisted selection. A run carrying its own
declared airframe would allow any client to claim a racing envelope on a mission actually
flown with a trainer — and the attribution exists precisely so that the analytics record
can be trusted about which airframe produced which performance curve.

### 4.5 Telemetry and Media

Telemetry is sampled off the frame loop at approximately 20 Hz, yielding a sequence of
`(t, x, y, z)` samples. Because sampling is tied to the render loop, the inter-sample
interval is the frame time; a browser rendering slowly produces a sparser path, which §5.3
shows is accommodated by the validation bounds rather than penalised.

Photographs captured during flight are queued client-side and uploaded strictly one at a
time. The rationale is load-shaping: a mission may fire the shutter several times in quick
succession, each frame being a megabyte or more, and dispatching them concurrently is the
worst case for both the learner's uplink and the receiving server. Serialising them costs
nothing, because the flight never waits on the network in either arrangement. A rejected
upload settles the queue so that subsequent uploads still proceed, while the originating
caller still observes its own rejection.

---

## 5. The Assessment Model

This section documents the system's principal design contribution.

### 5.1 The Problem

The browser is the only component that *can* simulate the flight. The drone is a Rapier
rigid body under continuous gust disturbance; re-executing the same program server-side
would not reproduce the same trajectory, and would in any case reintroduce exactly the
per-second server cost that client-side simulation exists to avoid.

But the browser must not be permitted to *assert* its own result. If it could, the
platform's entire assessment record would be a collection of client-supplied claims.

### 5.2 The Two-Grader Arrangement

The system therefore implements the scoring policy **twice**, in two languages, with two
different standings:

| | Client grader | Server grader |
| --- | --- | --- |
| Location | `resources/js/lib/simulator/grader.ts` | `app/Actions/GradeSimulatorRun.php` |
| Input | telemetry the browser just produced | telemetry *reconstructed* from the submission |
| Runs when | the instant the drone lands | on receipt of the submission |
| Status | **preview** — displayed, never stored | **authoritative** — stored, ranked, reported |

The client grader exists solely for latency: a learner sees a result the moment they land,
rather than after a network round trip. Its output is discarded. The server grader decides
what the run was worth.

The obvious hazard of duplicated policy is drift — two implementations diverging, so that
learners watch a displayed score change after the fact. This is mitigated by treating the
weights as a shared contract and pinning the cases most likely to diverge in the
feature-test suite (§11.3).

### 5.3 The Trust Boundary

The submission schema is the boundary, and its most important property is negative: it
**does not accept a score, a star count, or a claim of completion**. It accepts:

```
{ code: string,
  path: Array<{ t, x, y, z }>,   // ≤ 4000 samples
  collisions: integer,            // ≥ 0
  photos: Array<{ x, y, z }> }    // ≤ 60
```

Validation of the path is performed by a **single closure over the whole array** rather
than by wildcard field rules. This is a performance decision with a measured basis: a
4 000-sample path validated with `path.*.x`-style rules expands to roughly 16 000
attributes, each carrying rule objects resolved through the validator's message and
attribute machinery, and rebuilt on every retrieval of the validated set — measured at
2.5 seconds of CPU and 10 MB of memory, to guard geometry costing under 4 milliseconds.
One closure over the array costs approximately 1 millisecond.

Beyond type and range checks, the closure enforces that the path is a *recording*:

- samples must be **strictly ordered in time**;
- consecutive samples must be separated by at most **1.0 second**.

The second bound deserves comment. The sampler emits every 0.05 s, and slower only when
the browser itself is rendering slower. A one-second gap therefore represents a run
limping along at one frame per second — roughly twenty times worse than the worst honest
submission — and is still accepted. What it *does* exclude is a path with no cadence at
all: a submission must carry roughly one sample per second of the flight it claims, which
disqualifies the handful of points that would otherwise suffice to sit on every waypoint.

### 5.4 Reconstruction from Trajectory

The reconstruction stage measures what the submitted flight *achieved*, against the
mission's own authored geometry. Nothing in the submission asserts an achievement; each is
derived.

**Waypoint acquisition** is measured against the *segments* of the path rather than its
sampled points. Because the path arrives at a fixed sample rate, a drone crossing a small
waypoint at cruise speed can straddle it between two consecutive samples; testing closest
approach along each segment finds crossings that a point-wise test would miss and would
wrongly fail an honest learner for. Waypoints must be acquired in authored order.

**Elapsed time** is floored by a kinematic cost of the submitted path. Each leg is costed
at the flight envelope, the horizontal and vertical components being flown together so the
slower dominates:

```
t̂ = max( t_reported ,  Σ over legs  max( ‖Δx,Δz‖ / 8.5 ,  |Δy| / 3.5 ) )
```

The constants are the envelope maxima plus the largest gust the wind field can contribute,
deliberately set above what learners actually reach — the fastest of the twenty authored
missions peaks at 6.3 m/s horizontally and 3.2 m/s vertically — so the floor can never
exceed an honest run's own clock.

**Collisions** are floored analogously. Contact resolution lives in the physics engine, so
a claim of zero collisions cannot be fully disproved server-side. What *can* be established
is whether the polyline provably penetrates solid geometry: each obstacle the path passes
through is at least one unreported strike. This is a segment-versus-oriented-box test using
the slab method, with an intentionally generous margin subtracted from each obstacle's
half-extents so that only unambiguous intrusions register.

**Photograph corroboration** ties the one claim with no geometry of its own back to the
path. A camera mission with no waypoints could otherwise be cleared by posting the target
coordinates and nothing else. Each claimed photograph must have been taken within 2 metres
of some point the drone demonstrably occupied. Across the twenty authored missions no
genuine photograph lands further than 6 mm from a path sample — the residue is floating-point
rounding — so the tolerance is a formality for anyone who flew, and excludes only
photographs taken where the drone never was.

**Traversal objectives** (the wash tunnel) are evaluated by rotating each sample into the
obstacle's local frame and confirming the path was inside the opening at *both* ends,
mirroring the two sensor volumes the browser uses.

### 5.5 Computational Cost

Reconstruction is a sweep over the largest object a request carries. Two optimisations keep
it bounded:

1. **The path is unpacked once** into four flat coordinate arrays, after which no geometry
   routine touches the associative form again. This avoids re-walking arrays of associative
   samples once per obstacle, per waypoint, and per photograph.
2. **Obstacles are rejected by bounding box** before any sweep. A mission's obstacles are
   distributed across the map while a flight visits some region of it, so most obstacles are
   settled by a single comparison. The rejection is *exact* rather than heuristic: an
   obstacle whose world-space axis-aligned box misses the path's own bounding box cannot be
   entered by any segment of it. A further per-segment slab rejection settles almost every
   remaining segment before the rotation arithmetic is reached.

### 5.6 The Scoring Function

Let a mission define a waypoint set `W`, a photo-target set `P`, a minimum photo quota `q`, a
wash requirement `ω ∈ {0,1}`, a time limit `T`, an optional minimum altitude `a_min`, a
landing requirement `L ∈ {0,1}`, a collision sensitivity `C ∈ {0,1}`, and a maximum score `M`.

Objectives generalise the waypoint ratio so that camera missions and traversal missions
grade on the same curve as plain navigation runs:

```
O_total = |W| + |P| + 1[q > 0] + ω
O_hit   = w_hit + p_hit + 1[q > 0 ∧ photos_missing = 0] + 1[ω ∧ washed]
ρ       = O_hit / O_total          (ρ = 1 when O_total = 0)
```

The raw score out of 100 is then

```
s = 70·ρ + 20·1[L satisfied] + 10·1[¬timed_out]
s ← max(0, s − 10·k)               when C = 1, k = floored collision count
S = round( clamp(s, 0, 100) · M / 100 )
```

Completion is a conjunction, not a threshold:

```
completed = (ρ = 1) ∧ (max_altitude ≥ a_min) ∧ (L satisfied) ∧ ¬timed_out
```

Stars are earned atop completion — one for finishing, one for a clean flight, one for
beating the clock comfortably:

```
stars = 0                                              if ¬completed
      = min(3, 1 + 1[k = 0] + 1[t̂ ≤ 0.75·T])          otherwise
```

The reconstruction reports both `O_hit` and `O_total` alongside the score, and both are
persisted. The ratio is the only figure that reads identically across mission types, and
storing it allows an attempt history to state what a run *achieved* rather than only what it
was worth.

### 5.7 Adversarial Analysis

The design does not claim forgery is impossible. Because the flight occurs in the browser,
a sufficiently determined client can always describe a plausible one. The claim is weaker
and more useful: **forgery is raised from trivial to approximately as expensive as flying.**

Mission geometry is transmitted to the browser in order to be rendered, so waypoint and
photo-target coordinates are readable from the page. A naive forgery — posting a handful of
samples sitting exactly on each waypoint — is defeated by four independent constraints
acting together:

| Constraint | Effect on the forger |
| --- | --- |
| Sample-cadence bound (§5.3) | The path must be dense, not a list of targets. |
| Kinematic time floor (§5.4) | Covering ground costs the time it would really cost. |
| Obstacle intrusion floor (§5.4) | Cutting through a building is charged as the strike it would be. |
| Photo corroboration (§5.4) | Photographs must lie on the trajectory. |

The residual requirement is a dense, speed-limited, obstacle-free trajectory through the
mission's waypoints in order — which is most of the way to simply flying it.

One property is enforced throughout and is more important than any individual check: **every
server-side adjustment moves the result only against the submitter.** Times are floored
upward, collision counts floored upward, objectives never granted without evidence. It
follows that a check being wrong about an *honest* learner is not possible; the failure mode
of the entire mechanism is leniency toward a forger, never punishment of a pilot.

### 5.8 Recording the Outcome

Persisting a graded run must satisfy two constraints simultaneously: progress must be
monotonic, and concurrent submissions must not lose updates.

The implementation performs `firstOrCreate` for the progress row **outside** the transaction,
then opens a transaction, re-reads the row under a pessimistic lock, merges, writes the
append-only run record, and commits. Placing the insert outside the transaction prevents it
from deadlocking against the row lock; placing the merge inside guarantees serialisation.

Within the merge, `best_score` and `stars` are combined with `max`, and `completed_at` is
set once and never cleared. A poor run can therefore never remove a good one — which is
precisely the property that allows the leaderboard to be correct without consulting history.

---

## 6. Knowledge Assessment

Alongside flight missions, each course may carry multiple-choice knowledge checks. The two
halves of the schema deliberately rhyme: a quiz is to a course what a mission is, and quiz
progress is to a quiz exactly what mission progress is to a mission — one monotonic row the
learner owns, and one append-only row per graded submission.

### 6.1 Scoring

A quiz is scored as a **percentage**, not a point total, and both the pass threshold and the
recorded best score are percentages. This is what permits an author to add a question to a
published quiz without moving the bar underneath learners who have already cleared it. It is
also why questions carry no weighting column: weighting means something only once an author
has a reason to say one question matters more, and a column nobody sets must still be read,
summed, and defended by the grader.

Retakes are unlimited. `passed_at` is set once and never cleared, and is the authority on
whether a learner has passed — deriving that fact from `best_score ≥ pass_percentage` would
silently revoke passes the moment an author raised the threshold.

### 6.2 Protection of the Answer Key

The correct-answer flag is defended twice, on the principle that a single defence against
disclosure of an answer key is insufficient:

1. The payload delivered to the browser is **constructed by hand** in a dedicated resource —
   identifier and label only, nothing else.
2. The column is additionally **hidden at the model level**, so that a stray serialisation, a
   debug dump, or an accidentally eager-loaded relation cannot leak it either.

Question explanations are withheld on the same shape for the same reason: on a well-written
question, explaining why an answer is correct discloses the answer as surely as the flag
does. Both travel only in the post-grading result, when there is nothing left to disclose.

Attempts store counts rather than chosen answers. Per-option answer history is a
substantially wider and faster-growing table, is worth building only once someone is actually
asking which distractor learners fall for, and would bind a learner's history to question
rows that authors replace wholesale on each re-seed.

---

## 7. The Data Model

### 7.1 Entities

Thirteen domain tables, excluding billing and framework infrastructure:

| Group | Tables |
| --- | --- |
| Identity | `users`, `passkeys` |
| Catalogue | `courses`, `challenges`, `quizzes`, `quiz_questions`, `quiz_options` |
| Progress | `user_challenge_progress`, `challenge_runs`, `user_quiz_progress`, `quiz_attempts` |
| Fleet and media | `drone_models`, `drone_photos` |

At the time of writing the seeded corpus comprises 5 courses, 20 missions, 3 quizzes, and 5
airframes.

### 7.2 Selected Design Decisions

**`required_plan` is nullable on missions and non-null on courses.** Null denotes "whatever
my course says" — the common case by a wide margin, and the one that prevents a course's
missions from drifting away from it. A mission stores a value only when it deliberately
departs from its course, which is how a browsable introductory course may contain missions
that are all premium.

**Mission geometry is stored as JSON.** The environment and success criteria are authored
data, read whole by exactly one consumer, and never queried across. Normalising them would
purchase nothing and cost a join per mission load.

**Append-only tables carry no `updated_at`.** `challenge_runs` and `quiz_attempts` record
graded submissions; nothing revises a graded submission, and the absence of the column states
so at the schema level.

**Public content is addressed by slug; learner-owned resources by UUID.** Course and mission
URLs are the product's public, shareable, indexable surface, and a readable slug is worth
more there than an opaque identifier. Learner-owned rows use UUIDs so that a learner's own
URL is not a running count of every row the system holds.

### 7.3 Index Strategy

Progress outgrows every other table by orders of magnitude — it is proportional to
*learners × missions* — so its indexing determines whether ranking remains affordable.

| Index | Serves |
| --- | --- |
| `user_challenge_progress (user_id, challenge_id)` unique | the hot-path lookup during recording; per-learner aggregates |
| `user_challenge_progress (challenge_id)` | the join out to missions in every ranking aggregate. The unique key leads with `user_id` and therefore **cannot** serve a lookup by mission |
| `challenges (course_id, is_published)` | per-course rankings; the published-mission count on every catalogue card |
| `challenge_runs (user_id, challenge_id, id)` | one learner's attempt curve, entirely within the index |
| `challenge_runs (challenge_id)` | cohort aggregates, which name no learner and cannot use the above |
| `quiz_questions (quiz_id, order)` | the whole quiz in author order, for both the page and the grader |
| `quiz_attempts (user_id, quiz_id, id)` | one learner's submission history on one quiz |

Attempt-history indexes lead with the primary key rather than the creation timestamp, so that
two submissions within the same second still order deterministically.

### 7.4 Integrity and Concurrency

Referential integrity is enforced at the database level. All learner- and content-owned
relationships cascade on delete; the two airframe references nullify instead, so that
retiring an airframe from the fleet does not delete the flight history recorded against it.

The `(user_id, challenge_id)` uniqueness constraint on progress is simultaneously the
correctness constraint and the index serving the hot path. It is also what renders the
create-then-lock sequence of §5.8 safe under a double-submitted form: two racing submissions
collide on the constraint, then serialise on the row.

---

## 8. Entitlement and the Commercial Model

### 8.1 Two Orthogonal Axes

Access is governed by two questions that are deliberately *not* collapsed into one:

- **Catalogue depth** — how far into the content library a tier reaches. Expressed as a total
  order over plans, consulted through `Plan::covers()`.
- **Capability** — which functional features a tier unlocks. Expressed as a set of `Feature`
  cases per plan.

A tier may include every mission without including an instructor dashboard, and the model
represents that directly. Collapsing the two would force every capability decision to be
re-expressed as a content decision, or vice versa.

Each `Feature` case is registered as an authorisation ability under its own value. Adding a
gated capability therefore requires adding an enum case and listing it on the relevant plans;
route middleware, programmatic checks, and the client-side feature list all answer identically
with no further wiring.

A `Feature` additionally reports whether the capability behind it has actually *shipped*. This
allows the pricing presentation to distinguish "your plan does not include this" from "nobody
has this yet" — a distinction the system treats as an honesty requirement rather than a
presentational nicety.

### 8.2 Resolution Precedence

A learner's plan is resolved by consulting sources in descending order of authority:

1. **A manual override column.** Exists precisely to express something billing does not know:
   comped, staff, academic, or pre-launch accounts. It therefore outranks the payment provider
   by design. An override naming a plan that no longer exists falls through rather than
   throwing, since resolution runs on every request and a stale override should cost a learner
   their upgrade, not the entire page.
2. **An active subscription**, mapped back through configured variant identifiers. A learner
   may legitimately hold more than one — mid-upgrade, or a personal seat alongside an
   institutional one — so every identifier is considered and the most generous prevails.
3. **The free tier.** Every account has a plan; unauthenticated visitors resolve here too, so
   that callers sharing entitlement state with public views need no null branch.

An unrecognised variant identifier resolves to *nothing*, never to a default. An identifier
the configuration cannot account for is a configuration error, and silently granting a paid
tier for it is worse than granting none. An identifier claimed by two plans receives the same
answer for a stronger reason: returning the first match would resolve entitlement by the
declaration order of enum cases, which no reader would recognise as billing logic and which
would silently misgrant if the declarations were ever reordered.

### 8.3 Memoisation, Not Caching

Resolution is memoised **on the model instance**, not in a cache store. A fresh user object is
hydrated per request, so the memo's lifetime is exactly correct by construction and there is
nothing to invalidate when a subscription changes. This is a deliberate rejection of caching:
entitlement is read on every request, and a stale grant is a revenue problem in one direction
and a support problem in the other.

### 8.4 Enforcement

The resolved plan and its granted capabilities are shared with every rendered page. This is
**a rendering hint and nothing more** — it decides which affordances a learner is shown. Every
gated capability is independently enforced server-side, by route middleware or by an explicit
assertion in the controller. Guards are repeated at write endpoints rather than inherited from
the page that normally precedes them, on the reasoning that a locked mission which still
accepts submissions is not locked; it merely has no link.

Ordering is also load-bearing: playability is asserted *before* entitlement, so that a locked
mission and a retired one are indistinguishable to a prober.

---

## 9. Performance and Scalability

### 9.1 Cost Profile

The system's defining economic property is that **simulation does not appear on the server's
cost curve at all**. A flight of arbitrary duration produces exactly one graded POST. Server
cost per flight is therefore constant in flight duration and consists of: validating a bounded
payload (≈1 ms), reconstructing telemetry (single-digit milliseconds), scoring (negligible),
one insert, one locked update, and one cache-counter increment.

Consequently the simulator appears nowhere on the scaling roadmap. The bottlenecks are
elsewhere.

### 9.2 The Ranked Read Model

Ranking is a full aggregate over the largest table in the schema, read far more often than it
changes. Every view of it is cached, keyed by a **generation counter** embedded in the cache
key. Invalidation is a single write: the counter is incremented, whereupon every previously
written key simply stops being consulted and expires on its own. There is no enumeration of
which per-course and per-viewer entries a given run might have affected.

Four properties of the implementation each fixed an observed defect and must not be undone:

1. **The counter is seeded before it is incremented.** Only some cache drivers treat an
   increment on an absent key as counting from zero; the database and memcached stores return
   false and write nothing. Without an atomic create-if-absent, a generation that no run had
   ever created remained pinned at zero and *every invalidation silently did nothing*.
2. **Cached values are wrapped rather than stored bare.** A slice may legitimately be null — a
   learner who has not flown has no standing — and a bare null is indistinguishable from a
   miss. Unwrapped, those learners re-ran the full ranking aggregate on every page view.
3. **Only plain arrays cross the cache boundary.** Serialising stores restrict which classes
   may be unserialised, so a cached query row would return as an incomplete class and fault on
   first use. The board would render on the miss that populated the cache and throw on every
   subsequent hit until expiry.
4. **Shared slices are built under a lock; per-viewer slices are not.** Because one submission
   anywhere retires every cached view simultaneously, all viewers miss at once; unguarded, each
   would answer with the same full aggregate and load would rise with traffic precisely when
   there is least headroom. Waiting is bounded and never fatal — a waiter that times out
   computes the slice itself. A per-viewer slice can only collide with that same learner's own
   requests, so a lock there would purchase nothing.

### 9.3 The Invalidation Cliff

The generation counter is global: one submission anywhere retires the entire board. This is
the system's dominant scaling limit, and it can be characterised analytically.

Let `A` be submissions per second across all learners and `B` the cost of rebuilding one
cached slice. The board's *effective* cached lifetime is `1/A` seconds, not its nominal
time-to-live. Below approximately `A = 1/B` the cache absorbs reads normally. Above it, the
board rebuilds permanently: the stampede lock prevents that from becoming `N` concurrent
aggregates, but converts them into a queue in which viewers wait, then fall through and compute
anyway.

For a full aggregate over a few million progress rows on indexed joins, `B ≈ 100–300 ms`,
placing the cliff at roughly **3–10 submissions per second** — a busy weekday, not a viral
spike.

Three responses exist, in increasing order of cost:

| Response | Mechanism | Gain | Cost |
| --- | --- | --- | --- |
| (a) Decouple invalidation from writes | throttle the counter bump, or rely on time-to-live alone | ~1 order of magnitude | seconds of staleness |
| (b) Scope invalidation to what moved | per-course generation counters | ~1 further order | one key per course |
| (c) Maintain ranking incrementally | sorted sets updated on write | reads become O(log N); writes invalidate nothing | the projection becomes durable state that can drift and needs periodic rebuild |

Response (a) is defensible on product grounds as well as engineering ones: a leaderboard is a
social object rather than a ledger, and thirty seconds of staleness is imperceptible.

Critically, the query object's public surface is already the correct seam for all three: none
of (a), (b), or (c) changes a single caller.

### 9.4 Deployment Topology

The web tier is stateless in design and is intended to scale horizontally behind a load
balancer, with a content delivery network for hashed assets and learner media, a shared cache
and session store, a primary database with an optional read replica, and object storage for
media.

The development defaults deliberately diverge, being tuned for a zero-dependency local
checkout. Every one of the following must change in production, and §12 records the divergence
as a real risk rather than a footnote:

| Setting | Development | Production | Rationale |
| --- | --- | --- | --- |
| Database | SQLite | MySQL 8+ | ranking uses window functions; column aliasing exists to avoid reserved words |
| Cache store | database | shared in-memory store | the read model exists to keep load *off* the database |
| Session driver | database | shared in-memory store | session writes are the tier's most frequent write, and what makes it non-stateless |
| Filesystem | local disk | object storage | on more than one node, media written to node A is unreachable from node B |

### 9.5 Absence of Asynchronous Work

There are presently no queued jobs; every request performs all of its own work inline. Two
workloads belong on a queue before traffic makes the question urgent: photograph
post-processing (the safety-critical validation must remain synchronous, but thumbnailing and
transfer to object storage need not), and ranking rebuilds should response (c) be adopted.
Neither is a large change now; both become substantially larger under load.

---

## 10. Security Considerations

### 10.1 Execution of Untrusted Code

Addressed in §4.1. The isolation properties are real but their intended scope is narrow: the
code executes only in its own author's browser, so the mechanism guards against accident, not
against an adversary attacking themselves.

### 10.2 Integrity of Assessment

Addressed in §5.7. Summarised: the client is trusted to report a trajectory and nothing more,
and every server-side adjustment is one-directional against the submitter.

### 10.3 Payment Webhook Authentication

The billing webhook is the only endpoint in the application that grants paid access with no
session behind it, and it receives correspondingly specific treatment.

The route is registered with the entire browser middleware group removed, rather than with a
single middleware excluded. The reasoning is twofold. First, nothing in that group applies to a
machine-to-machine POST: a session started and immediately discarded on every delivery is
churn. Second, excluding a group by name cannot drift the way naming a middleware class can —
and it already did drift, as the framework's request-forgery middleware was renamed, leaving
the former name a deprecated subclass. Excluding the old name would have silently left the
protection enabled on an endpoint that cannot satisfy it.

Signature verification is applied **unconditionally**. The vendor package verifies only when a
secret happens to be configured, which means an environment that forgets to set one *fails
open*. With no secret configured here, no signature matches and every call is rejected. The
governing judgement is explicit: a webhook nobody can call is an outage; a webhook anybody can
call is a breach.

A second middleware sits *behind* the signature check — and therefore reads only bodies already
authenticated — to absorb deliveries the controller would otherwise refuse indefinitely: one
already recorded, or one naming an unknown account. The provider redelivers anything that is
not a success response, and neither of those improves with repetition.

Variant identifiers are read from configuration, never from database rows, because the sandbox
and production catalogues carry different identifiers for the same product and a row would
allow a client-supplied value to reach checkout.

### 10.4 Media Confidentiality

Learner-captured photographs are written to a private filesystem and are never web-addressable.
Each frame reaches its owner as a short-lived expiring URL. Uploaded bytes are validated before
anything touches the disk, a per-learner quota bounds storage consumption, and a policy governs
both reading and deletion.

### 10.5 Rate Limiting

Write endpoints are throttled. The stated principle is that ceilings are sized **to bound what a
script can do, not to pace an honest user**: a mission takes at least ten seconds to fly and
submits once, so a limit of thirty per minute sits far above any genuine learner while bounding
automated abuse. Checkout initiation is throttled more tightly because each call may create a
customer record with the payment provider and an abandoned overlay costs an API round trip
regardless.

### 10.6 Authentication

Password authentication is supplemented by time-based one-time passwords with recovery codes and
by WebAuthn passkeys. Email verification gates the authenticated area. Password policy tightens
in production, including a minimum length and a check against known-compromised credentials.

---

## 11. Verification and Validation

### 11.1 Strategy

The project operates under an explicit rule that every change must be programmatically covered.
At the time of writing the suite comprises **365 test methods across 34 files** — 32 feature-test
classes, including dedicated authentication and settings groups, and 2 unit-test classes — plus
8 client-side test modules covering the airframe model, the physics layer, the simulator session
store, and shared UI utilities.

Feature tests are preferred over unit tests, and models are constructed through factories rather
than by hand, so that tests exercise the same construction path the application uses.

### 11.2 Static Analysis and Style

The continuous-integration pipeline runs, for the server: a formatter, a static analyser at a
high strictness level, and the test suite; and for the client: a linter, a formatter check, a
TypeScript compilation check, and the client test suite. Automated refactoring tooling is
configured but not enforced.

An additional runtime guard is armed outside production: lazy loading of relations raises an
exception, so an N+1 access pattern fails the test suite rather than degrading the production
database.

### 11.3 Contracts the Suite Pins

Three properties are not merely tested but *pinned*, in the sense that the tests exist
specifically to detect divergence rather than to demonstrate correctness:

1. **Cross-implementation agreement of the scoring policy.** The client and server graders must
   produce the same verdict; the mission cases most likely to diverge first are asserted
   explicitly.
2. **Cache generation semantics.** The leaderboard's invalidation behaviour, including the
   seeding subtlety of §9.2, is asserted directly.
3. **Entitlement precedence and gate registration.** The ordering of override, subscription, and
   default is asserted, as is the automatic registration of each capability as an authorisation
   ability.

### 11.4 A Known Gap in the Verification Environment

The test suite runs against SQLite with an in-memory cache and synchronous queue; production runs
a different database engine and a shared cache store. This is the correct default for a fast
suite and is harmless for most of the code — but it is precisely wrong for the two places where
the hardest logic lives:

- **The ranking SQL is engine-specific.** Window functions inside a subquery, and an alias that
  exists solely to avoid a reserved word, are validated only against SQLite. SQLite will accept
  queries the production engine rejects, and the failure would surface on the leaderboard page in
  production.
- **Cache-store semantics differ in ways the read model depends upon.** The array store used by
  the suite cannot observe the increment-on-absent-key difference that motivated the counter
  seeding, and it is the store that concealed the serialisation defect described in §9.2, because
  it alone retains live objects rather than serialising them.

The recommended remedy is to retain the fast suite as the default and add a scheduled or
pre-merge job running the same suite against production-equivalent services. It requires no new
tests; the existing ones become meaningful the moment they execute against the real engines.

---

## 12. Limitations and Threats to Validity

**No empirical evaluation with learners has been conducted.** All claims in this report concern
the system's construction, not its educational efficacy. Whether the assessment model produces
scores that correlate with programming competence is an open empirical question.

**Assessment integrity is probabilistic, not absolute.** §5.7 states the bound honestly: forgery
is made expensive, not impossible. A determined client can synthesise a plausible trajectory.
The design's defensible claim is that the cost of doing so approaches the cost of flying, and
that no honest learner can be harmed by any check.

**Collision reporting remains partially client-supplied.** Only provable geometric intrusion can
be established server-side. A forger who reports zero collisions while flying a path that grazes
rather than penetrates obstacles is under-penalised.

**The development-production configuration gap is a live risk.** Every entry in the table at
§9.4 is a default that is correct locally and dangerous in production. The gap is documented but
not asserted by any automated check.

**Ranking invalidation has a quantified cliff at commercially ordinary traffic.** §9.3 places it
at 3–10 submissions per second. The remedies are known and cheap; none has yet been applied.

**No retention policy governs the append-only tables.** Attempt history is the fastest-growing
data in the schema and nothing prunes it. Both attempt tables have accrued this debt on identical
terms, so a retention policy should address both rather than being written twice.

**Two commercial tiers are sold ahead of their capabilities.** Self-service checkout is
deliberately closed for tiers whose distinguishing features do not yet exist, and the presentation
marks unbuilt capabilities as such — but the arrangement depends on that honesty being maintained
by hand in the marketing copy.

**This report is the sole prose description of the system.** The engineering notes that
previously accompanied it are no longer in the repository, so nothing cross-checks the account
given here. Claims in this document were taken from the implementation at the commit named in
Appendix C and will drift from it as the code moves.

---

## 13. Future Work

**Empirical evaluation.** A controlled study comparing learning outcomes against a conventional
text-based introductory sequence would convert the pedagogical premise from assumption to
evidence.

**Asynchronous processing.** Photograph post-processing and ranking maintenance should move to a
queue before load makes the migration urgent (§9.5).

**Incremental ranking.** Response (c) of §9.3 replaces the aggregate with an incrementally
maintained projection, eliminating invalidation from the write path entirely at the cost of a
projection requiring periodic reconciliation.

**A versioned public API.** Programmatic access is an intended commercial capability. It should be
a separate, versioned surface with its own resource classes — authenticated by token and
rate-limited per token — rather than the in-page endpoints opened up. Sharing page-property shapes
with a public API would silently render every page a breaking-change surface.

**Institutional accounts.** Seat-based access requires membership tables and one further branch in
entitlement resolution, whose insertion point is already identified: between the subscription check
and the free-tier fallback.

**Additional language runtimes.** A second language runtime is a sold-but-unbuilt capability whose
gate already exists. The worker command protocol is language-agnostic, so the work is confined to
the execution environment inside the worker.

**Authoring tools.** Both missions and quizzes are presently seeded content with no authoring
interface. Content scaling requires one.

---

## 14. Conclusion

DroneVerse addresses a specific and non-obvious problem: how to grade work whose production must,
for reasons of interactivity and cost, occur on hardware the assessor does not control.

Its answer is a strict separation of simulation from adjudication. The browser is given the
computationally expensive task — a physics-accurate rigid-body simulation driven by learner code
in an isolated worker — and is trusted with exactly one output: a record of where the drone went.
Everything that determines what a learner has earned is derived on the server, from that
trajectory and from the mission's own geometry, using checks constructed so that each can only
ever move a result against the party who submitted it.

The architectural consequences propagate outward. Because simulation is client-side, per-flight
server cost is constant and the simulator never appears on the scaling roadmap; the binding
constraint turns out to be the invalidation policy of one cached ranking projection, a problem
with three known and inexpensive remedies. Because entitlement points into the catalogue rather
than out of it, commercial tiers and educational content evolve independently. Because scoring is
a pure function over a stateless context, it is exhaustively testable — which is what makes it
credible to implement the same policy twice, in two languages, and hold the two in agreement by
test.

The system's principal limitation is honestly stated by its own implementation and repeated here:
assessment integrity is a matter of raising cost, not of achieving impossibility. What the design
does guarantee is the more important half of that bargain — that no honest learner can be
penalised by any mechanism intended to constrain a dishonest one.

---

## Glossary

| Term | Definition |
| --- | --- |
| **Action** | A single-method, immutable class encapsulating one unit of write-side business logic, invocable from any context. |
| **Airframe** | A parameterised drone model supplying the flight envelope under which a run is simulated. |
| **Entitlement** | The resolved determination of what a given user may access, across two orthogonal axes. |
| **Generation counter** | An integer embedded in cache keys such that incrementing it retires every key derived from it. |
| **Mission** | An authored challenge: geometry, briefing, starter code, success criteria, and a maximum score. |
| **Objective** | A gradable mission element — a waypoint, a photo target, a photo quota, or a traversal requirement. |
| **Page properties** | Server-computed data delivered to a client page component in place of an API response. |
| **Preview grade** | The client-computed score shown immediately on landing, subsequently discarded. |
| **Query object** | A class encapsulating an aggregate read together with its own caching policy. |
| **Reconstruction** | Server-side derivation of what a flight achieved, from its trajectory and the mission's geometry. |
| **Telemetry** | The time-stamped sequence of drone positions sampled during a run. |
| **Trust boundary** | The submission schema, which accepts descriptions of a flight and no assertions about its result. |

---

## Appendix A: Repository Map

```
app/
├── Actions/          write-side business logic, one public entry method each
├── Concerns/         traits answering questions on a model
├── Enums/            closed domain vocabularies
├── Http/
│   ├── Controllers/  HTTP shape only
│   ├── Middleware/   cross-cutting request concerns
│   ├── Requests/     payload validation and casting — the trust boundary
│   └── Resources/    server state → client page properties
├── Models/           persistence, relations, single-row questions
├── Policies/         per-model authorisation
├── Providers/        container bindings, gate registration
└── Queries/          cached read models over aggregates

resources/js/
├── components/       primitives, simulator components, marketing components
├── hooks/
├── layouts/          application, authentication, settings shells
├── lib/simulator/    physics, telemetry, grading, worker protocol, upload queue
├── pages/            one component per route
└── types/

database/
├── migrations/
└── seeders/          courses, quizzes, and the airframe fleet

tests/
├── Feature/          32 classes, including Auth/ and Settings/ groups
└── Unit/             2 classes

docs/                 this report, and the architecture and ERD diagrams
```

## Appendix B: HTTP Surface

Twenty-eight application routes, excluding vendor-registered authentication endpoints.

| Area | Representative routes | Notes |
| --- | --- | --- |
| Public | `GET /`, `GET /courses`, `GET /courses/{course}`, `GET /pricing` | slug-bound; readable without an account |
| Simulator | `GET /courses/{course}/challenges/{challenge}` | asserts playability then entitlement |
| Flight submission | `POST …/attempts` | throttled; returns JSON; the trust boundary |
| Airframe selection | `PUT …/drone` | capability-gated; the only writable path for the choice |
| Media | `POST …/photos`, `GET /photos`, `DELETE /photos/{photo}` | UUID-bound; policy-guarded |
| Knowledge checks | `GET …/quizzes/{quiz}`, `POST …/quizzes/{quiz}/attempts` | graded server-side |
| Progress views | `GET /dashboard`, `GET /leaderboard`, `GET /analytics` | analytics is capability-gated |
| Billing | `POST /checkout`, `PUT /settings/subscription`, `PUT /settings/subscription/plan`, `DELETE /settings/subscription` | throttled |
| Webhook | `POST /lemon-squeezy/webhook` | browser middleware removed; signature verified unconditionally |
| Settings | profile, security, appearance, billing | |

Nested paths express *containment*, not merely hierarchy: a mission is addressed through its
course because membership is an enforced check, and a mismatched pair is indistinguishable from
content that does not exist.

## Appendix C: Source References

Every claim in this report was read from the working tree at commit `635af1e` on `main`.
Readers seeking the implementation of any claim should begin at the following files, in this
order.

| § | Claim | File |
| --- | --- | --- |
| 3.4 | Reference controller shape | `app/Http/Controllers/ChallengeController.php` |
| 4.1 | Worker isolation | `resources/js/lib/simulator/worker.ts` |
| 4.2 | Command protocol | `resources/js/lib/simulator/commands.ts` |
| 4.3 | Flight model | `resources/js/lib/simulator/physics.ts` |
| 4.5 | Upload shaping | `resources/js/lib/simulator/upload-queue.ts` |
| 5.3 | Trust boundary | `app/Http/Requests/StoreChallengeAttemptRequest.php` |
| 5.4 | Reconstruction | `app/Actions/ReconstructRunTelemetry.php` |
| 5.6 | Scoring policy (authoritative) | `app/Actions/GradeSimulatorRun.php` |
| 5.6 | Scoring policy (preview) | `resources/js/lib/simulator/grader.ts` |
| 5.8 | Monotonic merge under lock | `app/Actions/RecordChallengeAttempt.php` |
| 8.1 | Two entitlement axes | `app/Enums/Plan.php`, `app/Enums/Feature.php` |
| 8.2 | Resolution precedence | `app/Actions/ResolvePlanForUser.php` |
| 8.3 | Instance memoisation | `app/Concerns/HasPlan.php` |
| 9.2 | Cached ranking read model | `app/Queries/Leaderboard.php` |
| 10.3 | Webhook hardening | `routes/billing.php`, `app/Http/Middleware/` |
| 11.3 | Pinned contracts | `tests/Feature/ChallengeTest.php`, `tests/Feature/LeaderboardCacheTest.php`, `tests/Feature/EntitlementTest.php` |
