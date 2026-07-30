<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A finished simulator run, as flown.
 *
 * Note what this no longer accepts: a score, a star count, or a claim of
 * completion. The browser reports where the drone went and what hit it;
 * {@see \App\Actions\ReconstructRunTelemetry} works out what that achieved
 * and {@see \App\Actions\GradeSimulatorRun} decides what it was worth.
 */
final class StoreChallengeAttemptRequest extends FormRequest
{
    /**
     * Samples accepted in one flight path.
     *
     * The client samples at a fixed rate and the longest authored mission is
     * a couple of minutes, so this sits far above any real run while keeping
     * both the payload and the geometry work it feeds bounded.
     */
    private const int MAX_PATH_SAMPLES = 4000;

    /** Well above what any single mission asks a pilot to shoot. */
    private const int MAX_PHOTOS = 60;

    /**
     * Longest gap allowed between two consecutive path samples.
     *
     * telemetry.ts samples every 0.05s, and slower than that only when the
     * browser is rendering slower than that — the sampler runs off the
     * frame loop, so the gap is the frame time once frames get long. One
     * second is therefore a run limping along at 1 fps, twenty times worse
     * than the worst honest submission, and still accepted.
     *
     * What it does rule out is a path with no cadence at all: a submission
     * has to carry roughly a sample per second of the flight it claims, so
     * the handful of points that would otherwise be enough to sit on every
     * waypoint is not a run any more.
     */
    private const float MAX_SAMPLE_GAP_SECONDS = 1.0;

    /** Coordinate envelope a sample has to sit inside to be a place on a map. */
    private const float MAX_COORDINATE = 100000.0;

    /** Ceiling on a sample's timestamp, matching the coordinate envelope. */
    private const float MAX_TIMESTAMP = 100000.0;

    /**
     * The path and photos, validated and cast, built during validation.
     *
     * Checking a sample and casting it are the same walk over the same data,
     * so the walk happens once and {@see self::run()} is handed what it
     * produced.
     *
     * @var array{path: array<int, array{t: float, x: float, y: float, z: float}>, photos: array<int, array{x: float, y: float, z: float}>}|null
     */
    private ?array $flight = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The path is checked by one closure over the whole array rather than by
     * `path.*.x` rules. A wildcard rule is expanded per matching key before
     * anything is validated, so four rules across four thousand samples
     * become sixteen thousand attributes, each carrying three or four rule
     * objects, each resolved through the validator's message and attribute
     * machinery — and then rebuilt again by every `validated()` call. On a
     * full-length path that measured 2.5 seconds of CPU and 10 MB, to guard
     * geometry that costs under 4 ms. The same checks as one pass over the
     * array cost about a millisecond.
     *
     * `bail` matters on both arrays: it keeps the closure off a value that
     * the preceding rules have already found is not a countable list.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20000'],
            'collisions' => ['required', 'integer', 'min:0', 'max:10000'],

            'path' => [
                'bail', 'required', 'array', 'min:1', 'max:'.self::MAX_PATH_SAMPLES,
                $this->validateFlightPath(...),
            ],

            'photos' => [
                'bail', 'present', 'array', 'max:'.self::MAX_PHOTOS,
                $this->validatePhotoPositions(...),
            ],
        ];
    }

    /**
     * The validated run, with each field cast to its native type.
     *
     * @return array{path: array<int, array{t: float, x: float, y: float, z: float}>, collisions: int, photos: array<int, array{x: float, y: float, z: float}>, code: string}
     */
    public function run(): array
    {
        $flight = $this->flight ?? ['path' => [], 'photos' => []];

        // Once, not once per field: `validated()` rebuilds the whole validated
        // set on every call, and this one carries the path.
        $validated = $this->validated();

        return [
            'path' => $flight['path'],
            'collisions' => (int) $validated['collisions'],
            'photos' => $flight['photos'],
            'code' => (string) $validated['code'],
        ];
    }

    /**
     * Check that the path is a recording, and keep it cast as we go.
     *
     * Every sample has to be a point, and the samples together have to be a
     * *recording*: taken in order, at something like the rate the sampler
     * takes them. Both failures describe a client that is not the simulator,
     * so neither can happen to a pilot who flew.
     *
     * @param  array<mixed>  $value
     */
    private function validateFlightPath(string $attribute, mixed $value, Closure $fail): void
    {
        $path = [];
        $previousTime = null;

        foreach ($value as $sample) {
            if (! is_array($sample)) {
                $fail('The flight path is not a sequence of samples.');

                return;
            }

            $time = $this->coordinate($sample['t'] ?? null, 0.0, self::MAX_TIMESTAMP);
            $x = $this->coordinate($sample['x'] ?? null, -self::MAX_COORDINATE, self::MAX_COORDINATE);
            $y = $this->coordinate($sample['y'] ?? null, -self::MAX_COORDINATE, self::MAX_COORDINATE);
            $z = $this->coordinate($sample['z'] ?? null, -self::MAX_COORDINATE, self::MAX_COORDINATE);

            if ($time === null || $x === null || $y === null || $z === null) {
                $fail('The flight path contains a sample that is not a point in time and space.');

                return;
            }

            if ($previousTime !== null) {
                $gap = $time - $previousTime;

                if ($gap <= 0) {
                    $fail('The flight path is out of order.');

                    return;
                }

                if ($gap > self::MAX_SAMPLE_GAP_SECONDS) {
                    $fail('The flight path is missing samples.');

                    return;
                }
            }

            $previousTime = $time;
            $path[] = ['t' => $time, 'x' => $x, 'y' => $y, 'z' => $z];
        }

        $this->flight = ['path' => $path, 'photos' => $this->flight['photos'] ?? []];
    }

    /**
     * Check that every claimed photo was taken somewhere on the map.
     *
     * @param  array<mixed>  $value
     */
    private function validatePhotoPositions(string $attribute, mixed $value, Closure $fail): void
    {
        $photos = [];

        foreach ($value as $photo) {
            if (! is_array($photo)) {
                $fail('The photo list is not a sequence of positions.');

                return;
            }

            $x = $this->coordinate($photo['x'] ?? null, -self::MAX_COORDINATE, self::MAX_COORDINATE);
            $y = $this->coordinate($photo['y'] ?? null, -self::MAX_COORDINATE, self::MAX_COORDINATE);
            $z = $this->coordinate($photo['z'] ?? null, -self::MAX_COORDINATE, self::MAX_COORDINATE);

            if ($x === null || $y === null || $z === null) {
                $fail('A photo was not taken at a point in space.');

                return;
            }

            $photos[] = ['x' => $x, 'y' => $y, 'z' => $z];
        }

        $this->flight = ['path' => $this->flight['path'] ?? [], 'photos' => $photos];
    }

    /**
     * One numeric field, cast, or null if it is not a number in range.
     *
     * `is_numeric` is the same test `numeric` applies, and the bounds are the
     * same ones `between` applies; doing both here is what lets the whole
     * sample be settled without building a rule object for it.
     */
    private function coordinate(mixed $value, float $min, float $max): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        if (is_nan($number) || $number < $min || $number > $max) {
            return null;
        }

        return $number;
    }
}
