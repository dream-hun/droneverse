<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Plan;
use App\Models\Challenge;
use App\Models\Course;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A mission, for creating one and editing one.
 *
 * The world and the grading rules arrive as JSON text, because that is how
 * they are authored, and are held to the shape the simulator flies to — the
 * EnvironmentConfig and SuccessCriteria types in resources/js/types/
 * simulator.ts, which App\Models\Challenge's docblock mirrors. Valid JSON is
 * not enough: a mission saved without a start position or a time limit is a
 * page that throws for every pilot who opens it, and this is the last place
 * that can be caught before one does.
 *
 * Only the keys the simulator cannot do without are required. Everything it
 * treats as optional — obstacles, props, wind, photo targets — stays optional
 * here, so this does not become a second, stricter copy of the contract that
 * drifts from the first.
 */
final class SaveChallengeRequest extends FormRequest
{
    /**
     * A pilot's submission is capped at this many characters, so a starter
     * longer than it could not be flown even untouched.
     */
    private const int MAX_CODE_LENGTH = 20000;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $course = $this->route('course');
        $challenge = $this->route('challenge');

        return [
            'title' => ['required', 'string', 'max:120'],
            'slug' => [
                'required',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique(Challenge::class, 'slug')
                    ->where('course_id', $course instanceof Course ? $course->id : 0)
                    ->ignore($challenge instanceof Challenge ? $challenge->id : null),
            ],
            'briefing' => ['required', 'string', 'max:10000'],
            'order' => ['required', 'integer', 'min:0', 'max:1000'],
            'difficulty' => ['required', 'string', 'max:40'],
            'required_plan' => ['nullable', Rule::enum(Plan::class)],
            'starter_code' => ['required', 'string', 'max:'.self::MAX_CODE_LENGTH],
            'solution_code' => ['nullable', 'string', 'max:'.self::MAX_CODE_LENGTH],
            'environment' => ['required', 'string', 'json'],
            'success_criteria' => ['required', 'string', 'json'],
            'max_score' => ['required', 'integer', 'min:1', 'max:1000'],
            'is_published' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => __('Use lower-case letters, numbers and single hyphens, like hover-and-land.'),
            'slug.unique' => __('Another mission in this course already uses that slug.'),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->checkShape($validator, 'environment', [
                'start' => ['required', 'array'],
                'start.x' => ['required', 'numeric'],
                'start.y' => ['required', 'numeric'],
                'start.z' => ['required', 'numeric'],
                'start.yaw' => ['required', 'numeric'],
                'bounds' => ['required', 'array'],
                'bounds.width' => ['required', 'numeric', 'gt:0'],
                'bounds.depth' => ['required', 'numeric', 'gt:0'],
                'bounds.height' => ['required', 'numeric', 'gt:0'],
                'goal' => ['required', 'array'],
                'goal.x' => ['required', 'numeric'],
                'goal.z' => ['required', 'numeric'],
                'goal.radius' => ['required', 'numeric', 'gt:0'],
                'gates' => ['present', 'array'],
                'waypoints' => ['present', 'array'],
                'obstacles' => ['sometimes', 'array'],
            ]),
            fn (Validator $validator) => $this->checkShape($validator, 'success_criteria', [
                'type' => ['required', Rule::in(['waypoints', 'gates'])],
                'waypoints' => ['present', 'array'],
                'avoid_collisions' => ['required', 'boolean'],
                'max_time_seconds' => ['required', 'numeric', 'gt:0'],
                'landing_required' => ['required', 'boolean'],
                'min_altitude' => ['sometimes', 'numeric'],
                'min_photos' => ['sometimes', 'integer', 'min:0'],
                'photo_targets' => ['sometimes', 'array'],
                'wash_required' => ['sometimes', 'boolean'],
            ]),
        ];
    }

    /**
     * The validated mission, with the JSON decoded for the array casts.
     *
     * @return array{title: string, slug: string, briefing: string, order: int, difficulty: string, required_plan: string|null, starter_code: string, solution_code: string|null, environment: array<mixed>, success_criteria: array<mixed>, max_score: int, is_published: bool}
     */
    public function challenge(): array
    {
        $input = $this->safe();

        return [
            'title' => $input->string('title')->value(),
            'slug' => $input->string('slug')->value(),
            'briefing' => $input->string('briefing')->value(),
            'order' => $input->integer('order'),
            'difficulty' => $input->string('difficulty')->value(),
            'required_plan' => $input->filled('required_plan') ? $input->string('required_plan')->value() : null,
            'starter_code' => $input->string('starter_code')->value(),
            'solution_code' => $input->filled('solution_code') ? $input->string('solution_code')->value() : null,
            'environment' => $this->decoded('environment') ?? [],
            'success_criteria' => $this->decoded('success_criteria') ?? [],
            'max_score' => $input->integer('max_score'),
            'is_published' => $input->boolean('is_published'),
        ];
    }

    /**
     * Validate a decoded JSON field against the simulator's shape and report
     * what is missing under the field it was typed into, so the author sees
     * "start.x is required" beside the textarea they have to fix.
     *
     * @param  array<string, array<mixed>>  $rules
     */
    private function checkShape(Validator $validator, string $field, array $rules): void
    {
        if ($validator->errors()->has($field)) {
            return;
        }

        $decoded = $this->decoded($field);

        if ($decoded === null || array_is_list($decoded) && $decoded !== []) {
            $validator->errors()->add($field, __('This must be a JSON object.'));

            return;
        }

        $shape = ValidatorFactory::make($decoded, $rules);

        foreach ($shape->errors()->all() as $message) {
            $validator->errors()->add($field, $message);
        }
    }

    /**
     * @return array<mixed>|null
     */
    private function decoded(string $field): ?array
    {
        $value = $this->input($field);

        if (! is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
