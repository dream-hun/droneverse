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
        $plan = $this->enum('plan', Plan::class);

        /*
         * The enum rule above has already rejected anything Plan cannot be
         * built from, so the fallback is unreachable. It resolves to Starter
         * rather than to a paid tier because that is the direction a bug here
         * should fail: Starter is not self-serve, so ResolveCheckoutPrice
         * refuses it and nobody is charged for a plan we misread.
         */
        return $plan instanceof Plan ? $plan : Plan::Starter;
    }

    public function variant(): string
    {
        return $this->string('variant')->toString();
    }
}
