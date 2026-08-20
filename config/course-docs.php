<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Course Documentation
    |--------------------------------------------------------------------------
    |
    | The written guide behind each course, keyed by course slug. A course
    | with no entry here simply has no documentation page — the link is not
    | rendered and the route 404s — because a docs page with nothing authored
    | in it is worse than no docs page at all.
    |
    | The examples are deliberately NOT the missions' reference solutions.
    | Those are earned: App\Models\Challenge unlocks `solution_code` after a
    | completion or three runs, and publishing the same code on an open page
    | would hand it over to anyone who guessed the URL. These teach the
    | commands the course is built on, using shapes a pilot can lift into any
    | mission in it — which is also what makes them worth reading once the
    | solutions are unlocked.
    |
    | `commands` names entries in config/drone-api.php. The page renders the
    | reference from there, so a signature is written once and a course that
    | names a command that no longer exists fails its test rather than
    | rendering a blank row.
    |
    */

    'drone-basics' => [
        'tagline' => 'Take off, go somewhere, come back down.',
        'summary' => 'Everything in this course is built from five commands and one loop. Get comfortable here and the rest of the catalog is the same ideas flown to tighter tolerances.',
        'objectives' => [
            'Structure a flight program around `async function main(drone)`.',
            'Leave the ground and return to it on purpose.',
            'Move relative to the nose with `turn()` and `moveForward()`, and absolutely with `moveTo()`.',
            'Walk a list of waypoints instead of writing one line per stop.',
            'Read the rangefinder and stop before you hit something.',
        ],
        'commands' => ['takeoff', 'land', 'hover', 'moveForward', 'turn', 'moveTo', 'setAltitude', 'getDistanceAhead'],
        'examples' => [
            [
                'slug' => 'minimal-flight',
                'title' => 'The shape of every program',
                'description' => 'Your code is one async function named `main`, and it is handed a `drone`. Every command is awaited, because each one resolves when the manoeuvre has actually been flown — drop an `await` and the whole script runs in a single frame.',
                'code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();
                        await drone.hover(2);
                        await drone.land();
                    }
                    JS,
            ],
            [
                'slug' => 'flying-a-square',
                'title' => 'A square, with a loop',
                'description' => '`moveForward()` goes wherever the nose points, so turning between legs is what draws a shape. Four identical legs means a loop rather than four pairs of lines — and changing the 4 to a 6 flies a hexagon.',
                'code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff(3);

                        const sides = 4;

                        for (let i = 0; i < sides; i++) {
                            await drone.moveForward(6);
                            await drone.turn(360 / sides);
                        }

                        await drone.land();
                    }
                    JS,
            ],
            [
                'slug' => 'waypoint-list',
                'title' => 'Waypoints as data',
                'description' => 'Waypoint coordinates from a briefing are absolute, so they are flown with `moveTo()`. Keeping them in an array separates the route from the flying: to change the mission you edit the list, not the loop.',
                'code' => <<<'JS'
                    async function main(drone) {
                        // Lift off first — moveTo() will happily fly you into
                        // the ground if you hand it a low y.
                        await drone.takeoff();

                        const route = [
                            { x: 8, y: 3, z: -6 },
                            { x: 14, y: 3, z: -14 },
                            { x: 2, y: 3, z: -18 },
                        ];

                        for (const point of route) {
                            await drone.moveTo(point.x, point.y, point.z);
                            console.log('reached', point);
                        }

                        await drone.land();
                    }
                    JS,
            ],
            [
                'slug' => 'stop-before-the-wall',
                'title' => 'Creeping up on an obstacle',
                'description' => 'The rangefinder looks straight along the nose and reports 20 when it sees nothing. Testing against a threshold — rather than against 20 — is what makes this read as "keep going while the way is clear".',
                'code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff(2);

                        // Nudge forward a meter at a time and re-check, so the
                        // drone never commits to a leg longer than it can see.
                        while ((await drone.getDistanceAhead()) > 2) {
                            await drone.moveForward(1);
                        }

                        console.log('stopped at', await drone.getDistanceAhead(), 'm');

                        // Something is in the way: go over it rather than through it.
                        await drone.setAltitude(6);
                        await drone.moveForward(6);
                        await drone.land();
                    }
                    JS,
            ],
        ],
        'pitfalls' => [
            [
                'title' => 'A missing `await` looks like a crash',
                'body' => 'Without it the command is issued and the next line runs immediately, so the whole program queues up in one frame. The usual symptom is a drone that lands while still climbing.',
            ],
            [
                'title' => '`land()` lands here, not at the pad',
                'body' => 'It descends from wherever it is called. If the mission grades on returning to the pad, fly back over it first.',
            ],
        ],
    ],

    'precision-flight' => [
        'tagline' => 'The same commands, flown to a tolerance.',
        'summary' => 'These missions are not harder to plan — they are harder to fly. The margin for error is small enough that speed, settling time and the difference between absolute and relative altitude all start to matter.',
        'objectives' => [
            'Command absolute altitude, and step relative to the current one.',
            'Trade speed for accuracy with `setSpeed()`.',
            'Use `hover()` to let an airframe settle before a tight manoeuvre.',
            'Line up over a mark before descending onto it.',
        ],
        'commands' => ['setAltitude', 'setSpeed', 'hover', 'moveTo', 'moveForward', 'turn', 'getAltitude', 'getPosition', 'land'],
        'examples' => [
            [
                'slug' => 'altitude-steps',
                'title' => 'Stepping up in fixed increments',
                'description' => '`setAltitude()` is absolute, so a relative step means reading the current height and adding to it. Computing each rung from the base rather than from the last reading keeps a small hover drift from accumulating up the ladder.',
                'code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff(2);

                        const base = await drone.getAltitude();

                        for (let step = 1; step <= 4; step++) {
                            await drone.setAltitude(base + step * 2);

                            // Let it settle before anything is measured at this rung.
                            await drone.hover(1);

                            console.log('rung', step, 'at', await drone.getAltitude());
                        }

                        await drone.land();
                    }
                    JS,
            ],
            [
                'slug' => 'slow-is-accurate',
                'title' => 'Slowing down for the tight part',
                'description' => 'Cruise speed is a setting, not an argument — it applies to every move that follows until it is changed. Cruising between the open stretches and slowing for the gates is usually faster overall than flying the whole route carefully.',
                'code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff(3);

                        // Open ground: get it over with.
                        drone.setSpeed(8);
                        await drone.moveTo(10, 3, -10);

                        // Tight ground: buy accuracy with time.
                        drone.setSpeed(2);
                        await drone.moveTo(12, 2.4, -16);
                        await drone.moveTo(14, 2.4, -22);

                        await drone.land();
                    }
                    JS,
            ],
            [
                'slug' => 'offset-slalom',
                'title' => 'A slalom from a list of offsets',
                'description' => 'A weaving line is a straight run down one axis with an alternating offset on the other. Written this way the shape lives in two numbers, and widening the weave is a one-character edit.',
                'code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff(3);
                        drone.setSpeed(4);

                        const gates = 6;
                        const spacing = 5;   // meters between gates
                        const weave = 2.5;   // how far off the centreline each way

                        for (let i = 0; i < gates; i++) {
                            const offset = i % 2 === 0 ? weave : -weave;

                            await drone.moveTo(offset, 3, -(i + 1) * spacing);
                        }

                        await drone.land();
                    }
                    JS,
            ],
            [
                'slug' => 'pinpoint-landing',
                'title' => 'Landing on a mark',
                'description' => 'Because `land()` drops straight down, a pinpoint landing is really a pinpoint hover: get the horizontal position right, let it stop moving, and only then descend.',
                'code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff(4);

                        const pad = { x: 12, z: -18 };

                        // Arrive high and slow, directly over the pad.
                        drone.setSpeed(2);
                        await drone.moveTo(pad.x, 4, pad.z);

                        // Kill the drift before committing to the descent.
                        await drone.hover(1.5);

                        const here = await drone.getPosition();
                        console.log('offset', (here.x - pad.x).toFixed(2), (here.z - pad.z).toFixed(2));

                        await drone.land();
                    }
                    JS,
            ],
        ],
        'pitfalls' => [
            [
                'title' => 'Relative steps drift, absolute ones do not',
                'body' => 'Reading the altitude and adding to it once per rung accumulates whatever the hover wandered. Compute each target from a fixed base instead.',
            ],
            [
                'title' => 'Speed is sticky',
                'body' => '`setSpeed()` stays in effect for the rest of the run. Slowing down for a gate and forgetting to speed back up is a common way to lose a timed mission.',
            ],
        ],
    ],

    'sensor-flight' => [
        'tagline' => 'Let the readings decide where to go.',
        'summary' => 'Up to now the route was known before the run started. Here it is not: the program probes, reads, and picks its next move from what came back. The flying is the same — the control flow is what changes.',
        'objectives' => [
            'Drive a `while` loop from a live sensor reading.',
            'Probe in more than one direction by turning between reads.',
            'Read a `scan()` contact list and pick a target out of it.',
            'Turn a bearing and a distance into a leg of flight.',
        ],
        'commands' => ['getDistanceAhead', 'scan', 'getPosition', 'getHeading', 'getAltitude', 'turn', 'moveForward', 'moveTo', 'setSpeed'],
        'examples' => [
            [
                'slug' => 'probe-both-ways',
                'title' => 'Probing left and right',
                'description' => 'The rangefinder only looks along the nose, so measuring in another direction means pointing the nose there. Turning back by the same amount afterwards leaves the drone as it was found, which keeps the probe from becoming part of the route.',
                'code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff(2.5);

                        async function probe(degrees) {
                            await drone.turn(degrees);
                            const distance = await drone.getDistanceAhead();
                            await drone.turn(-degrees);

                            return distance;
                        }

                        const left = await probe(-90);
                        const right = await probe(90);

                        console.log('left', left, 'right', right);

                        // Take whichever side has more room.
                        await drone.turn(left > right ? -90 : 90);
                        await drone.moveForward(Math.min(left, right, 8) - 1);

                        await drone.land();
                    }
                    JS,
            ],
            [
                'slug' => 'reading-a-scan',
                'title' => 'Reading the contact list',
                'description' => '`scan()` returns everything within range, nearest first, with a bearing already relative to the nose. Logging the raw list once is the fastest way to learn what a mission has actually put around you.',
                'code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff(4);

                        const contacts = await drone.scan(25);

                        for (const contact of contacts) {
                            console.log(
                                contact.kind,
                                contact.label ?? '(unlabelled)',
                                `${contact.distance}m`,
                                `bearing ${contact.bearingDeg}`,
                            );
                        }

                        console.log('nearest is', contacts[0]?.kind ?? 'nothing in range');

                        await drone.land();
                    }
                    JS,
            ],
            [
                'slug' => 'homing-on-a-contact',
                'title' => 'Flying to something you found',
                'description' => 'A contact carries both a bearing and absolute coordinates, which gives you two ways to reach it. Turning by the bearing and flying the distance is the one that generalises — it works the same whether the target moved or you did.',
                'code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff(3);

                        const contacts = await drone.scan(30);
                        const target = contacts.find((c) => c.label === 'north-tower');

                        if (!target) {
                            console.warn('nothing labelled north-tower in range');
                            await drone.land();
                            return;
                        }

                        // bearingDeg is already relative to the nose: 0 is straight
                        // ahead, positive is to the right. That is exactly what
                        // turn() wants, so it can be passed straight through.
                        await drone.turn(target.bearingDeg);

                        // Stop short of it rather than on top of it.
                        drone.setSpeed(3);
                        await drone.moveForward(target.distance - 4);

                        await drone.land();
                    }
                    JS,
            ],
            [
                'slug' => 'guarded-loop',
                'title' => 'A loop that always ends',
                'description' => 'A sensor loop that never satisfies its condition runs until the mission clock stops it, and the run is scored as a timeout. Counting the passes costs one variable and turns a hang into a log line you can read.',
                'code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff(2);

                        const maxSteps = 40;
                        let steps = 0;

                        while ((await drone.getDistanceAhead()) > 2) {
                            if (steps++ >= maxSteps) {
                                console.warn('gave up after', maxSteps, 'steps');
                                break;
                            }

                            await drone.moveForward(1);
                        }

                        await drone.land();
                    }
                    JS,
            ],
        ],
        'pitfalls' => [
            [
                'title' => 'The rangefinder is a level ray',
                'body' => "It is cast horizontally at the drone's current altitude and sees nothing above or below that line. A low wall reads as clear air from four meters up.",
            ],
            [
                'title' => '20 means "clear"',
                'body' => '`getDistanceAhead()` returns 20 when nothing is in range, so `=== 20` is not a reliable test for open ground. Compare against a threshold well below it.',
            ],
            [
                'title' => '`getHeading()` is not the HUD compass',
                'body' => 'The HUD shows a 0–360 compass bearing; `getHeading()` reports raw yaw, which runs the other way. Prefer a scan contact\'s `bearingDeg` when direction actually matters.',
            ],
        ],
    ],

    'delivery-ops' => [
        'tagline' => 'A route, a clock, and a city in the way.',
        'summary' => 'Multi-stop runs where the plan is the score. The individual manoeuvres are ones you already know; what these missions test is whether you sequence them without wasting altitude, distance or time.',
        'objectives' => [
            'Fly a stop list with a service pause at each one.',
            'Cruise above the rooftops and descend only over a stop.',
            'Spend the clock deliberately with `setSpeed()`.',
            'Factor a route into functions you can reuse across missions.',
        ],
        'commands' => ['moveTo', 'setAltitude', 'setSpeed', 'hover', 'takeoff', 'land', 'getBattery', 'getPosition'],
        'examples' => [
            [
                'slug' => 'stop-list',
                'title' => 'A delivery run as a stop list',
                'description' => 'Every stop is the same four moves, so it is written once as a function and called per entry. The cruise altitude sits above the tallest thing on the route, and the drone only comes down when it is over a drop.',
                'code' => <<<'JS'
                    async function main(drone) {
                        const CRUISE = 12;      // above the rooftops
                        const DROP = 2;         // hand-off height
                        const SERVICE = 1.5;    // seconds on station per stop

                        const stops = [
                            { name: 'depot-north', x: 10, z: -8 },
                            { name: 'tower-west', x: -6, z: -20 },
                            { name: 'market', x: 14, z: -26 },
                        ];

                        async function deliver(stop) {
                            await drone.moveTo(stop.x, CRUISE, stop.z);
                            await drone.setAltitude(DROP);
                            await drone.hover(SERVICE);
                            await drone.setAltitude(CRUISE);

                            console.log('delivered', stop.name);
                        }

                        await drone.takeoff(CRUISE);
                        drone.setSpeed(7);

                        for (const stop of stops) {
                            await deliver(stop);
                        }

                        await drone.moveTo(0, CRUISE, 0);
                        await drone.land();
                    }
                    JS,
            ],
            [
                'slug' => 'climb-once',
                'title' => 'Climbing once instead of per leg',
                'description' => 'The commonest way to lose a timed route is to yo-yo: descending to each stop and climbing back to cruise between every pair. Sorting the stops so the route does not double back costs nothing and is usually worth more than raising the speed.',
                'code' => <<<'JS'
                    async function main(drone) {
                        const CRUISE = 14;

                        const stops = [
                            { name: 'market', x: 14, z: -26 },
                            { name: 'depot-north', x: 10, z: -8 },
                            { name: 'tower-west', x: -6, z: -20 },
                        ];

                        // Nearest-first from the pad. Not the shortest possible
                        // tour, but it is one line and it beats the order the
                        // briefing happened to list them in.
                        const ordered = [...stops].sort(
                            (a, b) => Math.hypot(a.x, a.z) - Math.hypot(b.x, b.z),
                        );

                        await drone.takeoff(CRUISE);
                        drone.setSpeed(8);

                        for (const stop of ordered) {
                            await drone.moveTo(stop.x, CRUISE, stop.z);
                            await drone.hover(1);
                        }

                        await drone.moveTo(0, CRUISE, 0);
                        await drone.land();
                    }
                    JS,
            ],
            [
                'slug' => 'watch-the-battery',
                'title' => 'Checking you can finish',
                'description' => 'Charge drains faster under throttle than in a hover, and the rate belongs to the airframe you picked. Reading it between stops turns a run that dies on the last leg into one that comes home early and lands.',
                'code' => <<<'JS'
                    async function main(drone) {
                        const CRUISE = 12;
                        const RESERVE = 25; // percent kept back for the trip home

                        const stops = [
                            { x: 10, z: -8 },
                            { x: -6, z: -20 },
                            { x: 14, z: -26 },
                        ];

                        await drone.takeoff(CRUISE);
                        drone.setSpeed(7);

                        for (const stop of stops) {
                            const charge = await drone.getBattery();

                            if (charge < RESERVE) {
                                console.warn('breaking off at', charge, '%');
                                break;
                            }

                            await drone.moveTo(stop.x, CRUISE, stop.z);
                            await drone.hover(1);
                        }

                        await drone.moveTo(0, CRUISE, 0);
                        await drone.land();
                    }
                    JS,
            ],
        ],
        'pitfalls' => [
            [
                'title' => 'Cruise under the rooftops and the straight line finds them',
                'body' => '`moveTo()` does not route around anything. Pick a cruise altitude above the tallest obstacle on the route and only leave it over a stop.',
            ],
            [
                'title' => 'Hovers are on the clock',
                'body' => 'A one-second service pause at six stops is six seconds. On a timed mission that is often the whole margin.',
            ],
        ],
    ],

    'city-operations' => [
        'tagline' => 'A full shift over a working city block.',
        'summary' => 'The graduation course. Sensors, camera and route planning at once, over a block with traffic, trees and a wash tunnel in it — and missions that grade several of those at the same time.',
        'objectives' => [
            'Sweep a street and log every contact the scanner returns.',
            'Find a labelled target and photograph it from the right place.',
            'Line up on a tunnel and fly it straight through.',
            'Compose a long mission out of small, separately-testable functions.',
        ],
        'commands' => ['scan', 'takePhoto', 'moveTo', 'moveForward', 'turn', 'setSpeed', 'setAltitude', 'hover', 'getPosition', 'takeoff', 'land'],
        'examples' => [
            [
                'slug' => 'street-sweep',
                'title' => 'Sweeping a street and logging it',
                'description' => 'A sweep is a lawnmower pattern with a scan at each pass. Collecting contacts into a Map keyed by label is what stops the same parked van being counted once per pass.',
                'code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff(6);
                        drone.setSpeed(5);

                        const found = new Map();

                        for (let lane = 0; lane < 4; lane++) {
                            await drone.moveTo(lane * 6, 6, -10);
                            await drone.moveTo(lane * 6, 6, -28);

                            for (const contact of await drone.scan(20)) {
                                const key = contact.label ?? `${contact.kind}@${contact.x},${contact.z}`;

                                if (!found.has(key)) {
                                    found.set(key, contact);
                                    console.log('new contact:', contact.kind, key);
                                }
                            }
                        }

                        console.log('swept', found.size, 'objects');
                        await drone.land();
                    }
                    JS,
            ],
            [
                'slug' => 'photograph-a-target',
                'title' => 'Photographing a named target',
                'description' => 'Photo objectives are graded on where the drone was standing when the shutter fired, so the job is to get to a sensible stand-off and point the nose at the subject. The 0.4 s stabilise happens inside `takePhoto()` — it does not need a hover before it.',
                'code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff(8);

                        const contacts = await drone.scan(35);
                        const subject = contacts.find((c) => c.label === 'clock-tower');

                        if (!subject) {
                            console.warn('clock-tower not in range');
                            await drone.land();
                            return;
                        }

                        // Stand off far enough to get the whole thing in frame.
                        drone.setSpeed(3);
                        await drone.turn(subject.bearingDeg);
                        await drone.moveForward(subject.distance - 10);

                        // Re-aim from the new position: the bearing changed on the way in.
                        const closer = await drone.scan(20);
                        const reacquired = closer.find((c) => c.label === 'clock-tower');

                        if (reacquired) {
                            await drone.turn(reacquired.bearingDeg);
                        }

                        await drone.takePhoto('clock-tower, north face');

                        await drone.land();
                    }
                    JS,
            ],
            [
                'slug' => 'through-the-tunnel',
                'title' => 'Flying the wash tunnel',
                'description' => "A tunnel is graded at both mouths, so it only counts if the drone goes in one end and out the other. Approaching on the tunnel's own axis, dropping to the middle of its opening and committing to a single straight leg is what keeps it from clipping a wall halfway through.",
                'code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff(4);

                        const [wash] = (await drone.scan(40)).filter((c) => c.kind === 'carwash');

                        if (!wash) {
                            console.warn('no wash tunnel in range');
                            await drone.land();
                            return;
                        }

                        // Line up well short of the mouth, on the tunnel's centreline.
                        drone.setSpeed(2);
                        await drone.moveTo(wash.x, wash.y, wash.z + 10);

                        // Re-acquire from the new position and square up on it.
                        const aligned = (await drone.scan(40)).find((c) => c.kind === 'carwash');

                        if (aligned) {
                            await drone.turn(aligned.bearingDeg);
                        }

                        await drone.hover(1);

                        // One straight run, long enough to clear the far mouth.
                        await drone.moveForward(20);

                        await drone.land();
                    }
                    JS,
            ],
            [
                'slug' => 'shift-skeleton',
                'title' => 'A shift, in pieces',
                'description' => 'A combined mission is long enough that debugging it as one block is painful. Splitting it into named phases lets you comment out three of them and fly the fourth until it works — which is how these get finished.',
                'code' => <<<'JS'
                    async function main(drone) {
                        const CRUISE = 10;

                        async function launch() {
                            await drone.takeoff(CRUISE);
                            drone.setSpeed(6);
                        }

                        async function survey() {
                            for (const z of [-10, -20, -30]) {
                                await drone.moveTo(0, CRUISE, z);
                                await drone.takePhoto(`survey z=${z}`);
                            }
                        }

                        async function inspect() {
                            const contacts = await drone.scan(30);
                            console.log('contacts on station:', contacts.length);
                        }

                        async function recover() {
                            await drone.moveTo(0, CRUISE, 0);
                            await drone.land();
                        }

                        await launch();
                        await survey();
                        await inspect();
                        await recover();
                    }
                    JS,
            ],
        ],
        'pitfalls' => [
            [
                'title' => 'The bearing goes stale as you fly',
                'body' => '`bearingDeg` is measured from where the drone was when it scanned. On a long approach, scan again near the target and re-aim.',
            ],
            [
                'title' => 'Photos are graded on position, not on framing',
                'body' => 'A photo target checks where the drone was standing. Being close and pointed the wrong way still fails it, and so does a beautiful shot taken from outside the radius.',
            ],
            [
                'title' => 'Scanning sees through walls',
                'body' => 'The scanner is geometric and reports everything within range regardless of what is between you and it. Something in the contact list is not necessarily something you can fly to in a straight line.',
            ],
        ],
    ],

];
