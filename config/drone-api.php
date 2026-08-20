<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | The `drone` Object
    |--------------------------------------------------------------------------
    |
    | Every command a pilot's program can issue, in the words the course
    | documentation renders. This is a description of an API that already
    | exists — resources/js/lib/simulator/worker.ts declares the methods,
    | resources/js/lib/simulator/physics.ts carries the flight ones out, and
    | use-drone-simulation.ts answers the sensor ones. Nothing here is
    | aspirational, and an entry that stops matching those three files is a
    | bug in this file rather than a feature request for them.
    |
    | It lives in config rather than in the database because it is not
    | content a course author edits: it is the shape of the runtime, it is
    | the same for every course, and a seeded row would let two deployments
    | document two different APIs over one simulator.
    |
    | `returns` being null means the call resolves with nothing useful and is
    | awaited purely for the manoeuvre to finish. Every command is awaited —
    | the promise settles when the physics loop has actually flown it, which
    | is why a program that forgets an `await` runs its whole script in one
    | frame and lands before it has taken off.
    |
    */

    'commands' => [

        'takeoff' => [
            'group' => 'flight',
            'signature' => 'await drone.takeoff(altitude?)',
            'summary' => 'Spool the motors and climb straight up from the pad.',
            'params' => [
                ['name' => 'altitude', 'type' => 'number', 'optional' => true, 'description' => 'Target height in meters. Defaults to 1.5.'],
            ],
            'returns' => null,
            'notes' => [
                'The climb starts only after the airframe has spooled up, so the first second of a run looks like nothing is happening.',
                'Resolves once the drone is inside the arrival window and has bled off its climb rate, not the moment it passes the altitude.',
            ],
        ],

        'land' => [
            'group' => 'flight',
            'signature' => 'await drone.land()',
            'summary' => 'Descend straight down from wherever the drone currently is and settle on its landing gear.',
            'params' => [],
            'returns' => null,
            'notes' => [
                'Lands where it is called, not where it took off. Fly back over the pad first if the mission grades on landing there.',
                'The last 0.7 m is flown as a slow flare, so a landing always costs a beat more than the descent itself.',
            ],
        ],

        'moveForward' => [
            'group' => 'flight',
            'signature' => 'await drone.moveForward(distance)',
            'summary' => "Fly `distance` meters along the drone's current heading, holding altitude.",
            'params' => [
                ['name' => 'distance', 'type' => 'number', 'optional' => false, 'description' => 'Meters to travel. Horizontal only — altitude is preserved.'],
            ],
            'returns' => null,
            'notes' => [
                'Relative to the nose, so a turn() before it changes where it goes. This is the pair that flies polygons.',
            ],
        ],

        'moveTo' => [
            'group' => 'flight',
            'signature' => 'await drone.moveTo(x, y, z)',
            'summary' => 'Fly to an absolute point in the world, in one straight line.',
            'params' => [
                ['name' => 'x', 'type' => 'number', 'optional' => false, 'description' => 'World X in meters.'],
                ['name' => 'y', 'type' => 'number', 'optional' => false, 'description' => 'Altitude in meters.'],
                ['name' => 'z', 'type' => 'number', 'optional' => false, 'description' => 'World Z in meters.'],
            ],
            'returns' => null,
            'notes' => [
                'It does not route around anything. The straight line is the whole plan, so anything between here and there is your problem.',
                'Waypoint coordinates are absolute, which is why waypoint missions are usually flown with this rather than with moveForward.',
            ],
        ],

        'turn' => [
            'group' => 'flight',
            'signature' => 'await drone.turn(degrees)',
            'summary' => 'Yaw in place, without moving.',
            'params' => [
                ['name' => 'degrees', 'type' => 'number', 'optional' => false, 'description' => 'Positive yaws right (clockwise from above); negative yaws left.'],
            ],
            'returns' => null,
            'notes' => [
                'Rotation only. The drone holds its position while it turns, so a turn never costs you altitude or ground.',
            ],
        ],

        'hover' => [
            'group' => 'flight',
            'signature' => 'await drone.hover(seconds)',
            'summary' => 'Hold station for a fixed time.',
            'params' => [
                ['name' => 'seconds', 'type' => 'number', 'optional' => false, 'description' => 'How long to hold, in seconds of flight time.'],
            ],
            'returns' => null,
            'notes' => [
                'Holds against the wind rather than freezing, so it is also how you let an airframe settle before a tight manoeuvre.',
                'It spends mission time. On a mission graded against the clock, every hover is on the bill.',
            ],
        ],

        'setAltitude' => [
            'group' => 'flight',
            'signature' => 'await drone.setAltitude(altitude)',
            'summary' => 'Climb or descend to an absolute height, holding position over the ground.',
            'params' => [
                ['name' => 'altitude', 'type' => 'number', 'optional' => false, 'description' => 'Target height in meters above the field.'],
            ],
            'returns' => null,
            'notes' => [
                'Absolute, not relative. To step up by two meters, read getAltitude() first and add to it.',
            ],
        ],

        'setSpeed' => [
            'group' => 'flight',
            'signature' => 'drone.setSpeed(speed)',
            'summary' => 'Set the cruise speed used by every move that follows.',
            'params' => [
                ['name' => 'speed', 'type' => 'number', 'optional' => false, 'description' => "Metres per second, clamped to the airframe's own minimum and maximum cruise."],
            ],
            'returns' => null,
            'notes' => [
                'Clamped rather than rejected: ask a Freighter for 20 m/s and you get its maximum, not an error.',
                'Takes effect immediately and stays set for the rest of the run.',
            ],
        ],

        'getPosition' => [
            'group' => 'sensors',
            'signature' => 'await drone.getPosition()',
            'summary' => 'Where the drone is now, in world coordinates.',
            'params' => [],
            'returns' => '`{ x, y, z }` in meters.',
            'notes' => [],
        ],

        'getAltitude' => [
            'group' => 'sensors',
            'signature' => 'await drone.getAltitude()',
            'summary' => 'Current height above the field.',
            'params' => [],
            'returns' => 'A number, in meters. The same value as `getPosition().y`.',
            'notes' => [],
        ],

        'getHeading' => [
            'group' => 'sensors',
            'signature' => 'await drone.getHeading()',
            'summary' => "The airframe's yaw, in degrees.",
            'params' => [],
            'returns' => 'A number, in degrees.',
            'notes' => [
                'This is not the 0–360 compass figure on the HUD. The HUD shows the compass bearing; this reports raw yaw, which runs the opposite way — a positive turn() makes this value go down.',
                'For anything that has to reason about direction, prefer the `bearingDeg` on a scan() contact: it is already relative to the nose and already normalised.',
            ],
        ],

        'getBattery' => [
            'group' => 'sensors',
            'signature' => 'await drone.getBattery()',
            'summary' => 'Charge remaining, as a whole percent.',
            'params' => [],
            'returns' => 'An integer from 0 to 100.',
            'notes' => [
                'Drains faster under throttle than in a hover, and the rate is a property of the airframe you picked.',
            ],
        ],

        'getDistanceAhead' => [
            'group' => 'sensors',
            'signature' => 'await drone.getDistanceAhead()',
            'summary' => 'Rangefinder reading straight along the nose.',
            'params' => [],
            'returns' => 'Meters to the first solid thing ahead, or 20 if nothing is within range.',
            'notes' => [
                'A level ray, cast horizontally from the drone at its current altitude — it sees nothing above or below that line.',
                '20 means "clear", not "wall at 20 m". Compare against a threshold well under 20 rather than testing for the number itself.',
            ],
        ],

        'scan' => [
            'group' => 'sensors',
            'signature' => 'await drone.scan(range?)',
            'summary' => 'Sweep the object-detection suite and list everything around the drone.',
            'params' => [
                ['name' => 'range', 'type' => 'number', 'optional' => true, 'description' => 'Radius in meters. Defaults to 15, clamped to between 2 and 40.'],
            ],
            'returns' => 'An array of contacts sorted nearest-first, each `{ kind, label, x, y, z, distance, bearingDeg }`.',
            'notes' => [
                '`bearingDeg` is relative to the nose: 0 is dead ahead, positive is to the right, and it is always between -180 and 180.',
                '`label` is the callsign the mission gave an object, or null. It is how a briefing names a target you have to find.',
                'Unlike the rangefinder, this is spherical and sees in every direction, including behind and above.',
            ],
        ],

        'takePhoto' => [
            'group' => 'camera',
            'signature' => 'await drone.takePhoto(label?)',
            'summary' => 'Hold steady for a beat and capture a frame from the drone camera.',
            'params' => [
                ['name' => 'label', 'type' => 'string', 'optional' => true, 'description' => 'A caption for the photo log. Trimmed to 60 characters.'],
            ],
            'returns' => 'The captured photo, which is also queued for your photo log.',
            'notes' => [
                'The drone stabilises for 0.4 s before the shutter fires, so the shot is not smeared. That time is on the mission clock.',
                'Where the drone was standing when it fired is what photo-target objectives are graded on — point the nose at the subject, not just near it.',
                'Photos survive the run: they upload to your photo log and are served through links that expire after 30 minutes.',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Groups
    |--------------------------------------------------------------------------
    |
    | The headings a reference table is broken under, in render order. Keyed
    | by the `group` on each command above.
    |
    */

    'groups' => [
        'flight' => 'Flight',
        'sensors' => 'Sensors',
        'camera' => 'Camera',
    ],

    /*
    |--------------------------------------------------------------------------
    | The Manual
    |--------------------------------------------------------------------------
    |
    | The prose that opens the public reference at /docs — the things true of
    | every mission, which is why they are here rather than repeated in each
    | course's guide in config/course-docs.php.
    |
    | Every claim below describes code that exists and is worded from it:
    | resources/js/lib/simulator/worker.ts is the sandbox, and
    | App\Actions\GradeSimulatorRun is the grader. The scoring section is not
    | here at all — App\Actions\BuildDroneManual builds it from the grader's
    | own constants, because a number written out here would be a number free
    | to drift away from the one a run is actually marked with.
    |
    */

    'manual' => [

        'tagline' => 'Everything the drone can do, and everything a run is graded on.',

        'summary' => 'One reference for the whole simulator: every command a program can issue, a worked example for each idea the courses teach, and the rules every run is scored by. None of it needs an account — read it before you write a line.',

        'concepts' => [
            [
                'title' => 'A program is one async function',
                'body' => 'Every mission runs the same entry point: `async function main(drone)`. The simulator calls it once, hands it the drone, and the run ends when it returns. Declare whatever you like around it — helpers, constants, classes — but that function has to exist, or the run stops before it starts with "Define an async function named main(drone)".',
            ],
            [
                'title' => 'Every command is awaited',
                'body' => 'Each `drone.*` call returns a promise that settles once the physics loop has actually flown the manoeuvre, not when the command is issued. Drop an `await` and the whole program runs in a single frame: the drone lands before it has taken off, and the run scores as though it never flew.',
            ],
            [
                'title' => 'It is ordinary JavaScript',
                'body' => 'Loops, conditionals, functions, arrays and objects all work, because the code is real JavaScript in a real engine. A route is an array you iterate; a search is a `while` that reads a sensor. Nothing here is a scripting language pretending to be one.',
            ],
            [
                'title' => 'Relative and absolute movement',
                'body' => '`moveForward()` and `turn()` fly relative to wherever the nose points; `moveTo()` flies to a coordinate in the world whatever the heading. Briefings give waypoints as absolute coordinates, so those are flown with `moveTo()` — and `moveTo()` will happily fly you into the ground if you hand it a low `y`, so take off first.',
            ],
            [
                'title' => 'Where the code runs',
                'body' => 'Programs run in a dedicated Web Worker: no DOM, no `window`, and `fetch`, `XMLHttpRequest`, `WebSocket` and `EventSource` are removed before the first line executes. `console.log`, `console.warn` and `console.error` do work, and print to the console beside the editor — which is how you see what a program thought it was doing.',
            ],
        ],

    ],

];
