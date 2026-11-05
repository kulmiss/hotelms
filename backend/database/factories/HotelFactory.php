<?php

namespace Database\Factories;

use App\Models\Hotel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Hotel>
 */
class HotelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Hotel',
            'email' => fake()->unique()->companyEmail(),
            'phone' => '+2519'.fake()->numerify('########'),
            'city' => 'Addis Ababa',
            'default_currency' => 'ETB',
            'timezone' => 'Africa/Addis_Ababa',
            'status' => 'active',
            'subscription_ends_at' => now()->addMonth(),
        ];
    }
}
