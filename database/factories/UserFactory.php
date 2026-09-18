<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @class UserFactory
 *
 * @package Database\Factories
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * definition
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'google_id' => (string) fake()->unique()->randomNumber(9, true),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'google_token' => 'test-access-token',
            'google_refresh_token' => 'test-refresh-token',
            'google_token_expires_at' => now()->addHour(),
            'remember_token' => Str::random(10),
        ];
    }
}
