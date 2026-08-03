<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Plan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A request to move an existing subscription onto another plan.
 *
 * The same two fields CheckoutRequest carries, and for the same reason: a pilot
 * names a tier and a billing period, and what that costs is
 * App\Actions\ResolveCheckoutPrice's answer alone. Nothing here names a price,
 * an amount or a Lemon Squeezy variant ID, so no request can reprice itself into
 * a plan it is not paying for.
 *
 * Which subscription is being changed is not a field either. It is the
 * authenticated pilot's own, found by App\Queries\DefaultSubscription, so there
 * is no identifier here for one account to substitute for another's.
 */
final class ChangePlanRequest extends FormRequest
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
             * Which periods are valid depends on the plan, so this checks only
             * the shape. ResolveCheckoutPrice rejects a period the plan does not
             * sell, and it has to anyway — it is reached from places that never
             * see an HTTP request.
             */
            'variant' => ['required', 'string', 'max:32'],
        ];
    }

    public function plan(): Plan
    {
        $plan = $this->enum('plan', Plan::class);

        /*
         * Unreachable past the enum rule above. It falls back to Starter rather
         * than to a paid tier because that is the direction a bug here should
         * fail: Starter is not self-serve, so ResolveCheckoutPrice refuses it
         * and no subscription is repriced onto a plan we misread.
         */
        return $plan instanceof Plan ? $plan : Plan::Starter;
    }

    public function variant(): string
    {
        return $this->string('variant')->toString();
    }
}
