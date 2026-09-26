<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\CreateChallenge;
use App\Actions\DeleteChallenge;
use App\Actions\UpdateChallenge;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveChallengeRequest;
use App\Models\Challenge;
use App\Models\Course;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Throwable;

/**
 * A course's missions, edited from the course's admin page.
 *
 * The routes bind the mission scoped to the course, so a slug from another
 * course is a 404 before anything here runs. Every write lands back on the
 * course page, which is the only place these forms open from.
 */
final class ChallengeController extends Controller
{
    public function store(SaveChallengeRequest $request, Course $course, CreateChallenge $create): RedirectResponse
    {
        $challenge = $create->handle($course, $request->challenge());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mission :title created.', ['title' => $challenge->title])]);

        return to_route('admin.courses.show', $course);
    }

    public function update(SaveChallengeRequest $request, Course $course, Challenge $challenge, UpdateChallenge $update): RedirectResponse
    {
        $update->handle($challenge, $request->challenge());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mission updated.')]);

        return to_route('admin.courses.show', $course);
    }

    /**
     * @throws Throwable
     */
    public function destroy(Course $course, Challenge $challenge, DeleteChallenge $delete): RedirectResponse
    {
        $delete->handle($challenge);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mission :title deleted.', ['title' => $challenge->title])]);

        return to_route('admin.courses.show', $course);
    }
}
