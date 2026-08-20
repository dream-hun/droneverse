<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Course;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The whole reference, assembled for the public manual at /docs.
 *
 * A course guide answers "what does this course need"; this answers "what is
 * there". Every command in config/drone-api.php rather than the eight or ten a
 * course leans on, every worked example and every pitfall written for any
 * course, and the scoring policy — which no course guide states, and which a
 * pilot has until now been able to learn only by losing points to it.
 *
 * Nothing here is authored twice. The commands come from the same reference
 * the guides render through {@see DescribeDroneCommands}, the examples and
 * pitfalls are lifted from config/course-docs.php exactly as written, and the
 * scoring numbers are read off {@see GradeSimulatorRun} rather than restated —
 * a manual that disagreed with the grader would be worse than no manual.
 */
final readonly class BuildDroneManual
{
    public function __construct(private DescribeDroneCommands $commands)
    {
        //
    }

    /**
     * @return array{
     *     tagline: string,
     *     summary: string,
     *     concepts: array<int, array{title: string, body: string}>,
     *     commandGroups: array<int, array{key: string, label: string, commands: array<int, array<string, mixed>>}>,
     *     examples: array<int, array{course: array{title: string, slug: string, published: bool}, items: array<int, array{slug: string, title: string, description: string, code: string}>}>,
     *     pitfalls: array<int, array{course: array{title: string, slug: string, published: bool}, items: array<int, array{title: string, body: string}>}>,
     *     scoring: array{weights: array<int, array{label: string, points: int, note: string}>, total: int, collisionPenalty: int, completion: string, scaling: string, stars: array<int, string>},
     * }
     */
    public function handle(): array
    {
        /** @var array<string, mixed> $manual */
        $manual = (array) config('drone-api.manual', []);

        /*
         * The one query on the page, and it is two columns over the catalog.
         * It buys the examples their attribution: a reader who likes the look
         * of one gets a link to the course it was written for, and a course
         * that is not published gets named without being linked to a 404.
         */
        $published = Course::query()->published()->pluck('title', 'slug');

        return [
            'tagline' => (string) ($manual['tagline'] ?? ''),
            'summary' => (string) ($manual['summary'] ?? ''),
            'concepts' => array_values((array) ($manual['concepts'] ?? [])),
            'commandGroups' => $this->commands->handle($this->commands->everyName()),
            'examples' => $this->examples($published),
            'pitfalls' => $this->pitfalls($published),
            'scoring' => $this->scoring(),
        ];
    }

    /**
     * Every worked example, kept under the course it was written for.
     *
     * Grouped rather than flattened because nineteen programs in a row is a
     * wall, and because the grouping is the attribution — these were written
     * to teach a particular course's ideas, and saying which one is how a
     * reader knows where to go next.
     *
     * The slugs are prefixed with the course. They are rendered as element ids
     * and linked to, and while config/course-docs.php requires them to be
     * unique within a course, nothing stops two courses picking the same one —
     * on this page that would be two headings answering to one anchor.
     *
     * @param  Collection<string, string>  $published  course titles keyed by slug
     * @return array<int, array{course: array{title: string, slug: string, published: bool}, items: array<int, array{slug: string, title: string, description: string, code: string}>}>
     */
    private function examples(Collection $published): array
    {
        $groups = [];

        foreach ((array) config('course-docs', []) as $slug => $doc) {
            $items = array_values((array) ($doc['examples'] ?? []));

            if ($items === []) {
                continue;
            }

            $groups[] = [
                'course' => $this->course((string) $slug, $published),
                'items' => array_map(fn (array $example): array => [
                    'slug' => $slug.'-'.($example['slug'] ?? ''),
                    'title' => (string) ($example['title'] ?? ''),
                    'description' => (string) ($example['description'] ?? ''),
                    'code' => (string) ($example['code'] ?? ''),
                ], $items),
            ];
        }

        return $groups;
    }

    /**
     * Every pitfall, kept under the course that warned about it.
     *
     * Not deduplicated. Two courses warning about the same mistake in their
     * own words are two warnings worth reading, and collapsing them would mean
     * picking one course's phrasing to speak for another's.
     *
     * @param  Collection<string, string>  $published  course titles keyed by slug
     * @return array<int, array{course: array{title: string, slug: string, published: bool}, items: array<int, array{title: string, body: string}>}>
     */
    private function pitfalls(Collection $published): array
    {
        $groups = [];

        foreach ((array) config('course-docs', []) as $slug => $doc) {
            $items = array_values((array) ($doc['pitfalls'] ?? []));

            if ($items === []) {
                continue;
            }

            $groups[] = [
                'course' => $this->course((string) $slug, $published),
                'items' => array_map(fn (array $pitfall): array => [
                    'title' => (string) ($pitfall['title'] ?? ''),
                    'body' => (string) ($pitfall['body'] ?? ''),
                ], $items),
            ];
        }

        return $groups;
    }

    /**
     * How a group of examples names the course it came from.
     *
     * The published title when there is one, and the slug read back as words
     * when there is not — a guide can be authored before its course row is
     * seeded, and "delivery-ops" is a worse heading than "Delivery Ops" for
     * the same information. `published` is what decides whether the heading is
     * a link, so an unpublished course is named without being offered.
     *
     * @param  Collection<string, string>  $published
     * @return array{title: string, slug: string, published: bool}
     */
    private function course(string $slug, Collection $published): array
    {
        return [
            'title' => (string) $published->get($slug, Str::headline($slug)),
            'slug' => $slug,
            'published' => $published->has($slug),
        ];
    }

    /**
     * The scoring policy, read off the grader rather than described.
     *
     * The labels and the notes are prose and belong to this page; every number
     * in them is {@see GradeSimulatorRun}'s own constant. Writing "70 points"
     * here in words would be a second copy of the policy, free to drift from
     * the one a run is actually marked with — and a pilot who reads the rules
     * and then loses to different ones has been lied to.
     *
     * @return array{weights: array<int, array{label: string, points: int, note: string}>, total: int, collisionPenalty: int, completion: string, scaling: string, stars: array<int, string>}
     */
    private function scoring(): array
    {
        return [
            'weights' => [
                [
                    'label' => 'Mission objectives',
                    'points' => GradeSimulatorRun::OBJECTIVE_WEIGHT,
                    'note' => 'Each waypoint, each photo target, the photo quota and the wash pass count as one objective apiece, so a city shift is graded on the same curve as a plain navigation run. Partial credit is the fraction you hit.',
                ],
                [
                    'label' => 'Landing where the briefing asks',
                    'points' => GradeSimulatorRun::LANDING_WEIGHT,
                    'note' => 'Awarded outright on missions that do not ask for a landing.',
                ],
                [
                    'label' => 'Finishing inside the time limit',
                    'points' => GradeSimulatorRun::TIME_WEIGHT,
                    'note' => 'Running the clock out costs these points and fails the run outright, however much of it you flew.',
                ],
            ],
            'total' => GradeSimulatorRun::OBJECTIVE_WEIGHT
                + GradeSimulatorRun::LANDING_WEIGHT
                + GradeSimulatorRun::TIME_WEIGHT,
            'collisionPenalty' => GradeSimulatorRun::COLLISION_PENALTY,
            'completion' => 'A run counts as complete when every objective is met, any minimum altitude in the briefing was reached, the drone is landed if one was asked for, and the clock had not run out.',
            'scaling' => "Those hundred points are then scaled to the mission's own maximum, and that is the figure stored against your account and ranked on the leaderboard.",
            'stars' => [
                'Finish the mission.',
                'Fly it without a single collision.',
                sprintf(
                    'Land inside %d%% of the time limit.',
                    (int) round(GradeSimulatorRun::FAST_FINISH_RATIO * 100),
                ),
            ],
        ];
    }
}
