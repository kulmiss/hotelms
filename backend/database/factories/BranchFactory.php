<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Hotel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'hotel_id' => Hotel::factory(),
            'name' => fake()->randomElement(['Bole', 'Piassa', 'Kazanchis', 'CMC', 'Megenagna']).' branch',
            'city' => 'Addis Ababa',
            'status' => 'active',
        ];
    }
}
