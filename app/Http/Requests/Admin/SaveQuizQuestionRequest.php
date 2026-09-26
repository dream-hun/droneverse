<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A question and its answers, for creating one and editing one.
 *
 * At least two answers, because one is not a choice, and at least one marked
 * correct, because a question with none can never be earned — the grader
 * treats it as unanswerable rather than free, so it would silently cost every
 * pilot a point. Whether it is a single- or multiple-answer question is not
 * asked; it follows from how many answers are marked correct.
 */
final class SaveQuizQuestionRequest extends FormRequest
{
    /** Enough for any honest multiple choice, few enough to read on a phone. */
    private const int MAX_OPTIONS = 8;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:2000'],
            'explanation' => ['nullable', 'string', 'max:4000'],
            'order' => ['required', 'integer', 'min:0', 'max:1000'],
            'options' => ['required', 'array', 'min:2', 'max:'.self::MAX_OPTIONS],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.label' => ['required', 'string', 'max:500'],
            'options.*.is_correct' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'options.min' => __('Give at least two answers to choose between.'),
            'options.*.label.required' => __('Every answer needs some text.'),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('options') || $validator->errors()->has('options.*')) {
                    return;
                }

                if (array_filter($this->options(), static fn (array $option): bool => $option['is_correct']) === []) {
                    $validator->errors()->add('options', __('Mark at least one answer as correct.'));
                }
            },
        ];
    }

    /**
     * @return array{prompt: string, explanation: string|null, order: int, options: array<int, array{id: int|null, label: string, is_correct: bool}>}
     */
    public function question(): array
    {
        $input = $this->safe();

        return [
            'prompt' => $input->string('prompt')->value(),
            'explanation' => $input->filled('explanation') ? $input->string('explanation')->value() : null,
            'order' => $input->integer('order'),
            'options' => $this->options(),
        ];
    }

    /**
     * The answers in the order they were sent, re-indexed.
     *
     * The form names them by position and a removed row leaves a gap, so the
     * keys that arrive are not a list; their order is the author's order.
     *
     * @return array<int, array{id: int|null, label: string, is_correct: bool}>
     */
    private function options(): array
    {
        $options = $this->input('options', []);

        if (! is_array($options)) {
            return [];
        }

        $normalised = [];

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $id = $option['id'] ?? null;
            $label = $option['label'] ?? '';

            $normalised[] = [
                'id' => is_numeric($id) ? (int) $id : null,
                'label' => is_string($label) ? mb_trim($label) : '',
                'is_correct' => filter_var($option['is_correct'] ?? false, FILTER_VALIDATE_BOOL),
            ];
        }

        return $normalised;
    }
}
