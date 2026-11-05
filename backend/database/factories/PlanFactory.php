<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Standard',
            'max_rooms' => 60,
            'price' => '5000.00',
            'currency' => 'ETB',
            'billing_cycle' => 'monthly',
            'modules' => ['housekeeping', 'cashier_close'],
            'is_active' => true,
        ];
    }
}
