<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\BuildBillingSummary;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Inertia\Inertia;
use Inertia\Response;

final class BillingController extends Controller
{
    /**
     * Show the pilot's plan, renewal date and receipts.
     *
     * Every prop is spread from BuildBillingSummary, which reads only local
     * tables — this page reaches no third party, so it renders the same in an
     * environment with no Creem credentials as in one with them.
     */
    public function edit(BuildBillingSummary $summary, #[CurrentUser] User $user): Response
    {
        return Inertia::render('settings/billing', $summary->handle($user));
    }
}
