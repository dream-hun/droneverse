<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Plan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A request to open checkout on a plan.
 *
 * Note what is not here: a price, an amount or a Paddle price ID. The buyer
 * names a tier and a billing period and nothing else; what that costs is
 * App\Actions\ResolveCheckoutPrice's answer alone.
 */
final class CheckoutRequest extends FormRequest
{
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
            'plan' => ['required', Rule::enum(Plan::class)],
            /*
             * Which variants are valid depends on the plan, so this only checks
             * the shape. ResolveCheckoutPrice rejects a period the plan does
             * not sell, and it has to anyway — it is reached from places that
             * never see an HTTP request.
             */
            'variant' => ['required', 'string', 'max:32'],
        ];
    }

    public function plan(): Plan
    {
        return Plan::from((string) $this->validated('plan'));
    }

    public function variant(): string
    {
        return (string) $this->validated('variant');
    }
}
