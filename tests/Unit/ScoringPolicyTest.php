<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Actions\GradeSimulatorRun;
use App\Models\Challenge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The server's half of the shared scoring contract.
 *
 * Every case comes from tests/Fixtures/scoring-vectors.json. That file, not
 * this one, is where the policy is written down — a case added there is a
 * case the grader has to satisfy.
 *
 * Two PHPUnit tests over the same data existed before, one per language, and
 * they were separately maintained descriptions of the same rules. They agreed
 * because whoever wrote them made them agree, which is not a property the
 * next change inherits.
 *
 * A plain PHPUnit test rather than a Laravel one: nothing here touches the
 * database. A Challenge is built in memory and asked for the two attributes
 * the grader reads, which is the whole of the grader's dependency on it.
 */
final class ScoringPolicyTest extends TestCase
{
    /**
     * @return array<string, array{0: array{name: string, why: string, maxScore: int, criteria: array<string, mixed>, measured: array<string, mixed>, expected: array<string, mixed>}}>
     */
    public static function vectors(): array
    {
        $path = dirname(__DIR__).'/Fixtures/scoring-vectors.json';
        $contents = file_get_contents($path);

        if ($contents === false) {
            self::fail("the shared scoring vectors could not be read from {$path}");
        }

        /** @var array{vectors: array<int, array{name: string, why: string, maxScore: int, criteria: array<string, mixed>, measured: array<string, mixed>, expected: array<string, mixed>}>} $file */
        $file = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        $cases = [];

        foreach ($file['vectors'] as $vector) {
            $cases[$vector['name']] = [$vector];
        }

        return $cases;
    }

    /**
     * @param  array{name: string, why: string, maxScore: int, criteria: array<string, mixed>, measured: array<string, mixed>, expected: array<string, mixed>}  $vector
     */
    #[DataProvider('vectors')]
    public function test_the_server_grades_a_run_the_way_the_contract_says(array $vector): void
    {
        $challenge = new Challenge;
        $challenge->success_criteria = $vector['criteria'];
        $challenge->max_score = $vector['maxScore'];

        /** @var array{waypointsHit: int, waypointsTotal: int, collisions: int, maxAltitude: float, landed: bool, elapsedSeconds: float, timedOut: bool, photosTaken: int, photoTargetsHit: int, photoTargetsTotal: int, photosMissing: int, washRequired: bool, washed: bool} $measured */
        $measured = $vector['measured'];

        $this->assertSame(
            $vector['expected'],
            (new GradeSimulatorRun)->handle($measured, $challenge),
            $vector['why'],
        );
    }

    /**
     * The contract is only worth anything while both sides are reading it.
     *
     * A vector file that has quietly become empty, or a suite pointed at a
     * path that no longer exists, would pass every test above by running none
     * of them.
     */
    public function test_the_contract_covers_the_cases_it_claims_to(): void
    {
        $vectors = self::vectors();

        $this->assertGreaterThanOrEqual(
            15,
            count($vectors),
            'the shared scoring vectors have shrunk; a policy case has been dropped rather than changed',
        );
    }
}
