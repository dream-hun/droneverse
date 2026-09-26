<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Plan;
use App\Models\Course;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A course's catalogue entry, for creating one and editing one.
 *
 * The slug is the course's public URL and the key its written guide is filed
 * under in config/course-docs.php, so it is held to the shape a URL segment
 * wants and left for the author to choose rather than derived silently from a
 * title that may change.
 */
final class SaveCourseRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $course = $this->route('course');

        return [
            'title' => ['required', 'string', 'max:120'],
            'slug' => [
                'required',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique(Course::class, 'slug')->ignore($course instanceof Course ? $course->id : null),
            ],
            'description' => ['required', 'string', 'max:1000'],
            'difficulty' => ['required', 'string', 'max:40'],
            'required_plan' => ['required', Rule::enum(Plan::class)],
            'order' => ['required', 'integer', 'min:0', 'max:1000'],
            'is_published' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => __('Use lower-case letters, numbers and single hyphens, like drone-basics.'),
        ];
    }

    /**
     * @return array{title: string, slug: string, description: string, difficulty: string, required_plan: string, order: int, is_published: bool}
     */
    public function course(): array
    {
        $input = $this->safe();

        return [
            'title' => $input->string('title')->value(),
            'slug' => $input->string('slug')->value(),
            'description' => $input->string('description')->value(),
            'difficulty' => $input->string('difficulty')->value(),
            'required_plan' => $input->string('required_plan')->value(),
            'order' => $input->integer('order'),
            'is_published' => $input->boolean('is_published'),
        ];
    }
}
