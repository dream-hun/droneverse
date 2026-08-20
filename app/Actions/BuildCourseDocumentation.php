<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Course;

/**
 * The written guide for a course, assembled for the documentation page.
 *
 * Two sources meet here and neither is the database. The prose, the worked
 * examples and the list of commands a course leans on are authored in
 * config/course-docs.php; the signature and semantics of each of those
 * commands are described once, for the whole application, in
 * config/drone-api.php. {@see DescribeDroneCommands} joins them, so a command
 * is documented in one place and every course that teaches it — and the manual
 * at /docs, which names them all — renders the same description.
 *
 * A course with nothing authored has no page rather than an empty one — see
 * {@see self::existsFor()}, which is what the catalog asks before it offers
 * a link and what the controller asks before it renders.
 */
final readonly class BuildCourseDocumentation
{
    public function __construct(private DescribeDroneCommands $commands)
    {
        //
    }

    /**
     * Whether this course has documentation written for it.
     *
     * Asked on the course page to decide whether to show the link at all.
     * A missing entry is not an error: courses are rows and can be created
     * faster than a guide can be written for them, and a link to a page
     * that 404s is worse than no link.
     */
    public function existsFor(Course $course): bool
    {
        return is_array(config('course-docs.'.$course->slug));
    }

    /**
     * @return array{
     *     tagline: string,
     *     summary: string,
     *     objectives: array<int, string>,
     *     commandGroups: array<int, array{key: string, label: string, commands: array<int, array<string, mixed>>}>,
     *     examples: array<int, array{slug: string, title: string, description: string, code: string}>,
     *     pitfalls: array<int, array{title: string, body: string}>,
     * }|null
     */
    public function handle(Course $course): ?array
    {
        $doc = config('course-docs.'.$course->slug);

        if (! is_array($doc)) {
            return null;
        }

        return [
            'tagline' => (string) ($doc['tagline'] ?? ''),
            'summary' => (string) ($doc['summary'] ?? ''),
            'objectives' => array_values((array) ($doc['objectives'] ?? [])),
            'commandGroups' => $this->commands->handle(array_values((array) ($doc['commands'] ?? []))),
            'examples' => array_values((array) ($doc['examples'] ?? [])),
            'pitfalls' => array_values((array) ($doc['pitfalls'] ?? [])),
        ];
    }
}
