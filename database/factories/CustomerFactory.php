<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Customer>
 */
final class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'billable_id' => User::factory(),
            'billable_type' => (new User)->getMorphClass(),
            'creem_id' => 'cust_'.$this->faker->unique()->bothify('??##??##??##'),
            'email' => $this->faker->safeEmail(),
        ];
    }

    public function billable(Model $billable): self
    {
        return $this->state([
            'billable_id' => $billable->getKey(),
            'billable_type' => $billable->getMorphClass(),
        ]);
    }
}
