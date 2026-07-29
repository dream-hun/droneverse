<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\StartCheckout;
use App\Http\Requests\CheckoutRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class CheckoutController extends Controller
{
    /**
     * Open a Paddle checkout for the plan the pilot picked.
     *
     * Answers JSON rather than an Inertia response: the pricing page stays
     * where it is and hands the option bag straight to the Paddle.js overlay,
     * so a pilot who closes the overlay is back on the page they were reading
     * rather than on a re-rendered copy of it.
     */
    public function store(CheckoutRequest $request, StartCheckout $checkout, #[CurrentUser] User $user): JsonResponse
    {
        $plan = $request->plan();

        $options = $checkout->handle($user, $plan, $request->variant());

        if ($options === null) {
            /*
             * Reached by an unsold billing period, a sales-led tier, or a price
             * ID this environment has not configured. All three are "you cannot
             * buy this", and none of them should say which — the difference is
             * our configuration, not the buyer's business.
             */
            throw ValidationException::withMessages([
                'plan' => __(':plan is not available for purchase right now.', ['plan' => $plan->label()]),
            ]);
        }

        return response()->json(['checkout' => $options]);
    }
}
