<?php

namespace Database\Factories;

use App\Models\User;
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
            // Not a platform identity unless a state opts in: NULL is the
            // marker for every normal business user (see `User::platform_role`).
            'platform_role' => null,
        ];
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

    /**
     * The single Platform Owner identity.
     *
     * The owner never belongs to a business, so the state pins
     * `business_id` to NULL alongside `platform_role = owner` — the exact
     * invariant the model enforces on every save. Purely opt-in: normal
     * application seeding never creates a Platform Owner.
     */
    public function platformOwner(): static
    {
        return $this->state(fn (array $attributes) => [
            'business_id' => null,
            'platform_role' => User::PLATFORM_ROLE_OWNER,
        ]);
    }
}
