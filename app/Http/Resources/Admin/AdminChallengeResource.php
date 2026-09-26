<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Challenge;
use Illuminate\Support\Collection;

/**
 * A mission, complete, for the admin editor.
 *
 * The one place the reference solution leaves the server regardless of
 * progress. It is hidden on the model so no pilot-facing path can serialize it
 * by accident, and it is read out explicitly here because this is the screen
 * it is authored on — behind `manage_courses`, which is what makes that safe.
 *
 * The two JSON columns travel as pretty-printed text rather than as objects.
 * They are edited as text, and a round trip through a JavaScript object would
 * reorder nothing today but promises nothing about tomorrow; the author gets
 * back exactly what was saved.
 *
 * @phpstan-type Row array{slug: string, title: string, briefing: string, order: int, difficulty: string, requiredPlan: string|null, starterCode: string, solutionCode: string|null, environment: string, successCriteria: string, maxScore: int, isPublished: bool, pilots: int}
 */
final class AdminChallengeResource
{
    /**
     * @param  Collection<int, Challenge>  $challenges  with `progress` counted
     * @return array<int, Row>
     */
    public static function collection(Collection $challenges): array
    {
        return $challenges->map(fn (Challenge $challenge): array => self::one($challenge))->values()->all();
    }

    /**
     * @return Row
     */
    public static function one(Challenge $challenge): array
    {
        return [
            'slug' => $challenge->slug,
            'title' => $challenge->title,
            'briefing' => $challenge->briefing,
            'order' => $challenge->order,
            'difficulty' => $challenge->difficulty,
            'requiredPlan' => $challenge->required_plan,
            'starterCode' => $challenge->starter_code,
            'solutionCode' => $challenge->solution_code,
            'environment' => self::json($challenge->environment),
            'successCriteria' => self::json($challenge->success_criteria),
            'maxScore' => $challenge->max_score,
            'isPublished' => $challenge->is_published,
            'pilots' => $challenge->progress_count ?? 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
