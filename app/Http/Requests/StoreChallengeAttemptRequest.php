<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreChallengeAttemptRequest extends FormRequest
{
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
            'score' => ['required', 'integer', 'min:0'],
            'stars' => ['required', 'integer', 'min:0'],
            'completed' => ['required', 'boolean'],
            'code' => ['required', 'string', 'max:20000'],
        ];
    }

    /**
     * The validated attempt, with each field cast to its native type.
     *
     * @return array{score: int, stars: int, completed: bool, code: string}
     */
    public function attempt(): array
    {
        return [
            'score' => (int) $this->validated('score'),
            'stars' => (int) $this->validated('stars'),
            'completed' => (bool) $this->validated('completed'),
            'code' => (string) $this->validated('code'),
        ];
    }
}
