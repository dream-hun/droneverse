<?php

declare(strict_types=1);

namespace App\Http\Requests;

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
