<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RecordKelviqOrder;
use App\Http\Integrations\Kelviq;
use App\Models\Payment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

/**
 * Copy every order Kelviq holds into `payments`.
 *
 * The `order.*` webhooks keep the table current from the moment the endpoint
 * is subscribed to them. This is for everything before that — payments taken
 * while the endpoint listened only to checkout and subscription events — and
 * for anything a failed delivery missed after Kelviq gave up retrying it.
 *
 * Safe to run at any time and as often as wanted: rows are keyed on Kelviq's
 * order id, and what Kelviq lists now is the newest description of each order
 * there is.
 */
#[Description('Record every Kelviq order in the payments table')]
#[Signature('kelviq:sync-payments')]
final class SyncKelviqPaymentsCommand extends Command
{
    /**
     * Far more pages than this organisation will ever hold, so a `next` link
     * that never ends cannot keep the command running forever.
     */
    private const int MAX_PAGES = 1_000;

    /**
     * @throws ConnectionException|RequestException|RuntimeException
     */
    public function handle(Kelviq $kelviq, RecordKelviqOrder $orders): int
    {
        if (! $kelviq->configured()) {
            $this->components->error('Kelviq is not configured: set KELVIQ_SERVER_API_KEY.');

            return self::FAILURE;
        }

        $recorded = 0;
        $skipped = 0;
        $page = 0;

        do {
            $page++;
            $readAt = now();
            ['orders' => $batch, 'hasMore' => $hasMore] = $kelviq->listOrders($page);

            foreach ($batch as $order) {
                $orders->fromApi($order, $readAt) instanceof Payment ? $recorded++ : $skipped++;
            }
        } while ($hasMore && $page < self::MAX_PAGES);

        $this->components->info(sprintf('Recorded %d Kelviq %s.', $recorded, str('order')->plural($recorded)));

        if ($skipped > 0) {
            $this->components->warn(sprintf('Skipped %d with no id, customer or status.', $skipped));
        }

        return self::SUCCESS;
    }
}
