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
 * Note what is not here: a price, an amount, a Kelviq plan identifier or a
 * customer. The buyer names a tier and a billing period and nothing else; the
 * customer is the signed-in pilot and what they are charged is Kelviq's answer.
 *
 * The `variant` field below is this application's sense of the word — a billing
 * period such as `monthly`. App\Enums\Plan::chargePeriod() maps it onto the
 * Kelviq charge period, and refuses any period the plan does not sell.
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
             * the shape. App\Actions\StartCheckout refuses a period the plan
             * does not sell.
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
         * should fail: Starter is not self-serve, so StartCheckout refuses it
         * and nobody is charged for a plan we misread.
         */
        return $plan instanceof Plan ? $plan : Plan::Starter;
    }

    public function variant(): string
    {
        return $this->string('variant')->toString();
    }
}
