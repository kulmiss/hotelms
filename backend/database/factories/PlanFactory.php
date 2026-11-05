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
            'price_per_room' => '100.00',
            'minimum_monthly' => '2000.00',
            'yearly_free_months' => 2,
            'currency' => 'ETB',
            'modules' => ['housekeeping', 'cashier_close'],
            'is_active' => true,
        ];
    }
}
