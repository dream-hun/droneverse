<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ReconcileCreemBilling;
use App\Http\Integrations\Creem;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Catch up on any Creem webhook that never arrived.
 *
 * Scheduled hourly in routes/console.php, and safe to run by hand at any time:
 * everything it writes is keyed on Creem's own IDs, so a second run over the
 * same data changes nothing. See App\Actions\ReconcileCreemBilling.
 */
#[Description('Sync subscriptions and renewal payments from Creem, recovering missed webhooks')]
#[Signature('creem:reconcile')]
final class ReconcileCreemBillingCommand extends Command
{
    public function handle(Creem $creem, ReconcileCreemBilling $reconcile): int
    {
        if (! $creem->configured()) {
            $this->components->warn('Creem is not configured: set CREEM_API_KEY. Nothing to reconcile.');

            return self::SUCCESS;
        }

        $result = $reconcile->handle();

        $this->components->twoColumnDetail('Subscriptions synced', (string) $result['subscriptions']);
        $this->components->twoColumnDetail('Missing payments recorded', (string) $result['orders']);
        $this->components->twoColumnDetail('Failures (see log)', (string) $result['failures']);

        return $result['failures'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
