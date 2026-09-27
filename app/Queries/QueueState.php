<?php

declare(strict_types=1);

namespace App\Queries;

use App\Concerns\DescribesSystem;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/**
 * What the queue is holding: the jobs waiting to run and the ones that failed.
 *
 * Read from the tables the database queue and its failure log keep, which are
 * the only places either can be counted from here. Any other driver keeps them
 * somewhere this cannot see, and the figure comes back null rather than zero.
 * A figure that throws is null too, and the exception is reported so the cause
 * outlives the page it went missing from.
 */
final readonly class QueueState
{
    use DescribesSystem;

    /** Failed jobs listed on the page; the rest are counted. */
    private const int FAILED_JOB_LIMIT = 20;

    public function __construct(private FailedJobProviderInterface $failer) {}

    /**
     * @return array{connection: string, pending: int|null, oldestPendingSeconds: int|null, failed: int|null}
     */
    public function backlog(): array
    {
        $connection = $this->configString('queue.default');
        $pending = null;
        $oldest = null;

        if (config('queue.connections.'.$connection.'.driver') === 'database') {
            try {
                $jobs = $this->jobsTable($connection);
                $pending = (clone $jobs)->count();
                $available = (clone $jobs)->min('available_at');
                $oldest = is_numeric($available) ? max(0, CarbonImmutable::now()->getTimestamp() - (int) $available) : null;
            } catch (Throwable $throwable) {
                report($throwable);
                $pending = null;
            }
        }

        try {
            $failed = $this->failer instanceof CountableFailedJobProvider ? $this->failer->count() : null;
        } catch (Throwable $throwable) {
            report($throwable);
            $failed = null;
        }

        return [
            'connection' => $connection,
            'pending' => $pending,
            'oldestPendingSeconds' => $oldest,
            'failed' => $failed,
        ];
    }

    /**
     * The most recent failures, newest first.
     *
     * @return array<int, array{id: string, connection: string, queue: string, job: string, exception: string, failedAt: string|null}>
     */
    public function failedJobs(): array
    {
        try {
            $rows = $this->failedTable()
                ?->orderByDesc('failed_at')
                ->orderByDesc('id')
                ->limit(self::FAILED_JOB_LIMIT)
                ->get(['uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at']);
        } catch (Throwable $throwable) {
            report($throwable);

            return [];
        }

        if ($rows === null) {
            return [];
        }

        return $rows->map(function (stdClass $record): array {
            $row = fluent($record);
            $payload = json_decode((string) $row->string('payload'), true);
            $failedAt = $row->get('failed_at');

            return [
                'id' => (string) $row->string('uuid'),
                'connection' => (string) $row->string('connection'),
                'queue' => (string) $row->string('queue'),
                'job' => is_array($payload) && is_string($payload['displayName'] ?? null) ? $payload['displayName'] : 'Unknown job',
                'exception' => $this->firstLine((string) $row->string('exception')),
                'failedAt' => is_string($failedAt) ? CarbonImmutable::parse($failedAt)->toIso8601String() : null,
            ];
        })->values()->all();
    }

    /**
     * How many jobs have failed since a moment, or null when failures are not
     * kept in the database.
     *
     * Unlike the figures above this lets an exception through: the health
     * check that asks turns it into a failed check, which says more than an
     * unknown count would.
     */
    public function failuresSince(CarbonImmutable $since): ?int
    {
        return $this->failedTable()?->where('failed_at', '>=', $since)->count();
    }

    /**
     * The failed jobs table, when failures are kept in one.
     */
    private function failedTable(): ?Builder
    {
        $driver = $this->configString('queue.failed.driver');

        if (! in_array($driver, ['database', 'database-uuids'], true)) {
            return null;
        }

        return $this->connection($this->configString('queue.failed.database'))
            ->table($this->configString('queue.failed.table', 'failed_jobs'));
    }

    private function jobsTable(string $queueConnection): Builder
    {
        $database = config('queue.connections.'.$queueConnection.'.connection');
        $table = config('queue.connections.'.$queueConnection.'.table');

        return $this->connection(is_string($database) ? $database : '')
            ->table(is_string($table) ? $table : 'jobs');
    }

    private function connection(string $name): ConnectionInterface
    {
        return $name === '' ? DB::connection() : DB::connection($name);
    }
}
