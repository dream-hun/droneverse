<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Concerns\CourseContent;
use App\Enums\Plan;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The catalogue's access rules, asserted once against both kinds of content.
 *
 * {@see CourseContent} exists because a mission and a quiz answered these
 * questions in two separately-maintained copies that were only required to
 * agree by a comment. Every case here therefore runs against both models: a
 * rule that stops holding for one of them is the drift the trait was
 * extracted to make impossible, and it should fail here rather than as a
 * mission nobody could open or a quiz anyone could.
 *
 * The per-model tests still cover what only one of them does — the solution
 * unlock in ChallengeTest, the pass mark in QuizTest.
 */
final class CourseContentAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Both models, as a name and a factory-backed maker.
     *
     * @return array<string, array{0: callable(Course, array<string, mixed>): (Challenge|Quiz)}>
     */
    public static function content(): array
    {
        return [
            'challenge' => [fn (Course $course, array $attributes): Challenge => Challenge::factory()
                ->for($course)
                ->create($attributes)],
            'quiz' => [fn (Course $course, array $attributes): Quiz => Quiz::factory()
                ->for($course)
                ->create($attributes)],
        ];
    }

    /**
     * @param  callable(Course, array<string, mixed>): (Challenge|Quiz)  $make
     */
    #[DataProvider('content')]
    public function test_published_content_in_a_published_course_is_available(callable $make): void
    {
        $course = Course::factory()->create(['is_published' => true]);

        $this->assertTrue($make($course, ['is_published' => true])->isAvailableIn($course));
    }

    /**
     * @param  callable(Course, array<string, mixed>): (Challenge|Quiz)  $make
     */
    #[DataProvider('content')]
    public function test_unpublished_content_is_not_available(callable $make): void
    {
        $course = Course::factory()->create(['is_published' => true]);

        $this->assertFalse($make($course, ['is_published' => false])->isAvailableIn($course));
    }

    /**
     * Publishing content inside a course that is not live does not leak it.
     *
     * @param  callable(Course, array<string, mixed>): (Challenge|Quiz)  $make
     */
    #[DataProvider('content')]
    public function test_content_in_an_unpublished_course_is_not_available(callable $make): void
    {
        $course = Course::factory()->create(['is_published' => false]);

        $this->assertFalse($make($course, ['is_published' => true])->isAvailableIn($course));
    }

    /**
     * The check a URL carrying two independent slugs exists for.
     *
     * @param  callable(Course, array<string, mixed>): (Challenge|Quiz)  $make
     */
    #[DataProvider('content')]
    public function test_content_is_not_available_in_a_course_it_does_not_belong_to(callable $make): void
    {
        $owner = Course::factory()->create(['is_published' => true]);
        $other = Course::factory()->create(['is_published' => true]);

        $this->assertFalse($make($owner, ['is_published' => true])->isAvailableIn($other));
    }

    /**
     * @param  callable(Course, array<string, mixed>): (Challenge|Quiz)  $make
     */
    #[DataProvider('content')]
    public function test_content_without_a_plan_of_its_own_inherits_its_courses(callable $make): void
    {
        $course = Course::factory()->create(['required_plan' => Plan::Pro->value]);

        $this->assertSame(
            Plan::Pro,
            $make($course, ['required_plan' => null])->requiredPlanIn($course),
        );
    }

    /**
     * How a Starter course can hold Pro-only content.
     *
     * @param  callable(Course, array<string, mixed>): (Challenge|Quiz)  $make
     */
    #[DataProvider('content')]
    public function test_content_with_a_plan_of_its_own_overrides_its_courses(callable $make): void
    {
        $course = Course::factory()->create(['required_plan' => Plan::Starter->value]);

        $this->assertSame(
            Plan::Pro,
            $make($course, ['required_plan' => Plan::Pro->value])->requiredPlanIn($course),
        );
    }

    /**
     * @param  callable(Course, array<string, mixed>): (Challenge|Quiz)  $make
     */
    #[DataProvider('content')]
    public function test_a_guest_is_treated_as_a_starter_pilot(callable $make): void
    {
        $course = Course::factory()->create(['required_plan' => Plan::Starter->value]);
        $starter = $make($course, ['required_plan' => Plan::Starter->value]);
        $pro = $make($course, ['required_plan' => Plan::Pro->value]);

        $this->assertTrue($starter->isUnlockedFor(null, $course));
        $this->assertFalse($pro->isUnlockedFor(null, $course));
    }

    /**
     * @param  callable(Course, array<string, mixed>): (Challenge|Quiz)  $make
     */
    #[DataProvider('content')]
    public function test_a_plan_unlocks_the_tiers_it_covers(callable $make): void
    {
        $course = Course::factory()->create(['required_plan' => Plan::Starter->value]);
        $pro = $make($course, ['required_plan' => Plan::Pro->value]);

        $starterPilot = User::factory()->create(['plan_override' => Plan::Starter->value]);
        $proPilot = User::factory()->create(['plan_override' => Plan::Pro->value]);

        $this->assertFalse($pro->isUnlockedFor($starterPilot, $course));
        $this->assertTrue($pro->isUnlockedFor($proPilot, $course));
    }

    /**
     * A stored plan name that no longer names a tier degrades open.
     *
     * The catalogue should keep working when a tier is renamed or retired,
     * and the fallback runs through the course, so it is worth pinning that
     * both kinds of content fall back the same way.
     *
     * @param  callable(Course, array<string, mixed>): (Challenge|Quiz)  $make
     */
    #[DataProvider('content')]
    public function test_an_unrecognised_plan_name_falls_back_to_starter(callable $make): void
    {
        $course = Course::factory()->create(['required_plan' => 'legacy-tier']);

        $this->assertSame(
            Plan::Starter,
            $make($course, ['required_plan' => 'legacy-tier'])->requiredPlanIn($course),
        );
    }
}
