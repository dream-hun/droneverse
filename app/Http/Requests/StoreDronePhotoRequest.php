<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StoreDronePhotoRequest extends FormRequest
{
    /**
     * Base64 data URLs inflate bytes by ~4/3, so this allows roughly a
     * 1.5 MB image — far above what the simulator's 1280px JPEG produces.
     */
    private const int MAX_DATA_URL_LENGTH = 2_000_000;

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
            'image' => [
                'required',
                'string',
                'max:'.self::MAX_DATA_URL_LENGTH,
                'regex:#^data:image/(jpeg|png);base64,[A-Za-z0-9+/=]+$#',
            ],
            'label' => ['nullable', 'string', 'max:60'],
            'x' => ['nullable', 'numeric', 'between:-1000,1000'],
            'y' => ['nullable', 'numeric', 'between:-1000,1000'],
            'z' => ['nullable', 'numeric', 'between:-1000,1000'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
        ];
    }

    /**
     * The validated photo payload, with each field cast to its native type.
     *
     * @return array{image: string, label: string|null, position: array{x: float, y: float, z: float, headingDeg: float}|null}
     */
    public function photo(): array
    {
        $input = $this->safe();

        return [
            'image' => $input->string('image')->value(),
            'label' => $input->filled('label') ? $input->string('label')->value() : null,
            'position' => $input->filled(['x', 'y', 'z']) ? [
                'x' => round($input->float('x'), 2),
                'y' => round($input->float('y'), 2),
                'z' => round($input->float('z'), 2),
                'headingDeg' => round($input->float('heading'), 1),
            ] : null,
        ];
    }
}
