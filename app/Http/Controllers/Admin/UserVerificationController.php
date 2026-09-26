<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\VerifyUserEmail;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class UserVerificationController extends Controller
{
    /**
     * Mark an account's address as verified on the pilot's behalf.
     *
     * Guarded like an edit, because it is one: vouching for a staff member's
     * address is exactly the step an address-swap takeover would need.
     */
    public function store(User $user, VerifyUserEmail $verify): RedirectResponse
    {
        Gate::authorize('update', $user);

        $verified = $verify->handle($user);

        Inertia::flash('toast', [
            'type' => $verified ? 'success' : 'info',
            'message' => $verified ? __('Email address marked as verified.') : __('That address was already verified.'),
        ]);

        return back();
    }
}
