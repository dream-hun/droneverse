<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Queue\Failed\FailedJobProviderInterface;

/**
 * Put a failed job back on the queue it failed on.
 *
 * Through `queue:retry` rather than a re-implementation of it. The command is
 * what resets the payload's attempt count, refreshes any encrypted command it
 * carries and removes the failure record once the job is pushed — details a
 * copy here would have to track release by release.
 */
final readonly class RetryFailedJob
{
    public function __construct(
        private FailedJobProviderInterface $failer,
        private Kernel $artisan,
    ) {}

    /**
     * False when no failed job has that id — already retried, or forgotten.
     */
    public function handle(string $id): bool
    {
        if ($this->failer->find($id) === null) {
            return false;
        }

        $this->artisan->call('queue:retry', ['id' => [$id]]);

        return true;
    }
}
