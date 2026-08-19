<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RebuildRollups;
use App\Models\PilotCourseTotals;
use App\Models\PilotMissionStats;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Recompute the analytics and leaderboard rollups from scratch.
 *
 * The escape hatch for the one weakness of a derived table: it can be wrong.
 * {@see \App\Actions\RollUpChallengeRun} keeps its totals incrementally, so a
 * bug in it, a restore from a partial backup, or anyone editing the run table
 * by hand leaves rows that no future write will correct. This puts them back
 * without needing to know what went wrong.
 *
 * It clears both tables before rebuilding them, so it is a maintenance
 * operation rather than something to schedule against live traffic.
 */
#[Description('Recompute the analytics and leaderboard rollups from runs and progress')]
#[Signature('rollups:rebuild')]
final class RebuildRollupsCommand extends Command
{
    /**
     * @throws Throwable
     */
    public function handle(RebuildRollups $rollups): int
    {
        $this->components->info('Rebuilding read-model rollups.');

        $this->components->task(
            'pilot_mission_stats',
            fn () => $rollups->missionStats(),
        );

        $this->components->task(
            'pilot_course_totals',
            fn () => $rollups->courseTotals(),
        );

        $this->components->twoColumnDetail(
            'pilot_mission_stats',
            sprintf('%d rows', PilotMissionStats::query()->count()),
        );

        $this->components->twoColumnDetail(
            'pilot_course_totals',
            sprintf('%d rows', PilotCourseTotals::query()->count()),
        );

        return self::SUCCESS;
    }
}
