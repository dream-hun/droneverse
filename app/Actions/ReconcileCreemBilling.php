<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\SubscriptionStatus;
use App\Http\Integrations\Creem;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pull the truth from Creem for every subscription and customer we know of.
 *
 * The webhook is the fast path and this is the one that cannot be missed. Creem
 * gives up on a delivery after five attempts inside a day, so a webhook that
 * met a deploy, a lapsed TLS certificate, a rotated secret or a bad hour is
 * gone for good — and when that webhook was a renewal, the subscription row is
 * left saying the period ended, the pilot loses a plan they paid for, and the
 * payment never reaches the finance pages.
 *
 * Both halves reuse what the webhook runs, so the result is exactly what the
 * missed delivery would have written:
 *
 *   - every subscription not yet finished is fetched and handed to
 *     App\Actions\SyncCreemSubscription, which puts its status and dates right;
 *   - every customer's paid transactions are handed to
 *     App\Actions\RecordCreemTransaction, which adds the renewals as orders.
 *
 * One failure never stops the run. A subscription Creem no longer knows, or a
 * timeout on one customer, is logged and counted and the rest carry on.
 */
final readonly class ReconcileCreemBilling
{
    /**
     * A customer with more history than this is caught up across several runs
     * rather than in one, since transactions come back newest first.
     */
    private const int MAX_TRANSACTION_PAGES = 10;

    private const int PAGE_SIZE = 50;

    public function __construct(
        private Creem $creem,
        private SyncCreemSubscription $subscriptions,
        private RecordCreemTransaction $transactions,
    ) {
        //
    }

    /**
     * @return array{subscriptions: int, orders: int, failures: int}
     */
    public function handle(): array
    {
        $result = ['subscriptions' => 0, 'orders' => 0, 'failures' => 0];

        $this->reconcileSubscriptions($result);
        $this->reconcileTransactions($result);

        return $result;
    }

    /**
     * @param  array{subscriptions: int, orders: int, failures: int}  $result
     */
    private function reconcileSubscriptions(array &$result): void
    {
        /*
         * Expired and canceled are where a Creem subscription ends; nothing
         * moves one on again, so there is nothing to catch up on.
         */
        $finished = [SubscriptionStatus::Expired->value, SubscriptionStatus::Canceled->value];

        Subscription::query()
            ->whereNotIn('status', $finished)
            ->with('billable')
            ->lazyById()
            ->each(function (Subscription $subscription) use (&$result): void {
                $user = $subscription->billable;

                if (! $user instanceof User) {
                    return;
                }

                try {
                    $remote = $this->creem->retrieveSubscription($subscription->creem_id);

                    if ($this->subscriptions->handle($user, $remote) instanceof Subscription) {
                        $result['subscriptions']++;
                    }

                    $transaction = $remote['last_transaction'] ?? null;

                    if (is_array($transaction)) {
                        /** @var array<string, mixed> $transaction */
                        $order = $this->transactions->handle($user, $transaction);

                        if ($order?->wasRecentlyCreated === true) {
                            $result['orders']++;
                        }
                    }
                } catch (Throwable $exception) {
                    $result['failures']++;

                    Log::warning('Could not reconcile a Creem subscription.', [
                        'subscription' => $subscription->creem_id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });
    }

    /**
     * @param  array{subscriptions: int, orders: int, failures: int}  $result
     */
    private function reconcileTransactions(array &$result): void
    {
        Customer::query()
            ->with('billable')
            ->lazyById()
            ->each(function (Customer $customer) use (&$result): void {
                $user = $customer->billable;

                if (! $user instanceof User || ! is_string($customer->creem_id) || $customer->creem_id === '') {
                    return;
                }

                try {
                    $result['orders'] += $this->reconcileCustomer($user, $customer->creem_id);
                } catch (Throwable $exception) {
                    $result['failures']++;

                    Log::warning('Could not reconcile a Creem customer\'s transactions.', [
                        'customer' => $customer->creem_id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });
    }

    /**
     * Returns how many orders were written that did not exist before.
     */
    private function reconcileCustomer(User $user, string $customerId): int
    {
        $created = 0;

        for ($page = 1; $page <= self::MAX_TRANSACTION_PAGES; $page++) {
            $response = $this->creem->searchTransactions($customerId, $page, self::PAGE_SIZE);
            $items = $response['items'] ?? null;

            if (! is_array($items) || $items === []) {
                break;
            }

            foreach ($items as $transaction) {
                if (! is_array($transaction)) {
                    continue;
                }

                /** @var array<string, mixed> $transaction */
                $order = $this->transactions->handle($user, $transaction);

                if ($order?->wasRecentlyCreated === true) {
                    $created++;
                }
            }

            $next = data_get($response, 'pagination.next_page');

            if (! is_int($next) || $next <= $page) {
                break;
            }
        }

        return $created;
    }
}
