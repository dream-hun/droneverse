<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Course;

final readonly class BuildCourseDocumentation
{
    public function __construct(private DescribeDroneCommands $commands) {}

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
