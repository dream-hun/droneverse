<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\ChallengeRun;
use App\Models\QuizAttempt;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Everything that happened on the platform, newest first, as one stream.
 *
 * There is no events table behind this, and deliberately so. Each thing worth
 * seeing already leaves a row somewhere — an account, a graded run, a quiz
 * attempt — and a second copy written alongside it
 * would be a second record of the same fact, free to disagree with the first.
 * The feed is read from the records themselves.
 *
 * Paging a merge of several tables is done without a union. For page n of size
 * k, the newest n·k + 1 rows of each source are enough to contain the newest
 * n·k + 1 of all of them together, so each source is asked for that many —
 * by primary key, or by an indexed date — and the merge, sort and slice
 * happen here. The cost of a page is bounded by how deep it is, never by how
 * big the tables have grown, and nothing depends on how a particular database
 * spells a union with an ORDER BY in each branch. The page number is capped
 * for the same reason: this is for seeing what is happening, and a history
 * lives on the pages that own it.
 *
 * @phpstan-type Pilot array{uuid: string, name: string, email: string}
 * @phpstan-type Item array{key: string, kind: string, occurredAt: string, pilot: Pilot|null, title: string, meta: string|null}
 */
final readonly class ActivityFeed
{
    public const int PER_PAGE = 25;

    public const int MAX_PAGE = 20;

    /**
     * Every source the feed can read.
     *
     * Payments are not among them: Kelviq is the only record of those, and its
     * dashboard is where they are read.
     */
    public const array KINDS = ['signup', 'run', 'quiz'];

    /**
     * @param  array<int, string>  $kinds  Which of KINDS to read.
     * @return array{items: array<int, Item>, hasMore: bool, page: int}
     */
    public function page(array $kinds, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $page = max(1, min($page, self::MAX_PAGE));
        $window = $page * $perPage + 1;

        $items = collect($kinds)
            ->unique()
            ->flatMap(fn (string $kind): Collection => $this->source($kind, $window))
            ->sort(fn (array $a, array $b): int => [$b['at'], $b['item']['key']] <=> [$a['at'], $a['item']['key']])
            ->values()
            ->slice(($page - 1) * $perPage, $perPage + 1)
            ->map(fn (array $entry): array => $entry['item'])
            ->values();

        return [
            'items' => $items->take($perPage)->all(),
            'hasMore' => $items->count() > $perPage && $page < self::MAX_PAGE,
            'page' => $page,
        ];
    }

    /**
     * @return Collection<int, array{at: int, item: Item}>
     */
    private function source(string $kind, int $limit): Collection
    {
        return match ($kind) {
            'signup' => $this->signups($limit),
            'run' => $this->runs($limit),
            'quiz' => $this->quizzes($limit),
            default => throw new InvalidArgumentException(sprintf('No activity source is called [%s].', $kind)),
        };
    }

    /**
     * @return Collection<int, array{at: int, item: Item}>
     */
    private function signups(int $limit): Collection
    {
        return User::query()
            ->select(['id', 'uuid', 'name', 'email', 'created_at'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (User $user): array => $this->entry(
                'signup',
                $user->id,
                $user->created_at,
                $user,
                'Opened an account',
                null,
            ));
    }

    /**
     * @return Collection<int, array{at: int, item: Item}>
     */
    private function runs(int $limit): Collection
    {
        return ChallengeRun::query()
            ->select(['id', 'user_id', 'challenge_id', 'score', 'stars', 'completed', 'collisions', 'created_at'])
            ->with([
                'user:id,uuid,name,email',
                'challenge:id,course_id,title',
                'challenge.course:id,title',
            ])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (ChallengeRun $run): array => $this->entry(
                'run',
                $run->id,
                $run->created_at,
                $run->user,
                sprintf('Flew %s', $run->challenge->title ?? 'a retired mission'),
                sprintf(
                    '%s · %d pts · %d★ · %s',
                    $run->challenge->course->title ?? '',
                    $run->score,
                    $run->stars,
                    $run->completed ? 'cleared' : 'not cleared',
                ),
            ));
    }

    /**
     * @return Collection<int, array{at: int, item: Item}>
     */
    private function quizzes(int $limit): Collection
    {
        return QuizAttempt::query()
            ->select(['id', 'user_id', 'quiz_id', 'score', 'passed', 'created_at'])
            ->with(['user:id,uuid,name,email', 'quiz:id,course_id,title', 'quiz.course:id,title'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (QuizAttempt $attempt): array => $this->entry(
                'quiz',
                $attempt->id,
                $attempt->created_at,
                $attempt->user,
                sprintf('Took %s', $attempt->quiz->title ?? 'a retired quiz'),
                sprintf(
                    '%s · %d%% · %s',
                    $attempt->quiz->course->title ?? '',
                    $attempt->score,
                    $attempt->passed ? 'passed' : 'not passed',
                ),
            ));
    }

    /**
     * @return Pilot
     */
    private function pilotFor(User $user): array
    {
        return ['uuid' => $user->uuid, 'name' => $user->name, 'email' => $user->email];
    }

    /**
     * One row of the feed, with the timestamp it sorts by kept beside it.
     *
     * @return array{at: int, item: Item}
     */
    private function entry(string $kind, int $id, ?CarbonInterface $at, ?User $pilot, string $title, ?string $meta): array
    {
        return [
            'at' => $at?->getTimestamp() ?? 0,
            'item' => [
                'key' => sprintf('%s:%d', $kind, $id),
                'kind' => $kind,
                'occurredAt' => $at?->toIso8601String() ?? '',
                'pilot' => $pilot instanceof User ? $this->pilotFor($pilot) : null,
                'title' => $title,
                'meta' => $meta,
            ],
        ];
    }
}
