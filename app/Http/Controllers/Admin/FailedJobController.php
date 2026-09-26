<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\ForgetFailedJob;
use App\Actions\RetryFailedJob;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final class FailedJobController extends Controller
{
    public function retry(string $failedJob, RetryFailedJob $retry): RedirectResponse
    {
        Inertia::flash('toast', $retry->handle($failedJob)
            ? ['type' => 'success', 'message' => __('The job is back on the queue.')]
            : ['type' => 'error', 'message' => __('That job is no longer in the failed list.')]);

        return back();
    }

    public function destroy(string $failedJob, ForgetFailedJob $forget): RedirectResponse
    {
        Inertia::flash('toast', $forget->handle($failedJob)
            ? ['type' => 'success', 'message' => __('Failed job discarded.')]
            : ['type' => 'error', 'message' => __('That job is no longer in the failed list.')]);

        return back();
    }
}
