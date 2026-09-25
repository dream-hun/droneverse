<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Subscription>
 */
final class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * An active monthly subscription, a day into the period it has paid for.
     *
     * Deliberately mid-period rather than starting today: most of what these
     * rows are asked is "is this still running?", and a subscription whose
     * period ends the moment it is created answers that differently depending
     * on how long the test takes.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'billable_id' => User::factory(),
            'billable_type' => (new User)->getMorphClass(),
            'type' => Subscription::DEFAULT_TYPE,
            'creem_id' => 'sub_'.fake()->unique()->bothify('??##??##??##'),
            'customer_id' => 'cust_'.fake()->bothify('??##??##??##'),
            'product_id' => 'prod_'.fake()->bothify('??##??##??##'),
            'status' => SubscriptionStatus::Active->value,
            'units' => 1,
            'trial_ends_at' => null,
            'renews_at' => now()->addMonth(),
            'current_period_start_at' => now()->subDay(),
            'current_period_end_at' => now()->addMonth(),
            'canceled_at' => null,
        ];
    }

    /**
     * Attach the subscription to an account that already exists.
     *
     * Named around the morph columns rather than using the framework's `for()`,
     * which would want a relationship name this model does not expose in that
     * direction.
     */
    public function billable(Model $billable): self
    {
        return $this->state([
            'billable_id' => $billable->getKey(),
            'billable_type' => $billable->getMorphClass(),
        ]);
    }

    /**
     * Sell a particular Creem product, which is what decides the plan.
     */
    public function selling(string $productId): self
    {
        return $this->state(['product_id' => $productId]);
    }

    public function status(SubscriptionStatus $status): self
    {
        return $this->state(['status' => $status->value]);
    }

    public function trialing(?DateTimeInterface $endsAt = null): self
    {
        $endsAt ??= now()->addWeek();

        return $this->state([
            'status' => SubscriptionStatus::Trialing->value,
            'trial_ends_at' => $endsAt,
            'current_period_end_at' => $endsAt,
        ]);
    }

    /**
     * Cancellation asked for, period still running — which is the only state a
     * subscription can be resumed from.
     */
    public function scheduledCancel(?DateTimeInterface $endsAt = null): self
    {
        return $this->state([
            'status' => SubscriptionStatus::ScheduledCancel->value,
            'current_period_end_at' => $endsAt ?? now()->addWeek(),
            'renews_at' => null,
        ]);
    }

    public function canceled(?DateTimeInterface $at = null): self
    {
        $at ??= now()->subDay();

        return $this->state([
            'status' => SubscriptionStatus::Canceled->value,
            'canceled_at' => $at,
            'current_period_end_at' => $at,
            'renews_at' => null,
        ]);
    }

    public function expired(): self
    {
        return $this->state([
            'status' => SubscriptionStatus::Expired->value,
            'current_period_end_at' => now()->subDay(),
            'renews_at' => null,
        ]);
    }
}
