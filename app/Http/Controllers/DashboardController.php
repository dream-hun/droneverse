<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ChallengeStatus;
use App\Http\Resources\CourseCardResource;
use App\Models\Course;
use App\Models\User;
use App\Models\UserChallengeProgress;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $courses = Course::catalog()->get();

        return Inertia::render('dashboard', [
            'courses' => CourseCardResource::collection(
                $courses,
                UserChallengeProgress::completedCountsByCourse($user),
            ),
            'continue' => $this->continueCard($user),
            'stats' => UserChallengeProgress::statsFor($user),
        ]);
    }

    /**
     * The "pick up where you left off" card, or null with nothing in flight.
     *
     * The card must never link to content the simulator route would 404 on,
     * so only a published challenge in a published course counts.
     *
     * @return array{courseSlug: string, challengeSlug: string, challengeTitle: string}|null
     */
    private function continueCard(User $user): ?array
    {
        $progress = $user->challengeProgress()
            ->with('challenge.course')
            ->where('status', '!=', ChallengeStatus::Completed)
            ->whereRelation('challenge', 'is_published', true)
            ->whereRelation('challenge.course', 'is_published', true)
            ->latest('updated_at')
            ->first();

        $challenge = $progress?->challenge;

        if ($challenge === null || $challenge->course === null) {
            return null;
        }

        return [
            'courseSlug' => $challenge->course->slug,
            'challengeSlug' => $challenge->slug,
            'challengeTitle' => $challenge->title,
        ];
    }
}
