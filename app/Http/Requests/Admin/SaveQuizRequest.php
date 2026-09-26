<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Plan;
use App\Models\Course;
use App\Models\Quiz;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A quiz's details, for creating one and editing one.
 *
 * The slug is unique within its course only, as a mission's is, and the pass
 * mark is a whole percentage: App\Models\Quiz::isPassedBy() compares against
 * it, and a bar of zero would pass a pilot who answered nothing.
 */
final class SaveQuizRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $course = $this->route('course');
        $quiz = $this->route('quiz');

        return [
            'title' => ['required', 'string', 'max:120'],
            'slug' => [
                'required',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique(Quiz::class, 'slug')
                    ->where('course_id', $course instanceof Course ? $course->id : 0)
                    ->ignore($quiz instanceof Quiz ? $quiz->id : null),
            ],
            'description' => ['required', 'string', 'max:1000'],
            'order' => ['required', 'integer', 'min:0', 'max:1000'],
            'required_plan' => ['nullable', Rule::enum(Plan::class)],
            'pass_percentage' => ['required', 'integer', 'min:1', 'max:100'],
            'is_published' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => __('Use lower-case letters, numbers and single hyphens, like final-check.'),
            'slug.unique' => __('Another quiz in this course already uses that slug.'),
        ];
    }

    /**
     * @return array{title: string, slug: string, description: string, order: int, required_plan: string|null, pass_percentage: int, is_published: bool}
     */
    public function quiz(): array
    {
        $input = $this->safe();

        return [
            'title' => $input->string('title')->value(),
            'slug' => $input->string('slug')->value(),
            'description' => $input->string('description')->value(),
            'order' => $input->integer('order'),
            'required_plan' => $input->filled('required_plan') ? $input->string('required_plan')->value() : null,
            'pass_percentage' => $input->integer('pass_percentage'),
            'is_published' => $input->boolean('is_published'),
        ];
    }
}
