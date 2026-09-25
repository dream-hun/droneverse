<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Course;
use Illuminate\Support\Arr;

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
            'tagline' => Arr::string($doc, 'tagline', ''),
            'summary' => Arr::string($doc, 'summary', ''),
            'objectives' => array_values(array_filter(Arr::array($doc, 'objectives', []), is_string(...))),
            'commandGroups' => $this->commands->handle(array_values(array_filter(Arr::array($doc, 'commands', []), is_string(...)))),
            'examples' => array_map(fn (array $example): array => [
                'slug' => Arr::string($example, 'slug', ''),
                'title' => Arr::string($example, 'title', ''),
                'description' => Arr::string($example, 'description', ''),
                'code' => Arr::string($example, 'code', ''),
            ], array_values(array_filter(Arr::array($doc, 'examples', []), is_array(...)))),
            'pitfalls' => array_map(fn (array $pitfall): array => [
                'title' => Arr::string($pitfall, 'title', ''),
                'body' => Arr::string($pitfall, 'body', ''),
            ], array_values(array_filter(Arr::array($doc, 'pitfalls', []), is_array(...)))),
        ];
    }
}
