<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Order>
 */
final class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'billable_id' => User::factory(),
            'billable_type' => (new User)->getMorphClass(),
            'creem_id' => 'ord_'.$this->faker->unique()->bothify('??##??##??##'),
            'checkout_id' => 'ch_'.$this->faker->bothify('??##??##??##'),
            'customer_id' => 'cust_'.$this->faker->bothify('??##??##??##'),
            'product_id' => 'prod_'.$this->faker->bothify('??##??##??##'),
            'subscription_id' => 'sub_'.$this->faker->bothify('??##??##??##'),
            'currency' => 'USD',
            'amount' => 1900,
            'status' => 'paid',
            'type' => 'recurring',
            'refunded' => false,
            'refunded_amount' => null,
            'refunded_at' => null,
            'ordered_at' => now(),
        ];
    }

    public function billable(Model $billable): self
    {
        return $this->state([
            'billable_id' => $billable->getKey(),
            'billable_type' => $billable->getMorphClass(),
        ]);
    }

    /**
     * Refunded in full unless an amount says otherwise, because a partial
     * refund is the case worth spelling out at a call site.
     */
    public function refunded(?int $amount = null): self
    {
        return $this->state(fn (array $attributes): array => [
            'refunded' => true,
            'refunded_amount' => $amount ?? $attributes['amount'],
            'refunded_at' => now(),
        ]);
    }
}
