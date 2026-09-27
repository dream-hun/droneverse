<?php

declare(strict_types=1);

use App\Models\Challenge;
use App\Models\ChallengeRun;
use App\Models\Course;
use App\Models\DroneModel;
use App\Models\PilotCourseTotals;
use App\Models\PilotMissionStats;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Models\UserChallengeProgress;
use App\Models\UserQuizProgress;

/**
 * The relationships nothing in the app reads yet.
 *
 * Each one names its own foreign key or morph pair, and a wrong key resolves
 * quietly to nothing — so each is read back against the record it was built
 * from.
 */
test('a record resolves the owner it was built for', function (bool $resolvesToOwner): void {
    expect($resolvesToOwner)->toBeTrue();
})->with([
    "a run's airframe" => [function (): bool {
        $drone = DroneModel::factory()->create();
        $run = ChallengeRun::factory()->create(['drone_model_id' => $drone->id]);

        return $run->drone?->is($drone) === true;
    }],
    "a progress row's pilot" => [function (): bool {
        $user = User::factory()->create();
        $progress = UserChallengeProgress::factory()->create(['user_id' => $user->id]);

        return $progress->user?->is($user) === true;
    }],
    "a progress row's airframe" => [function (): bool {
        $drone = DroneModel::factory()->create();
        $progress = UserChallengeProgress::factory()->create(['drone_model_id' => $drone->id]);

        return $progress->drone?->is($drone) === true;
    }],
    "a quiz progress row's pilot" => [function (): bool {
        $user = User::factory()->create();
        $progress = UserQuizProgress::factory()->create(['user_id' => $user->id]);

        return $progress->user?->is($user) === true;
    }],
    "a quiz attempt's pilot" => [function (): bool {
        $user = User::factory()->create();
        $attempt = QuizAttempt::factory()->create(['user_id' => $user->id]);

        return $attempt->user?->is($user) === true;
    }],
    "a quiz attempt's quiz" => [function (): bool {
        $quiz = Quiz::factory()->create();
        $attempt = QuizAttempt::factory()->create(['quiz_id' => $quiz->id]);

        return $attempt->quiz?->is($quiz) === true;
    }],
    "a course total's pilot" => [function (): bool {
        $user = User::factory()->create();
        $totals = PilotCourseTotals::factory()->create(['user_id' => $user->id]);

        return $totals->user?->is($user) === true;
    }],
    "a course total's course" => [function (): bool {
        $course = Course::factory()->create();
        $totals = PilotCourseTotals::factory()->create(['course_id' => $course->id]);

        return $totals->course?->is($course) === true;
    }],
    "a mission stat's pilot" => [function (): bool {
        $user = User::factory()->create();
        $stats = PilotMissionStats::factory()->create(['user_id' => $user->id]);

        return $stats->user?->is($user) === true;
    }],
    "a mission stat's mission" => [function (): bool {
        $challenge = Challenge::factory()->create();
        $stats = PilotMissionStats::factory()->create(['challenge_id' => $challenge->id]);

        return $stats->challenge?->is($challenge) === true;
    }],
]);

test("a pilot's quiz attempts are theirs alone", function (): void {
    $user = User::factory()->create();
    $attempt = QuizAttempt::factory()->create(['user_id' => $user->id]);
    QuizAttempt::factory()->create();

    expect($user->quizAttempts->modelKeys())->toBe([$attempt->id]);
});

test('courses, missions and quizzes are addressed by slug', function (Course|Challenge|Quiz $record): void {
    expect($record->getRouteKey())->toBe($record->slug);
})->with([
    'a course' => [fn (): Course => Course::factory()->create()],
    'a mission' => [fn (): Challenge => Challenge::factory()->create()],
    'a quiz' => [fn (): Quiz => Quiz::factory()->create()],
]);

test('airframes and quiz attempts are addressed by uuid', function (DroneModel|QuizAttempt $record): void {
    expect($record->getRouteKey())->toBeUuid()->toBe($record->uuid);
})->with([
    'an airframe' => [fn (): DroneModel => DroneModel::factory()->create()],
    'a quiz attempt' => [fn (): QuizAttempt => QuizAttempt::factory()->create()],
]);
