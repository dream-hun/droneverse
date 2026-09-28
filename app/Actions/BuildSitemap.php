<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Course;
use Illuminate\Support\Facades\Date;

/**
 * Every page on the site that is meant to be found, for sitemap.xml.
 *
 * The pages that give BuildPageHead a canonical path, and no others: a sitemap
 * listing a page whose own `<head>` says noindex gives a crawler two answers to
 * one question. Sign-in and sign-up are indexable but left out. Every page
 * links to them, so listing them tells a crawler nothing it lacks, and this is
 * the list of pages worth its time.
 *
 * `lastmod` is given only where it is known. A course page changes when the
 * course, one of its missions or one of its quizzes does, and a legal page on
 * the date config/legal.php records. The rest are assembled from config and
 * code, so there is no date to give, and inventing one would teach Google to
 * ignore the dates that are real.
 */
final readonly class BuildSitemap
{
    public function __construct(
        private BuildCanonicalUrl $canonicalUrl,
        private BuildCourseDocumentation $documentation,
    ) {}

    /**
     * @return list<array{loc: string, lastmod: string|null}>
     */
    public function handle(): array
    {
        $courses = Course::query()
            ->select(['id', 'slug', 'updated_at'])
            ->published()
            ->withMax('challenges', 'updated_at')
            ->withMax('quizzes', 'updated_at')
            ->orderBy('order')
            ->get();

        $entries = [
            $this->entry(route('home', absolute: false)),
            $this->entry(route('courses.index', absolute: false)),
        ];

        foreach ($courses as $course) {
            $entries[] = $this->entry(route('courses.show', $course, absolute: false), $this->lastChanged($course));

            if ($this->documentation->existsFor($course)) {
                $entries[] = $this->entry(route('courses.docs', $course, absolute: false));
            }
        }

        return [
            ...$entries,
            $this->entry(route('docs', absolute: false)),
            $this->entry(route('pricing', absolute: false)),
            $this->entry(route('terms', absolute: false), config()->string('legal.effective.terms')),
            $this->entry(route('privacy', absolute: false), config()->string('legal.effective.privacy')),
        ];
    }

    /**
     * @return array{loc: string, lastmod: string|null}
     */
    private function entry(string $path, ?string $lastmod = null): array
    {
        return ['loc' => $this->canonicalUrl->handle($path), 'lastmod' => $lastmod];
    }

    /**
     * When anything the course page shows last changed: the course itself, or
     * any mission or quiz in it, published or not, since unpublishing one
     * changes the page as surely as editing it.
     */
    private function lastChanged(Course $course): string
    {
        $latest = max(
            $course->updated_at?->getTimestamp() ?? 0,
            $this->timestamp($course->challenges_max_updated_at),
            $this->timestamp($course->quizzes_max_updated_at),
        );

        return Date::createFromTimestamp($latest)->toAtomString();
    }

    private function timestamp(?string $aggregate): int
    {
        return $aggregate === null ? 0 : Date::parse($aggregate)->getTimestamp();
    }
}
