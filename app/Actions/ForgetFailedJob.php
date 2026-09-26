<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Queue\Failed\FailedJobProviderInterface;

/**
 * Discard a failed job without running it again.
 *
 * For a failure that has been looked at and does not need retrying — a mail
 * to an address that no longer exists, a job whose work somebody has since
 * done by hand. The failer's own `forget`, so the record goes the way
 * `queue:forget` would take it.
 */
final readonly class ForgetFailedJob
{
    public function __construct(private FailedJobProviderInterface $failer) {}

    /**
     * False when no failed job has that id.
     */
    public function handle(string $id): bool
    {
        return (bool) $this->failer->forget($id);
    }
}
