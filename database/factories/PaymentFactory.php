<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
final class PaymentFactory extends Factory
{
    /**
     * A first Pro payment, taken and kept.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kelviq_order_id' => 'ORD-'.fake()->unique()->numerify('##############').'-'.fake()->bothify('?????'),
            'user_id' => User::factory(),
            'kelviq_customer_id' => fake()->uuid(),
            'kelviq_subscription_id' => fake()->uuid(),
            'status' => 'COMPLETE',
            'billing_type' => 'SUBSCRIPTION',
            'is_renewal' => false,
            'plan_identifier' => 'pro',
            'amount_units' => 1200,
            'currency' => 'USD',
            'paid_at' => now(),
            'kelviq_updated_at' => now(),
        ];
    }
}
