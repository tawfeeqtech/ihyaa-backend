<?php

namespace Database\Factories;

use App\Enums\UserRole;
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
            'role' => fake()->randomElement([UserRole::IDEA_OWNER, UserRole::INVESTOR]),
            'is_active' => true,
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

    public function ideaOwner(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::IDEA_OWNER,
            'university' => fake()->company(),
            'major' => fake()->jobTitle(),
        ]);
    }

    public function investor(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::INVESTOR,
            'investment_focus' => fake()->randomElement(['Tech', 'Fintech', 'Health', 'Edtech']),
            'investment_range' => ['min' => 50000, 'max' => 500000],
            'preferred_sectors' => ['التقنية المالية', 'الذكاء الاصطناعي'],
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::ADMIN,
        ]);
    }

    /** مستخدم OAuth جديد لم يختر الدور بعد (SRS-F01-07) */
    public function oauthWithoutRole(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => null,
            'provider' => 'google',
            'provider_id' => fake()->numerify('##########'),
            'email_verified_at' => now(),
        ]);
    }
}
