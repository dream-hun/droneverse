<?php

declare(strict_types=1);

use App\Actions\GradeSimulatorRun;
use App\Models\Challenge;

/**
 * The server's half of the shared scoring contract.
 *
 * Every case comes from tests/Fixtures/scoring-vectors.json. That file, not
 * this one, is where the policy is written down — a case added there is a
 * case the grader has to satisfy.
 *
 * Two tests over the same data existed before, one per language, and they
 * were separately maintained descriptions of the same rules. They agreed
 * because whoever wrote them made them agree, which is not a property the
 * next change inherits.
 *
 * The one file in the suite that does not `uses(TestCase::class)`: nothing
 * here touches the database. A Challenge is built in memory and asked for the
 * two attributes the grader reads, which is the whole of the grader's
 * dependency on it.
 */

/**
 * The contract, keyed by case name.
 *
 * Read through a function rather than straight into the dataset because the
 * last test in this file counts the cases, and a dataset cannot be asked how
 * many it holds.
 *
 * @return array<string, array{0: array{name: string, why: string, maxScore: int, criteria: array<string, mixed>, measured: array<string, mixed>, expected: array<string, mixed>}}>
 */
function scoringVectors(): array
{
    $path = dirname(__DIR__).'/Fixtures/scoring-vectors.json';
    $contents = file_get_contents($path);

    throw_if($contents === false, RuntimeException::class, "the shared scoring vectors could not be read from {$path}");

    /** @var array{vectors: array<int, array{name: string, why: string, maxScore: int, criteria: array<string, mixed>, measured: array<string, mixed>, expected: array<string, mixed>}>} $file */
    $file = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

    $cases = [];

    foreach ($file['vectors'] as $vector) {
        $cases[$vector['name']] = [$vector];
    }

    return $cases;
}

dataset('scoring vectors', fn (): array => scoringVectors());

/**
 * @param  array{name: string, why: string, maxScore: int, criteria: array<string, mixed>, measured: array<string, mixed>, expected: array<string, mixed>}  $vector
 */
test('the server grades a run the way the contract says', function (array $vector): void {
    $challenge = new Challenge;
    $challenge->success_criteria = $vector['criteria'];
    $challenge->max_score = $vector['maxScore'];

    /** @var array{waypointsHit: int, waypointsTotal: int, collisions: int, maxAltitude: float, landed: bool, elapsedSeconds: float, timedOut: bool, photosTaken: int, photoTargetsHit: int, photoTargetsTotal: int, photosMissing: int, washRequired: bool, washed: bool} $measured */
    $measured = $vector['measured'];

    expect((new GradeSimulatorRun)->handle($measured, $challenge))->toBe($vector['expected'], $vector['why']);
})->with('scoring vectors');

/**
 * The contract is only worth anything while both sides are reading it.
 *
 * A vector file that has quietly become empty, or a suite pointed at a
 * path that no longer exists, would pass every test above by running none
 * of them.
 */
test('the contract covers the cases it claims to', function (): void {
    $vectors = scoringVectors();

    expect(count($vectors))
        ->toBeGreaterThanOrEqual(
            15,
            'the shared scoring vectors have shrunk; a policy case has been dropped rather than changed',
        );
});
