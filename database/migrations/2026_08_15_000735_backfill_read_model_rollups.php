<?php

declare(strict_types=1);

use App\Actions\RebuildRollups;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Fill the rollups in for everything already flown.
     *
     * The read models switch to these tables in the same release that creates
     * them, so without this every pilot who has ever flown would open their
     * analytics to a blank page and drop off the leaderboard until their next
     * run put one mission's worth of history back. The rollups are derived
     * data, and the derivation is already written down — this is the same
     * rebuild the `rollups:rebuild` command runs, called once at the moment
     * the tables come into existence.
     *
     * Deliberately the whole rebuild rather than a hand-written backfill
     * query. A backfill that computed the totals its own way would be a
     * second definition of what the columns mean, and the first release is
     * the worst possible time to find out the two disagree.
     */
    public function up(): void
    {
        resolve(RebuildRollups::class)->handle();
    }
};
