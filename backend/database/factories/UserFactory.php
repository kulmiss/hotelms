<?php

namespace Database\Factories;

use App\Models\Hotel;
use App\Models\User;
use App\Services\DefaultRoles;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(fn () => ['hotel_id' => null])
            ->afterMaking(function (User $user) {
                $user->is_super_admin = true;
                $user->platform_role ??= 'owner';
            });
    }

    public function platformRole(string $role): static
    {
        return $this->superAdmin()->afterMaking(fn (User $user) => $user->platform_role = $role);
    }

    public function forHotel(Hotel $hotel): static
    {
        return $this->state(fn () => ['hotel_id' => $hotel->id]);
    }

    /** A hotel user with one of the default roles (hotel_admin, branch_manager, …). */
    public function withRole(Hotel $hotel, string $roleKey): static
    {
        return $this->forHotel($hotel)->state(fn () => [
            'role_id' => app(DefaultRoles::class)->ensureFor($hotel)[$roleKey]->id,
        ]);
    }

    public function mustChangePassword(): static
    {
        return $this->state(fn () => ['must_change_password' => true]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['status' => 'disabled']);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
