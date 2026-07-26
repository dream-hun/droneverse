<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20000'],
            'collisions' => ['required', 'integer', 'min:0', 'max:10000'],

            'path' => ['required', 'array', 'min:1', 'max:'.self::MAX_PATH_SAMPLES],
            'path.*.t' => ['required', 'numeric', 'min:0', 'max:100000'],
            'path.*.x' => ['required', 'numeric', 'between:-100000,100000'],
            'path.*.y' => ['required', 'numeric', 'between:-100000,100000'],
            'path.*.z' => ['required', 'numeric', 'between:-100000,100000'],

            'photos' => ['present', 'array', 'max:'.self::MAX_PHOTOS],
            'photos.*.x' => ['required', 'numeric', 'between:-100000,100000'],
            'photos.*.y' => ['required', 'numeric', 'between:-100000,100000'],
            'photos.*.z' => ['required', 'numeric', 'between:-100000,100000'],
        ];
    }

    /**
     * Reject a path the simulator could not have recorded.
     *
     * The rules above check that each sample is a point; this checks that
     * the samples are a *recording* — taken in order, at something like the
     * rate the sampler takes them. Both failures describe a client that is
     * not the simulator, so neither can happen to a pilot who flew.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Only worth asking once every sample is known to be a point;
            // otherwise the shape below is not there to read.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var array<int, array{t: mixed}> $path */
            $path = array_values($validator->valid()['path'] ?? []);

            for ($i = 1, $samples = count($path); $i < $samples; $i++) {
                $gap = (float) $path[$i]['t'] - (float) $path[$i - 1]['t'];

                if ($gap <= 0) {
                    $validator->errors()->add('path', 'The flight path is out of order.');

                    return;
                }

                if ($gap > self::MAX_SAMPLE_GAP_SECONDS) {
                    $validator->errors()->add('path', 'The flight path is missing samples.');

                    return;
                }
            }
        });
    }

    /**
     * The validated run, with each field cast to its native type.
     *
     * @return array{path: array<int, array{t: float, x: float, y: float, z: float}>, collisions: int, photos: array<int, array{x: float, y: float, z: float}>, code: string}
     */
    public function run(): array
    {
        /** @var array<int, array{t: mixed, x: mixed, y: mixed, z: mixed}> $path */
        $path = $this->validated('path');

        /** @var array<int, array{x: mixed, y: mixed, z: mixed}> $photos */
        $photos = $this->validated('photos') ?? [];

        return [
            'path' => array_values(array_map(
                fn (array $sample): array => [
                    't' => (float) $sample['t'],
                    'x' => (float) $sample['x'],
                    'y' => (float) $sample['y'],
                    'z' => (float) $sample['z'],
                ],
                $path,
            )),
            'collisions' => (int) $this->validated('collisions'),
            'photos' => array_values(array_map(
                fn (array $photo): array => [
                    'x' => (float) $photo['x'],
                    'y' => (float) $photo['y'],
                    'z' => (float) $photo['z'],
                ],
                $photos,
            )),
            'code' => (string) $this->validated('code'),
        ];
    }
}
